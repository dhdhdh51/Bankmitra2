<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Field-level encryption for PII (mobile, Aadhaar).
 *
 * STORAGE FORMAT
 *   encrypt() returns base64("v1" . iv[12] . tag[16] . ciphertext).
 *   The base64 wrapper means the value is plain ASCII, so it binds through PDO
 *   as an ordinary string and survives mysqldump / phpMyAdmin round-trips
 *   without any binary-escaping surprises, while the destination column stays
 *   VARBINARY.
 *
 * SEARCH
 *   searchHash() is a keyed HMAC-SHA256 over the *normalised* value. It gives
 *   deterministic exact-match lookups (WHERE mobile_hash = ?) without ever
 *   decrypting a column or exposing the plaintext to the query log.
 *   It is deliberately NOT reversible and NOT usable for partial matches.
 */
final class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const VERSION    = 'v1';
    private const IV_LENGTH  = 12;
    private const TAG_LENGTH = 16;

    private static ?string $dataKey = null;
    private static ?string $pepper  = null;

    /**
     * Drops the cached derived keys so the next call re-reads the configuration.
     *
     * Both keys are derived once and kept, since deriving them per call would hash
     * on every single encrypt. That cache also means a config reload is invisible
     * to this class - fine in a request, wrong for a test that has to prove a
     * blank key is rejected, and wrong for the CLI tools that load one config
     * after another.
     */
    public static function reset(): void
    {
        self::$dataKey = null;
        self::$pepper = null;
    }

    // -----------------------------------------------------------------------
    // Encryption
    // -----------------------------------------------------------------------

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::dataKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode(self::VERSION . $iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return null;
        }

        $versionLength = strlen(self::VERSION);
        $minimum = $versionLength + self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($raw) <= $minimum) {
            return null;
        }

        if (substr($raw, 0, $versionLength) !== self::VERSION) {
            return null; // unknown format / key rotation artefact
        }

        $iv         = substr($raw, $versionLength, self::IV_LENGTH);
        $tag        = substr($raw, $versionLength + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, $minimum);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::dataKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    // -----------------------------------------------------------------------
    // Deterministic search hash
    // -----------------------------------------------------------------------

    public static function searchHash(?string $value): ?string
    {
        $normalised = self::normalise($value);
        if ($normalised === null) {
            return null;
        }
        return hash_hmac('sha256', $normalised, self::pepper());
    }

    /**
     * Digits-only, trimmed. Mobile numbers are reduced to the last 10 digits so
     * "+91 98765 43210", "09876543210" and "9876543210" all hash identically.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 10 && strlen($digits) <= 13) {
            $digits = substr($digits, -10);
        }
        return $digits;
    }

    // -----------------------------------------------------------------------
    // Masking for list views (avoids decrypting whole result pages)
    // -----------------------------------------------------------------------

    public static function maskMobile(?string $value): ?string
    {
        $digits = self::normalise($value);
        if ($digits === null) {
            return null;
        }
        if (strlen($digits) <= 4) {
            return str_repeat('X', strlen($digits));
        }
        return str_repeat('X', strlen($digits) - 4) . substr($digits, -4);
    }

    public static function maskAadhaar(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) <= 4) {
            return str_repeat('X', strlen($digits));
        }
        return 'XXXX XXXX ' . substr($digits, -4);
    }

    // -----------------------------------------------------------------------
    // Generic helpers
    // -----------------------------------------------------------------------

    /** URL-safe base64 without padding (used by the JWT encoder). */
    public static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Numeric OTP of the given length, uniformly distributed. */
    public static function numericOtp(int $length = 6): string
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= (string) random_int(0, 9);
        }
        return $otp;
    }

    // -----------------------------------------------------------------------

    private static function dataKey(): string
    {
        if (self::$dataKey === null) {
            self::$dataKey = self::deriveKey((string) Config::get('data_key', ''), 'data_key');
        }
        return self::$dataKey;
    }

    private static function pepper(): string
    {
        if (self::$pepper === null) {
            self::$pepper = self::deriveKey((string) Config::get('hash_pepper', ''), 'hash_pepper');
        }
        return self::$pepper;
    }

    /**
     * Accepts a 64-char hex key (preferred) or any sufficiently long string,
     * and always yields exactly 32 raw bytes.
     */
    private static function deriveKey(string $configured, string $label): string
    {
        if ($configured === '') {
            throw new \RuntimeException(
                "Missing '{$label}' in config/config.php. Generate one with: "
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (strlen($configured) === 64 && ctype_xdigit($configured)) {
            $raw = hex2bin($configured);
            if ($raw !== false) {
                return $raw;
            }
        }

        if (strlen($configured) < 16) {
            throw new \RuntimeException("Configured '{$label}' is too short; use 64 hex characters.");
        }

        return hash('sha256', $configured, true);
    }
}
