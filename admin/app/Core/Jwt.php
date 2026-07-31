<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal HS256 JWT encoder/decoder. No Composer, no external library.
 *
 * Signature comparison uses hash_equals (timing safe). "alg" is pinned to HS256
 * on decode, so a token claiming {"alg":"none"} or an asymmetric algorithm is
 * rejected outright rather than trusted.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    /** Clock skew tolerance in seconds. */
    private const LEEWAY = 30;

    /**
     * @param array<string,mixed> $claims
     */
    public static function encode(array $claims, ?int $ttlSeconds = null): string
    {
        $now = time();
        $ttl = $ttlSeconds ?? (int) Settings::get('jwt_ttl_minutes', '120') * 60;

        $payload = array_merge([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
            'iss' => 'lrms',
        ], $claims);

        $header = ['typ' => 'JWT', 'alg' => self::ALGORITHM];

        $segments = [
            Crypto::b64UrlEncode(self::jsonEncode($header)),
            Crypto::b64UrlEncode(self::jsonEncode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $segments[] = Crypto::b64UrlEncode(self::sign($signingInput));

        return implode('.', $segments);
    }

    /**
     * @return array<string,mixed>|null Claims on success, null when the token is
     *                                 malformed, badly signed, or expired.
     */
    public static function decode(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(Crypto::b64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            return null;
        }

        $expected = self::sign($headerB64 . '.' . $payloadB64);
        $provided = Crypto::b64UrlDecode($signatureB64);
        if (!hash_equals($expected, $provided)) {
            return null;
        }

        $claims = json_decode(Crypto::b64UrlDecode($payloadB64), true);
        if (!is_array($claims)) {
            return null;
        }

        $now = time();
        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && $now + self::LEEWAY < (int) $claims['nbf']) {
            return null;
        }
        if (isset($claims['exp']) && is_numeric($claims['exp']) && $now - self::LEEWAY >= (int) $claims['exp']) {
            return null;
        }

        /** @var array<string,mixed> $claims */
        return $claims;
    }

    /** True when the token is structurally valid but past its expiry. */
    public static function isExpired(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        $claims = json_decode(Crypto::b64UrlDecode($parts[1]), true);
        if (!is_array($claims) || !isset($claims['exp']) || !is_numeric($claims['exp'])) {
            return false;
        }
        return time() >= (int) $claims['exp'];
    }

    private static function sign(string $input): string
    {
        $key = (string) Config::get('app_key', '');
        if ($key === '') {
            throw new \RuntimeException(
                "Missing 'app_key' in config/config.php. Generate one with: "
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }
        return hash_hmac('sha256', $input, $key, true);
    }

    /** @param array<string,mixed> $value */
    private static function jsonEncode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
