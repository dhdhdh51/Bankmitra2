<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Staff location capture, and the obligations that come with it.
 *
 * This system records where its agents are. That reverses the original design,
 * which captured no location at all, and it was an explicit decision by the
 * operator. The point of this class is that the obligations which make such a
 * decision defensible are enforced in code rather than written down somewhere and
 * hoped for:
 *
 *   CONSENT FIRST. An agent cannot be tracked until they have been shown the
 *   written notice and acknowledged it. Every write goes through
 *   assertMayTrack(), which throws otherwise. The notice is versioned, so
 *   changing what is collected forces a fresh acknowledgement instead of
 *   stretching old consent over new collection.
 *
 *   WITHDRAWABLE. An agent can revoke. Collection stops immediately; existing
 *   points are kept for the retention window and then purged like any other.
 *   A revocation is a management conversation, not something to be silently
 *   overridden by the software.
 *
 *   BOUNDED. Points older than the retention window are deleted by
 *   cron/purge-location-logs.php. A permanent record of somebody's movements is a
 *   liability that grows over time and helps nobody.
 *
 *   ON DUTY ONLY. Points are accepted with an on_duty flag and the app stops
 *   sending when the agent goes off duty. Tracking someone's evening is not
 *   field verification.
 *
 *   AUDITED. Reading another person's trail is recorded, because a location
 *   history is among the most sensitive things this database holds.
 */
final class TrackingService
{
    /**
     * Bump this when the notice text changes in any material way. An agent whose
     * acknowledgement is against an older version is treated as not having
     * consented, and the app shows them the notice again.
     */
    public const NOTICE_VERSION = '2026-08-01';

    /** Default retention, overridable in Settings. */
    private const DEFAULT_RETENTION_DAYS = 90;

    /** Points closer together than this are dropped as noise. */
    private const MIN_SECONDS_BETWEEN_POINTS = 60;

    // =======================================================================
    // Consent
    // =======================================================================

    /**
     * The notice an agent must acknowledge, in English and Hindi.
     *
     * Written to be understood by the person it applies to rather than to protect
     * whoever wrote it: what is collected, when, who sees it, how long it is kept,
     * and what they can do about it.
     *
     * @return array{version:string,english:string,hindi:string}
     */
    public static function notice(): array
    {
        $days = self::retentionDays();
        $bank = trim((string) Settings::get('bank_name', ''));
        $org = $bank !== '' ? $bank : 'your employer';

        return [
            'version' => self::NOTICE_VERSION,
            'english' => implode("\n", [
                'LOCATION RECORDING NOTICE',
                '',
                'This app records your location.',
                '',
                'What is recorded:',
                '  - Your position while you are marked on duty in this app.',
                '  - Your position at the moment you take a photo for a visit report.',
                '',
                'When it is NOT recorded:',
                '  - When you are off duty in the app.',
                '  - When the app is closed and you have not started a duty session.',
                '',
                sprintf('Who can see it: your supervisor, %s, and administrators of this', $org),
                'system. Every time somebody opens your location history it is logged.',
                '',
                sprintf('How long it is kept: %d days, then it is deleted automatically.', $days),
                '',
                'Your choices: you can withdraw this consent at any time from Account ->',
                'Location. Recording stops immediately. Withdrawing may affect visit',
                'reports that require a location, so speak to your supervisor first.',
                '',
                'You must acknowledge this notice before the app records any location.',
            ]),
            'hindi' => implode("\n", [
                'लोकेशन रिकॉर्डिंग सूचना',
                '',
                'यह ऐप आपकी लोकेशन रिकॉर्ड करता है।',
                '',
                'क्या रिकॉर्ड होता है:',
                '  - जब आप ऐप में ड्यूटी पर हैं, तब आपकी स्थिति।',
                '  - जब आप विज़िट रिपोर्ट के लिए फोटो लेते हैं, उस समय की स्थिति।',
                '',
                'क्या रिकॉर्ड नहीं होता:',
                '  - जब आप ऐप में ड्यूटी पर नहीं हैं।',
                '  - जब ऐप बंद है और आपने ड्यूटी शुरू नहीं की है।',
                '',
                'कौन देख सकता है: आपके सुपरवाइज़र, ' . $org . ', और इस सिस्टम के',
                'प्रशासक। जब भी कोई आपकी लोकेशन हिस्ट्री खोलता है, उसका रिकॉर्ड रखा जाता है।',
                '',
                sprintf('कितने दिन रखी जाती है: %d दिन, उसके बाद स्वतः मिट जाती है।', $days),
                '',
                'आपका अधिकार: आप कभी भी Account -> Location से यह सहमति वापस ले सकते हैं।',
                'रिकॉर्डिंग तुरंत बंद हो जाएगी। इससे उन विज़िट रिपोर्ट पर असर पड़ सकता है',
                'जिनमें लोकेशन आवश्यक है, इसलिए पहले सुपरवाइज़र से बात करें।',
                '',
                'ऐप कोई भी लोकेशन रिकॉर्ड करने से पहले आपकी स्वीकृति आवश्यक है।',
            ]),
        ];
    }

