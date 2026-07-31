<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Outbound channels: SMS (OTP), SMTP mail, and Firebase push.
 *
 * All credentials are read from the `settings` table at call time, so changing
 * them in the admin panel takes effect immediately with no redeploy. Every
 * channel degrades gracefully: if it is not configured, the call returns false
 * and the caller falls back (for OTP, to admin-assisted reset).
 */
final class Notifier
{
    // -----------------------------------------------------------------------
    // SMS
    // -----------------------------------------------------------------------

    public static function smsConfigured(): bool
    {
        return Settings::get('sms_api_url') !== null && Settings::get('sms_api_key') !== null;
    }

    /**
     * Sends an SMS through a URL-template gateway, which covers the majority of
     * Indian providers (MSG91, TextLocal, and most custom aggregators) without
     * needing a provider-specific SDK.
     *
     * The URL template supports {mobile} {message} {key} {sender} placeholders.
     */
    public static function sendSms(string $mobile, string $message): bool
    {
        if (!self::smsConfigured()) {
            return false;
        }

        $template = (string) Settings::get('sms_api_url', '');
        $url = strtr($template, [
            '{mobile}'  => rawurlencode(Crypto::normalise($mobile) ?? $mobile),
            '{message}' => rawurlencode($message),
            '{key}'     => rawurlencode((string) Settings::get('sms_api_key', '')),
            '{sender}'  => rawurlencode((string) Settings::get('sms_sender_id', '')),
        ]);

        $response = self::httpGet($url);

        Logger::activity(
            'sms_sent',
            'Notifications',
            sprintf('SMS to %s: %s', Crypto::maskMobile($mobile) ?? 'unknown', $response === null ? 'failed' : 'ok')
        );

        return $response !== null;
    }

    public static function sendOtpSms(string $mobile, string $otp): bool
    {
        $template = (string) Settings::get('sms_otp_template', 'Your LRMS OTP is {otp}. Valid for 10 minutes.');
        return self::sendSms($mobile, strtr($template, ['{otp}' => $otp]));
    }

    // -----------------------------------------------------------------------
    // Email (raw SMTP over streams - no PHPMailer, no Composer)
    // -----------------------------------------------------------------------

    /**
     * Sends a password-reset OTP by email.
     *
     * The code is put in the subject line as well as the body: on a phone the
     * subject is often all that is visible in the notification, which saves
     * opening the mail at all.
     *
     * Plain, unbranded HTML on purpose - heavy markup is what makes a genuine
     * message look like a phishing attempt, and some corporate mail clients
     * strip it anyway.
     */
    public static function sendOtpEmail(string $email, string $otp, int $expiryMinutes): bool
    {
        $appName = (string) Settings::get('app_name', 'LRMS');
        $subject = sprintf('%s password reset code: %s', $appName, $otp);

        $body = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;color:#1c2128">'
            . '<p>Use this code to reset your ' . htmlspecialchars($appName, ENT_QUOTES) . ' password:</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:.18em;margin:18px 0">'
            . htmlspecialchars($otp, ENT_QUOTES)
            . '</p>'
            . '<p>It expires in ' . $expiryMinutes . ' minute' . ($expiryMinutes === 1 ? '' : 's') . '.</p>'
            . '<p style="color:#6b7280;font-size:13px;margin-top:22px">'
            . 'If you did not ask to reset your password, ignore this message - your password has not changed. '
            . 'Never share this code with anyone, including bank staff.'
            . '</p></div>';

        return self::sendMail($email, $subject, $body);
    }

    public static function smtpConfigured(): bool
    {
        return Settings::get('smtp_host') !== null && Settings::get('smtp_from_email') !== null;
    }

    public static function sendMail(string $toEmail, string $subject, string $htmlBody): bool
    {
        if (!self::smtpConfigured()) {
            return false;
        }

        $host = (string) Settings::get('smtp_host', '');
        $port = Settings::int('smtp_port', 587);
        $username = (string) Settings::get('smtp_username', '');
        $password = (string) Settings::get('smtp_password', '');
        $encryption = strtolower((string) Settings::get('smtp_encryption', 'tls'));
        $fromEmail = (string) Settings::get('smtp_from_email', '');
        $fromName = (string) Settings::get('smtp_from_name', 'LRMS');

        $transport = $encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            error_log(sprintf('[LRMS smtp] connect failed: %s (%d)', $errstr, $errno));
            return false;
        }

