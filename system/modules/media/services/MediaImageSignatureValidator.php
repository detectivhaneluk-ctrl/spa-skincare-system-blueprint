<?php

declare(strict_types=1);

namespace Modules\Media\Services;

/**
 * Extension allowlist + finfo MIME + magic-byte verification (do not trust client Content-Type).
 * Prefer {@see self::validateFromStream()} for callers that already hold a readable stream.
 */
final class MediaImageSignatureValidator
{
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    private const ALLOWED_FINFO = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    /**
     * PHP upload temp path convenience (opens stream and delegates to {@see validateFromStream()}).
     *
     * @return array{extension:string,mime:string}
     */
    public function validate(string $tmpPath, string $clientOriginalName): array
    {
        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read upload for validation.');
        }
        try {
            return $this->validateFromStream($fh, $clientOriginalName);
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param resource $stream Readable binary stream (e.g. {@code fopen(..., 'rb')}); consumed from current offset.
     *
     * @return array{extension:string,mime:string}
     */
    public function validateFromStream($stream, string $clientOriginalName): array
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Readable stream resource required.');
        }
        $ext = strtolower(pathinfo($clientOriginalName, PATHINFO_EXTENSION));
        if ($ext === 'svg' || strcasecmp($ext, 'svg') === 0) {
            throw new \InvalidArgumentException('SVG uploads are not allowed.');
        }
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported file extension.');
        }

        $chunk = stream_get_contents($stream, 8192);
        if ($chunk === false) {
            $chunk = '';
        }
        $head = substr($chunk, 0, 64);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $chunk !== '' ? (string) $finfo->buffer($chunk) : '';
        if ($mime === '' || !in_array($mime, self::ALLOWED_FINFO, true)) {
            throw new \InvalidArgumentException('File content is not an allowed image type.');
        }

        if (!$this->magicMatches($head, $ext, $mime)) {
            throw new \InvalidArgumentException('File signature does not match an allowed image format.');
        }

        return ['extension' => $ext === 'jpeg' ? 'jpg' : $ext, 'mime' => $mime];
    }

    private function magicMatches(string $head, string $ext, string $mime): bool
    {
        if ($head === '') {
            return false;
        }

        $jpeg = str_starts_with($head, "\xFF\xD8\xFF");
        $png = str_starts_with($head, "\x89PNG\r\n\x1a\n");
        $webp = strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';

        $avifLike = false;
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brands = strtolower(bin2hex(substr($head, 8, 24)));
            $avifLike = str_contains($brands, '61766966') // avif
                || str_contains($brands, '61766973') // avis
                || str_contains($brands, '6d696631') // mif1
                || str_contains($brands, '6d736631'); // msf1
        }

        return match (true) {
            $mime === 'image/jpeg' || $ext === 'jpg' || $ext === 'jpeg' => $jpeg,
            $mime === 'image/png' || $ext === 'png' => $png,
            $mime === 'image/webp' || $ext === 'webp' => $webp,
            $mime === 'image/avif' || $ext === 'avif' => $avifLike,
            default => false,
        };
    }
}