    public static function retentionDays(): int
    {
        $configured = (int) Settings::get('location_retention_days', (string) self::DEFAULT_RETENTION_DAYS);
        // A retention of zero would mean "keep forever", which is the opposite of
        // what somebody setting it to zero would expect.
        return $configured > 0 ? $configured : self::DEFAULT_RETENTION_DAYS;
    }

    /** Whether this agent has a live acknowledgement of the current notice. */
    public static function hasConsented(int $userId): bool
    {
        return Database::instance()->scalar(
            'SELECT 1 FROM tracking_consents
              WHERE user_id = ? AND notice_version = ? AND withdrawn_at IS NULL
              LIMIT 1',
            [$userId, self::NOTICE_VERSION]
        ) !== null;
    }

    public static function recordConsent(int $userId, ?string $device, ?string $ip): void
    {
        $db = Database::instance();

        // A re-acknowledgement after a withdrawal must clear the withdrawal rather
        // than collide with the unique key and appear to fail.
        $existing = $db->first(
            'SELECT id FROM tracking_consents WHERE user_id = ? AND notice_version = ? LIMIT 1',
            [$userId, self::NOTICE_VERSION]
        );

        if ($existing !== null) {
            $db->update('tracking_consents', [
                'acknowledged_at' => date('Y-m-d H:i:s'),
                'device_info'     => $device === null ? null : mb_substr($device, 0, 255),
                'ip_address'      => $ip,
                'withdrawn_at'    => null,
            ], ['id' => (int) $existing['id']]);
        } else {
            $db->insert('tracking_consents', [
                'user_id'         => $userId,
                'notice_version'  => self::NOTICE_VERSION,
                'acknowledged_at' => date('Y-m-d H:i:s'),
                'device_info'     => $device === null ? null : mb_substr($device, 0, 255),
                'ip_address'      => $ip,
            ]);
        }

        Logger::audit(
            'consent',
            'user',
            $userId,
            null,
            ['notice_version' => self::NOTICE_VERSION],
            'Acknowledged the location recording notice'
        );
    }

    public static function withdrawConsent(int $userId): void
    {
        Database::instance()->query(
            'UPDATE tracking_consents SET withdrawn_at = ?
              WHERE user_id = ? AND withdrawn_at IS NULL',
            [date('Y-m-d H:i:s'), $userId]
        );

        Logger::audit(
            'consent',
            'user',
            $userId,
            null,
            ['notice_version' => self::NOTICE_VERSION],
            'Withdrew consent for location recording'
        );
    }

    /**
     * @throws \RuntimeException when this agent may not be tracked
     */
    public static function assertMayTrack(int $userId): void
    {
        if (!self::hasConsented($userId)) {
            throw new \RuntimeException(
                'Location cannot be recorded until the location notice has been acknowledged.'
            );
        }
    }

    // =======================================================================
    // Points
    // =======================================================================

