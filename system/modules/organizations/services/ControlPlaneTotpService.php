<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Env;
use Core\Auth\TotpVerifier;
use Modules\Organizations\Repositories\ControlPlaneTotpUserRepository;

/**
 * Encrypt/decrypt TOTP shared secrets and verify user codes for founder control-plane MFA.
 */
final class ControlPlaneTotpService
{
    private const SESSION_MFA_UNTIL = 'control_plane_mfa_valid_until';
    private const NONCE_LEN = 24;

    public function __construct(private ControlPlaneTotpUserRepository $users)
    {
    }

    public static function sessionKeyValidUntil(): string
    {
        return self::SESSION_MFA_UNTIL;
    }

    public function isEnrolled(int $userId): bool
    {
        $row = $this->users->fetchTotpRow($userId);
        if ($row === null) {
            return false;
        }

        return (int) ($row['control_plane_totp_enabled'] ?? 0) === 1
            && !empty($row['control_plane_totp_secret_ciphertext']);
    }

    /**
     * @throws \InvalidArgumentException when APP_KEY missing or ciphertext corrupt
     */
    public function verifyCode(int $userId, string $plainCode): bool
    {
        $row = $this->users->fetchTotpRow($userId);
        if ($row === null || (int) ($row['control_plane_totp_enabled'] ?? 0) !== 1) {
            return false;
        }
        $cipher = $row['control_plane_totp_secret_ciphertext'] ?? null;
        if (!is_string($cipher) || $cipher === '') {
            return false;
        }
        $secret = $this->decryptSecret($cipher);

        return TotpVerifier::verify($secret, $plainCode, 1);
    }

    /**
     * @param non-empty-string $plainBase32Secret
     *
     * @throws \InvalidArgumentException
     */
    public function enrollBase32Secret(int $userId, string $plainBase32Secret): void
    {
        $plainBase32Secret = strtoupper(trim($plainBase32Secret));
        if ($plainBase32Secret === '') {
            throw new \InvalidArgumentException('TOTP secret is empty.');
        }
        $bin = self::rawBase32Decode($plainBase32Secret);
        if ($bin === '') {
            throw new \InvalidArgumentException('TOTP secret is not valid base32.');
        }
        $this->users->setTotpSecretAndEnable($userId, $this->encryptSecret($plainBase32Secret));
    }

    public function markSessionFresh(int $ttlSeconds = 900): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION[self::SESSION_MFA_UNTIL] = time() + max(60, min(3600, $ttlSeconds));
    }

    public function isSessionFresh(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $until = (int) ($_SESSION[self::SESSION_MFA_UNTIL] ?? 0);

        return $until > time();
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function encryptSecret(string $plainBase32): string
    {
        $key = $this->encryptionKey();
        $nonce = random_bytes(self::NONCE_LEN);
        if (function_exists('sodium_crypto_secretbox')) {
            $cipher = sodium_crypto_secretbox($plainBase32, $nonce, $key);
            if ($cipher === false) {
                throw new \InvalidArgumentException('TOTP secret encryption failed.');
            }

            return 'nacl1:' . $nonce . $cipher;
        }
        $ivLen = openssl_cipher_iv_length('aes-256-gcm');
        if ($ivLen === false) {
            throw new \InvalidArgumentException('OpenSSL AES-GCM not available.');
        }
        $iv = random_bytes((int) $ivLen);
        $tag = '';
        $cipher = openssl_encrypt($plainBase32, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (!is_string($cipher) || $cipher === '') {
            throw new \InvalidArgumentException('TOTP secret encryption failed.');
        }

        return 'ossl1:' . $iv . $tag . $cipher;
    }

    private function decryptSecret(string $stored): string
    {
        $key = $this->encryptionKey();
        if (str_starts_with($stored, 'nacl1:')) {
            $raw = substr($stored, 6);
            if (strlen($raw) < self::NONCE_LEN + 16) {
                throw new \InvalidArgumentException('TOTP ciphertext corrupt.');
            }
            $nonce = substr($raw, 0, self::NONCE_LEN);
            $cipher = substr($raw, self::NONCE_LEN);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if (!is_string($plain)) {
                throw new \InvalidArgumentException('TOTP ciphertext decrypt failed.');
            }

            return $plain;
        }
        if (str_starts_with($stored, 'ossl1:')) {
            $raw = substr($stored, 6);
            $ivLen = openssl_cipher_iv_length('aes-256-gcm');
            if ($ivLen === false) {
                throw new \InvalidArgumentException('OpenSSL AES-GCM not available.');
            }
            $ivLen = (int) $ivLen;
            if (strlen($raw) < $ivLen + 16 + 1) {
                throw new \InvalidArgumentException('TOTP ciphertext corrupt.');
            }
            $iv = substr($raw, 0, $ivLen);
            $tag = substr($raw, $ivLen, 16);
            $cipher = substr($raw, $ivLen + 16);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (!is_string($plain)) {
                throw new \InvalidArgumentException('TOTP ciphertext decrypt failed.');
            }

            return $plain;
        }
        throw new \InvalidArgumentException('Unknown TOTP ciphertext format.');
    }

    /**
     * @return non-empty-string 32 bytes
     */
    private function encryptionKey(): string
    {
        $appKey = (string) Env::get('APP_KEY', '');
        if (trim($appKey) === '') {
            throw new \InvalidArgumentException('APP_KEY must be set to use control-plane TOTP storage.');
        }
        $h = hash('sha256', 'control_plane_totp_v1|' . $appKey, true);
        if ($h === false || strlen($h) !== 32) {
            throw new \InvalidArgumentException('APP_KEY derivation failed.');
        }

        return $h;
    }

    /**
     * @return non-empty-string
     */
    private static function rawBase32Decode(string $b32): string
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
