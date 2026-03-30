<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\App\Config;
use Core\Storage\Contracts\StorageProviderInterface;
use Core\Storage\S3\S3SigV4Signer;

/**
 * S3-compatible object storage (SigV4, path-style URLs). Optional driver behind {@see StorageProviderFactory}.
 *
 * Large-object streaming is buffered to php://temp on read — prefer local driver for very large files until ranged GET exists.
 */
final class S3CompatibleObjectStorageProvider implements StorageProviderInterface
{
    private function __construct(
        private readonly string $endpointHost,
        private readonly string $scheme,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly bool $pathStyle,
    ) {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('S3CompatibleObjectStorageProvider requires ext-curl.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $endpoint = trim((string) $config->get('storage.s3.endpoint', ''));
        $bucket = trim((string) $config->get('storage.s3.bucket', ''));
        $access = trim((string) $config->get('storage.s3.access_key', ''));
        $secret = trim((string) $config->get('storage.s3.secret_key', ''));
        $region = trim((string) $config->get('storage.s3.region', 'us-east-1'));
        $pathStyle = filter_var($config->get('storage.s3.path_style', true), FILTER_VALIDATE_BOOLEAN);
        if ($endpoint === '' || $bucket === '' || $access === '' || $secret === '') {
            throw new \RuntimeException(
                'storage.s3.endpoint, storage.s3.bucket, storage.s3.access_key, storage.s3.secret_key are required for s3_compatible driver.'
            );
        }
        $p = parse_url($endpoint);
        if ($p === false || empty($p['host'])) {
            throw new \RuntimeException('storage.s3.endpoint must be a valid URL with host.');
        }
        $scheme = ($p['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $host = (string) $p['host'];
        if (!empty($p['port'])) {
            $host .= ':' . (int) $p['port'];
        }

        return new self($host, $scheme, $bucket, $access, $secret, $region !== '' ? $region : 'us-east-1', $pathStyle);
    }

    public function driverName(): string
    {
        return 's3_compatible';
    }

    public function supportsContentHashing(): bool
    {
        return true;
    }

    public function computeSha256HexForKey(StorageKey $key): string
    {
        $ctx = hash_init('sha256');
        $h = $this->openReadStream($key);
        try {
            $n = hash_update_stream($ctx, $h);
            if ($n === false) {
                throw new \RuntimeException('Failed to hash storage object.');
            }
        } finally {
            fclose($h);
        }
        $hex = hash_final($ctx);
        if (!is_string($hex) || strlen($hex) !== 64) {
            throw new \RuntimeException('Failed to finalize storage hash.');
        }

        return $hex;
    }

    public function supportsPublicFilesystemPath(): bool
    {
        return false;
    }

    public function resolvePublicFilesystemPathIfSupported(StorageKey $key): ?string
    {
        return null;
    }

    public function resolvePublicUrl(StorageKey $key): ?string
    {
        return null;
    }

    /** @return resource */
    public function openReadStream(StorageKey $key)
    {
        $res = $this->request('GET', $this->objectKey($key), null, 'UNSIGNED-PAYLOAD');
        if ($res['code'] !== 200) {
            throw new \RuntimeException('S3 GET failed with HTTP ' . $res['code']);
        }
        $tmp = fopen('php://temp', 'rb+');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to open temp stream.');
        }
        fwrite($tmp, $res['body']);
        rewind($tmp);

        return $tmp;
    }

    public function readStreamToOutput(StorageKey $key): void
    {
        $h = $this->openReadStream($key);
        try {
            fpassthru($h);
        } finally {
            fclose($h);
        }
    }

    public function localFilesystemPathFor(StorageKey $key): string
    {
        throw new \RuntimeException('S3 driver does not expose local filesystem paths.');
    }

    public function fileExists(StorageKey $key): bool
    {
        $res = $this->request('HEAD', $this->objectKey($key), null, 'UNSIGNED-PAYLOAD');

        return $res['code'] === 200;
    }

    public function isDirectory(StorageKey $key): bool
    {
        return false;
    }

    public function isReadableFile(StorageKey $key): bool
    {
        return $this->fileExists($key);
    }

    public function fileSizeOrFail(StorageKey $key): int
    {
        $res = $this->request('HEAD', $this->objectKey($key), null, 'UNSIGNED-PAYLOAD');
        if ($res['code'] !== 200) {
            throw new \RuntimeException('Document not found.');
        }
        $cl = $res['headers']['content-length'] ?? $res['headers']['Content-Length'] ?? null;
        if ($cl === null || !is_numeric($cl)) {
            throw new \RuntimeException('Missing Content-Length on HEAD response.');
        }

        return (int) $cl;
    }

    public function deleteFileIfExists(StorageKey $key): bool
    {
        if (!$this->fileExists($key)) {
            return false;
        }
        $res = $this->request('DELETE', $this->objectKey($key), null, 'UNSIGNED-PAYLOAD');

        return $res['code'] >= 200 && $res['code'] < 300;
    }

    public function ensureParentDirectoryExists(StorageKey $key): void
    {
    }

    public function importLocalFile(string $localSourcePath, StorageKey $destKey, bool $isPhpUploadedFile): void
    {
        if (!is_file($localSourcePath) || !is_readable($localSourcePath)) {
            throw new \RuntimeException('Source file is missing.');
        }
        $hash = hash_file('sha256', $localSourcePath);
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new \RuntimeException('Failed to hash upload payload.');
        }
        $res = $this->request('PUT', $this->objectKey($destKey), $localSourcePath, $hash);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException('S3 PUT failed with HTTP ' . $res['code']);
        }
    }