    /**
     * Stores one location fix.
     *
     * Returns false when the point was deliberately dropped rather than failing:
     * a device that wakes up and posts five fixes a second is a battery and
     * storage problem, not extra precision.
     *
     * @param array{latitude:float,longitude:float,accuracy_m?:int|null,logged_at?:string|null,on_duty?:bool} $point
     */
    public static function record(int $agentId, array $point): bool
    {
        self::assertMayTrack($agentId);

        $latitude = (float) $point['latitude'];
        $longitude = (float) $point['longitude'];

        if (!self::plausible($latitude, $longitude)) {
            throw new \RuntimeException('That does not look like a valid coordinate.');
        }

        $db = Database::instance();
        $loggedAt = self::normaliseTimestamp($point['logged_at'] ?? null);

        // Rate limit per agent, measured on the SERVER clock. A device clock can be
        // wrong or deliberately set, so it is stored but never trusted for control
        // decisions.
        $last = $db->scalar(
            'SELECT received_at FROM bc_location_logs WHERE agent_id = ? ORDER BY id DESC LIMIT 1',
            [$agentId]
        );
        if ($last !== null && (time() - strtotime((string) $last)) < self::MIN_SECONDS_BETWEEN_POINTS) {
            return false;
        }

        $db->insert('bc_location_logs', [
            'agent_id'   => $agentId,
            'latitude'   => round($latitude, 7),
            'longitude'  => round($longitude, 7),
            'accuracy_m' => isset($point['accuracy_m']) && $point['accuracy_m'] !== null
                ? min(65535, max(0, (int) $point['accuracy_m']))
                : null,
            'logged_at'  => $loggedAt,
            'on_duty'    => ($point['on_duty'] ?? true) ? 1 : 0,
        ]);

        return true;
    }

    /**
     * One agent's trail for one day.
     *
     * Reading somebody's movements is audited. The caller passes who is asking so
     * that an agent looking at their own trail is not logged as surveillance.
     *
     * @return list<array<string,mixed>>
     */
    public static function trailFor(int $agentId, string $date, int $viewerId): array
    {
        $rows = Database::instance()->all(
            'SELECT latitude, longitude, accuracy_m, logged_at, received_at, on_duty
               FROM bc_location_logs
              WHERE agent_id = ? AND DATE(logged_at) = ?
           ORDER BY logged_at',
            [$agentId, $date]
        );

        if ($viewerId !== $agentId) {
            Logger::audit(
                'view_location',
                'user',
                $agentId,
                null,
                ['date' => $date, 'points' => count($rows)],
                sprintf('Viewed the location trail for %s on %s', 'agent #' . $agentId, $date)
            );
        }

        return $rows;
    }

    /**
     * Deletes points past the retention window.
     *
     * @return int rows removed
     */
    public static function purge(?int $retentionDays = null): int
    {
        $days = $retentionDays ?? self::retentionDays();
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $statement = Database::instance()->query(
            'DELETE FROM bc_location_logs WHERE logged_at < ?',
            [$cutoff]
        );

        return $statement->rowCount();
    }

    // =======================================================================
    // Helpers
    // =======================================================================

    /**
     * A coordinate that is obviously wrong is rejected rather than stored.
     *
     * (0,0) is in the Gulf of Guinea and is what a failed fix looks like; storing
     * it would put every agent who lost signal on the same spot in the ocean.
     */
    public static function plausible(float $latitude, float $longitude): bool
    {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return false;
        }
        return !(abs($latitude) < 0.0001 && abs($longitude) < 0.0001);
    }

    /**
     * A device timestamp, sanity-checked.
     *
     * A phone with a wrong clock would otherwise file today's movements under next
     * year, where nobody looks and the purge never reaches them.
     */
    private static function normaliseTimestamp(?string $raw): string
    {
        $now = time();
        if ($raw === null || $raw === '') {
            return date('Y-m-d H:i:s', $now);
        }

        $parsed = strtotime($raw);
        if ($parsed === false) {
            return date('Y-m-d H:i:s', $now);
        }

        // Accept a day behind (queued while offline) but never the future.
        if ($parsed > $now + 300 || $parsed < $now - 86400 * 2) {
            return date('Y-m-d H:i:s', $now);
        }

        return date('Y-m-d H:i:s', $parsed);
    }
}
