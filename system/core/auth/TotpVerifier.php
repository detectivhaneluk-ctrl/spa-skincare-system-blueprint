<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * RFC 6238 TOTP (time-based one-time password), Google Authenticator–compatible defaults:
 * HMAC-SHA1, 30-second step, 6 digits.
 */
final class TotpVerifier
{
    private const STEP = 30;
    private const DIGITS = 6;

    /**
     * @param non-empty-string $secretBase32 Base32 secret (padding optional)
     */
    public static function verify(string $secretBase32, string $userCode, int $allowedDriftSteps = 1): bool
    {
        $secret = self::base32Decode($secretBase32);
        if ($secret === '') {
            return false;
        }
        $code = preg_replace('/\s+/', '', $userCode) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $expectedInt = (int) $code;
        $timeSlice = (int) floor(time() / self::STEP);
        for ($i = -$allowedDriftSteps; $i <= $allowedDriftSteps; $i++) {
            if (self::codeForSlice($secret, $timeSlice + $i) === $expectedInt) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-string $secretBinary
     */
    private static function codeForSlice(string $secretBinary, int $timeSlice): int
    {
        $binCounter = pack('N2', ($timeSlice >> 32) & 0xffffffff, $timeSlice & 0xffffffff);
        $hash = hash_hmac('sha1', $binCounter, $secretBinary, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $truncated = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % 10 ** self::DIGITS;

        return $truncated;
    }

    /**
     * @return non-empty-string decoded binary
     */
    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $map = 'ABCDEFGHIJKLMNOPQRSTUVW234567';
        $buffer = 0;
        $bitsLeft = 0;
        $out = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos($map, $b32[$i]);
            if ($v === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $v;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $out;
    }
}