        try {
            $read = static function () use ($socket): string {
                $data = '';
                while (($line = fgets($socket, 1024)) !== false) {
                    $data .= $line;
                    // Multi-line replies use "250-"; the final one uses "250 ".
                    if (strlen($line) < 4 || $line[3] === ' ') {
                        break;
                    }
                }
                return $data;
            };
            $write = static function (string $command) use ($socket): void {
                fwrite($socket, $command . "\r\n");
            };
            $expect = static function (string $response, string $code): bool {
                return str_starts_with(trim($response), $code);
            };

            if (!$expect($read(), '220')) {
                return false;
            }

            $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $read();

            if ($encryption === 'tls') {
                $write('STARTTLS');
                if (!$expect($read(), '220')) {
                    return false;
                }
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $read();
            }

            if ($username !== '') {
                $write('AUTH LOGIN');
                if (!$expect($read(), '334')) {
                    return false;
                }
                $write(base64_encode($username));
                if (!$expect($read(), '334')) {
                    return false;
                }
                $write(base64_encode($password));
                if (!$expect($read(), '235')) {
                    return false;
                }
            }

            $write('MAIL FROM:<' . $fromEmail . '>');
            if (!$expect($read(), '250')) {
                return false;
            }

            $write('RCPT TO:<' . $toEmail . '>');
            $rcpt = $read();
            if (!$expect($rcpt, '250') && !$expect($rcpt, '251')) {
                return false;
            }

            $write('DATA');
            if (!$expect($read(), '354')) {
                return false;
            }

            $headers = [
                'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
                'To: <' . $toEmail . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];

            $body = chunk_split(base64_encode($htmlBody), 76, "\r\n");

            // Dot-stuffing: a lone "." would otherwise terminate the message.
            $write(implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\r\n.", "\r\n..", $body) . "\r\n.");
            if (!$expect($read(), '250')) {
                return false;
            }

            $write('QUIT');
            return true;
        } catch (\Throwable $e) {
            error_log('[LRMS smtp] ' . $e->getMessage());
            return false;
        } finally {
            @fclose($socket);
        }
    }

    // -----------------------------------------------------------------------
    // Firebase push
    // -----------------------------------------------------------------------

    public static function pushConfigured(): bool
    {
        return Settings::get('firebase_server_key') !== null;
    }

    /**
     * Best-effort FCM legacy-HTTP push to a user's registered devices.
     * In-app notifications are the source of truth; push is an accelerator, so a
     * failure here is logged and swallowed.
     *
     * @param array<string,mixed> $data
     */
    public static function push(int $userId, string $title, string $body, array $data = []): bool
    {
        if (!self::pushConfigured()) {
            return false;
        }

        $tokens = Database::instance()->all(
            'SELECT token FROM device_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [$userId]
        );
        if ($tokens === []) {
            return false;
        }

        $payload = [
            'registration_ids' => array_map(static fn (array $r): string => (string) $r['token'], $tokens),
            'notification'     => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
            'data'     => $data,
            'priority' => 'high',
        ];

        $response = self::httpPostJson(
            'https://fcm.googleapis.com/fcm/send',
            $payload,
            ['Authorization: key=' . (string) Settings::get('firebase_server_key', '')]
        );

        return $response !== null;
    }

    // -----------------------------------------------------------------------
    // Transport helpers (cURL when present, streams otherwise)
    // -----------------------------------------------------------------------

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                error_log('[LRMS http] GET failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
                return null;
            }
            return (string) $body;
        }

        $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string>        $headers
     */
    private static function httpPostJson(string $url, array $payload, array $headers = []): ?string
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $allHeaders = array_merge(['Content-Type: application/json'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $allHeaders,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                error_log('[LRMS http] POST failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
                return null;
            }
            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $allHeaders),
                'content'       => $json,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        // Strip CR/LF to prevent header injection.
        return str_replace(["\r", "\n"], '', $value);
    }
}
