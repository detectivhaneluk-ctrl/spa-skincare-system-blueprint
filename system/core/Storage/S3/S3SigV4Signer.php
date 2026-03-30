<?php

declare(strict_types=1);

namespace Core\Storage\S3;

/**
 * Minimal AWS Signature Version 4 signer for S3-compatible REST (no SDK).
 */
final class S3SigV4Signer
{
    /**
     * @param array<string, string> $headers lower-case header name => value (host, x-amz-*)
     * @return array<string, string> headers to send including Authorization, X-Amz-Date, x-amz-content-sha256
     */
    public static function signRequest(
        string $method,
        string $canonicalUri,
        string $queryString,
        array $headers,
        string $payloadHashHexOrUnsigned,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service,
    ): array {
        $method = strtoupper($method);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['x-amz-date'] = $amzDate;
        if (!isset($headers['x-amz-content-sha256'])) {
            $headers['x-amz-content-sha256'] = $payloadHashHexOrUnsigned;
        }
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', (string) $v)) . "\n";
            $signedHeadersList[] = $k;
        }
        $signedHeaders = implode(';', $signedHeadersList);
        $uri = $canonicalUri;
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }
        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $headers['x-amz-content-sha256'],
        ]);
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        $signingKey = self::signingKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headers['authorization'] = $auth;

        return $headers;
    }

    private static function signingKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