    public function renameKey(StorageKey $from, StorageKey $to): void
    {
        $this->serverSideCopy($this->objectKey($from), $this->objectKey($to));
        if (!$this->deleteFileIfExists($from)) {
            throw new \RuntimeException('Failed to delete source after S3 copy.');
        }
    }

    public function copyKeyThenDeleteSource(StorageKey $from, StorageKey $to): void
    {
        $this->renameKey($from, $to);
    }

    public function deleteDirectoryTree(StorageKey $directoryKey): array
    {
        return ['S3 driver: deleteDirectoryTree is not implemented; use object lifecycle rules or a dedicated tool.'];
    }

    public function deletePublicDirectoryTreeIfUnderPrefix(StorageKey $directoryKey, StorageKey $prefixKey): array
    {
        return $this->deleteDirectoryTree($directoryKey);
    }

    public function listImmediateChildNames(StorageKey $directoryKey): array
    {
        return [];
    }

    private function objectKey(StorageKey $key): string
    {
        $tail = trim($key->relativePosixPath(), '/');
        if ($tail === '') {
            throw new \InvalidArgumentException('Invalid storage key path.');
        }

        return $key->volume() . '/' . $tail;
    }

    private function signingPath(string $objectKey): string
    {
        $enc = self::pathEncodeSegments($objectKey);
        if ($this->pathStyle) {
            return '/' . $this->bucket . '/' . $enc;
        }

        return '/' . $enc;
    }

    private function requestUrlForSigningPath(string $signingPath): string
    {
        if ($this->pathStyle) {
            return $this->scheme . '://' . $this->endpointHost . $signingPath;
        }

        return $this->scheme . '://' . $this->bucket . '.' . $this->endpointHost . $signingPath;
    }

    private static function pathEncodeSegments(string $key): string
    {
        $trim = trim($key, '/');
        if ($trim === '') {
            return '';
        }
        $parts = explode('/', $trim);

        return implode('/', array_map(static fn (string $p): string => rawurlencode(rawurldecode($p)), $parts));
    }

    /**
     * @return array{code: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $objectKey, ?string $uploadFilePath, string $payloadDescriptor): array
    {
        $signingPath = $this->signingPath($objectKey);
        $url = $this->requestUrlForSigningPath($signingPath);
        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? $this->endpointHost);
        if (!empty($parsed['port'])) {
            $host .= ':' . (int) $parsed['port'];
        }
        $headers = [
            'host' => $host,
        ];
        if ($method === 'PUT' && $uploadFilePath !== null) {
            $headers['x-amz-content-sha256'] = $payloadDescriptor;
        }
        $extra = [];
        if ($method === 'PUT' && $uploadFilePath !== null) {
            $extra['Content-Type'] = 'application/octet-stream';
        }
        foreach ($extra as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
        $signed = S3SigV4Signer::signRequest(
            $method,
            $signingPath,
            '',
            $headers,
            $payloadDescriptor,
            $this->accessKey,
            $this->secretKey,
            $this->region,
            's3'
        );
        $flat = [];
        foreach ($signed as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed.');
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $flat);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($method === 'PUT' && $uploadFilePath !== null) {
            $body = file_get_contents($uploadFilePath);
            if ($body === false) {
                curl_close($ch);
                throw new \RuntimeException('Failed to read upload body.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException('S3 HTTP error: ' . $err);
        }
        $headerSize = strpos($raw, "\r\n\r\n");
        if ($headerSize === false) {
            return ['code' => 0, 'headers' => [], 'body' => ''];
        }
        $head = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize + 4);
        $code = 0;
        $hmap = [];
        foreach (explode("\r\n", $head) as $i => $line) {
            if ($i === 0) {
                if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $line, $m)) {
                    $code = (int) $m[1];
                }
                continue;
            }
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$hk, $hv] = explode(':', $line, 2);
            $hmap[strtolower(trim($hk))] = trim($hv);
        }

        return ['code' => $code, 'headers' => $hmap, 'body' => $body];
    }

    private function serverSideCopy(string $sourceObjectKey, string $destObjectKey): void
    {
        $destSigning = $this->signingPath($destObjectKey);
        $srcEnc = '/' . $this->bucket . '/' . self::pathEncodeSegments($sourceObjectKey);
        $url = $this->requestUrlForSigningPath($destSigning);
        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? $this->endpointHost);
        if (!empty($parsed['port'])) {
            $host .= ':' . (int) $parsed['port'];
        }
        $headers = [
            'host' => $host,
            'x-amz-copy-source' => $srcEnc,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
        ];
        $signed = S3SigV4Signer::signRequest(
            'PUT',
            $destSigning,
            '',
            $headers,
            'UNSIGNED-PAYLOAD',
            $this->accessKey,
            $this->secretKey,
            $this->region,
            's3'
        );
        $flat = [];
        foreach ($signed as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed.');
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $flat);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            throw new \RuntimeException('S3 copy failed.');
        }
        if (!preg_match('#HTTP/\d\.\d\s+(\d+)#', $raw, $m)) {
            throw new \RuntimeException('S3 copy: bad response.');
        }
        $code = (int) $m[1];
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('S3 copy failed with HTTP ' . $code);
        }
    }
}
