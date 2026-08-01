<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Turns coordinates into something a human can read, using a free service.
 *
 * The operator asked for no paid keys, so this uses OpenStreetMap's Nominatim.
 * That choice constrains the design far more than a paid API would, and the
 * constraints are the interesting part:
 *
 *   COORDINATES ARE THE RECORD, NOT THE ADDRESS. What gets stored on a visit or a
 *   location point is latitude and longitude. An address is derived for display
 *   and cached. Freezing a resolved name into the record would make a free
 *   service's guess indistinguishable from where the agent actually stood - and
 *   Nominatim in rural India will sometimes name the wrong village, or nothing at
 *   all. A wrong village in a recovery record is worse than no village.
 *
 *   ONE REQUEST PER SECOND, AND NO BULK REVERSE-GEOCODING. That is Nominatim's
 *   usage policy, and it is not advisory - ignoring it gets the server's IP
 *   blocked, at which point the feature is gone for everybody. So lookups are
 *   rate-limited here, cached aggressively, and never issued in a loop over a
 *   result set. A page that wants addresses for a whole day's trail asks for the
 *   cached ones and leaves the rest as coordinates.
 *
 *   A PAGE RENDER NEVER WAITS ON IT. Every entry point either reads the cache or
 *   is called from a cron. There is no code path where a slow or dead third party
 *   makes the panel hang, because the panel's job is recovery work and the address
 *   is a nicety.
 *
 *   FAILURE IS REMEMBERED. A coordinate the provider cannot name is recorded as a
 *   failure with a timestamp. Without that, every page view retries it forever,
 *   which is exactly the pattern that earns a block.
 *
 * Turning this off in Settings leaves the system fully functional and showing
 * coordinates. That is the intended fallback, not a degraded mode.
 */
final class GeocodingService
{
    /**
     * Cache key precision. Four decimal places is about 11 metres at this
     * latitude - finer than the accuracy of the phones these agents carry, and
     * coarse enough that a day of standing outside one house collapses to a single
     * lookup instead of a few hundred.
     */
    private const GRID_DECIMALS = 4;

    /** Nominatim's stated limit is one request per second. Left deliberately literal. */
    private const MIN_INTERVAL_MICROSECONDS = 1_100_000;

    /** A failed lookup is not retried for this long. */
    private const RETRY_FAILED_AFTER_HOURS = 168;

    /** Give up on a coordinate the provider repeatedly cannot name. */
    private const MAX_ATTEMPTS = 3;

    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    private const TIMEOUT_SECONDS = 8;

    /** Wall-clock microtime of the last outbound request in this process. */
    private static ?float $lastRequestAt = null;

    // =======================================================================
    // Reading - safe to call from a page render
    // =======================================================================

    /**
     * The cached address for a coordinate, or null.
     *
     * Deliberately does NOT call the provider. Views use this: an address appears
     * once something has resolved it, and until then the coordinate is shown. A
     * view that could trigger a network call would turn one slow third party into
     * a slow panel, and a list of fifty rows into fifty sequential requests.
     */
    public static function cached(?float $latitude, ?float $longitude): ?string
    {
        if (!self::usable($latitude, $longitude)) {
            return null;
        }

        $row = Database::instance()->first(
            'SELECT `address` FROM `geocode_cache` WHERE `grid_key` = ? LIMIT 1',
            [self::gridKey((float) $latitude, (float) $longitude)],
        );

        $address = $row['address'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * Cached addresses for many coordinates in one query, keyed by grid key.
     *
     * The reason this exists is the alternative: a loop calling cached() once per
     * row, which is how a trail view becomes a hundred queries.
     *
     * @param  list<array{0:float|null,1:float|null}>  $points
     * @return array<string,string>
     */
    public static function cachedMany(array $points): array
    {
        $keys = [];
        foreach ($points as $point) {
            $latitude = $point[0] ?? null;
            $longitude = $point[1] ?? null;
            if (self::usable($latitude, $longitude)) {
                $keys[self::gridKey((float) $latitude, (float) $longitude)] = true;
            }
        }

        if ($keys === []) {
            return [];
        }

        $keys = array_keys($keys);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $rows = Database::instance()->all(
            'SELECT `grid_key`, `address` FROM `geocode_cache`'
            . ' WHERE `grid_key` IN (' . $placeholders . ') AND `address` IS NOT NULL',
            $keys,
        );

        $resolved = [];
        foreach ($rows as $row) {
            $resolved[(string) $row['grid_key']] = (string) $row['address'];
        }

        return $resolved;
    }

    /**
     * The cache key for a coordinate, so a caller can match cachedMany() output
     * to its own rows without re-deriving the rounding rule.
     */
    public static function keyFor(float $latitude, float $longitude): string
    {
        return self::gridKey($latitude, $longitude);
    }

    /**
     * A coordinate formatted for display when no address is known.
     *
     * Six decimal places, because that is roughly a tenth of a metre and pasting
     * it into a map should land where the agent stood.
     */
    public static function formatCoordinates(?float $latitude, ?float $longitude): ?string
    {
        if (!self::usable($latitude, $longitude)) {
            return null;
        }

        return sprintf('%.6f, %.6f', (float) $latitude, (float) $longitude);
    }

    // =======================================================================
    // Resolving - cron only
    // =======================================================================

    /**
     * Whether outbound lookups are permitted at all.
     *
     * Two gates, and the contact address is not bureaucracy: Nominatim asks
     * callers to identify themselves, and sending a flood of anonymous requests
     * from a shared host is how the whole IP range ends up blocked. If the
     * operator has not supplied one, this stays off and the system shows
     * coordinates. Silently calling anonymously would be borrowing goodwill
     * against somebody else's account.
     */
    public static function enabled(): bool
    {
        return Settings::bool('geocode_enabled')
            && self::contactEmail() !== null;
    }

    /**
     * Why lookups are off, for a cron to print. Null when they are on.
     */
    public static function disabledReason(): ?string
    {
        if (!Settings::bool('geocode_enabled')) {
            return 'geocode_enabled is off in Settings';
        }

        if (self::contactEmail() === null) {
            return 'geocode_contact_email is not set in Settings - OpenStreetMap asks who is calling';
        }

        return null;
    }

    /**
     * Resolves one coordinate, honouring the cache and the rate limit.
     *
     * Returns the address, or null if it is not resolvable right now - which is a
     * normal outcome, not an error. Callers must treat null as "show the
     * coordinate", never as a reason to retry in a tight loop.
     */
    public static function resolve(float $latitude, float $longitude): ?string
    {
        if (!self::usable($latitude, $longitude)) {
            return null;
        }

        $key = self::gridKey($latitude, $longitude);
        $db = Database::instance();

        $existing = $db->first('SELECT * FROM `geocode_cache` WHERE `grid_key` = ? LIMIT 1', [$key]);

        if ($existing !== null && ($existing['address'] ?? null) !== null) {
            return (string) $existing['address'];
        }

        if ($existing !== null && !self::worthRetrying($existing)) {
            return null;
        }

        if (!self::enabled()) {
            return null;
        }

        $parsed = self::request($latitude, $longitude);

        if ($parsed === null) {
            self::recordFailure($key, $latitude, $longitude, $existing);

            return null;
        }

        self::store($key, $latitude, $longitude, $parsed, $existing);

        return $parsed['address'];
    }

    /**
     * Resolves up to $limit not-yet-named coordinates drawn from stored data.
     *
     * This is the only bulk path, it lives behind a cron, and $limit exists so a
     * nightly job cannot decide on its own to make ten thousand requests to a
     * service that asked for one per second. At the default it walks a backlog
     * down slowly, which is the correct speed for a nicety.
     *
     * @return array{queued:int, resolved:int, failed:int, skipped:string|null}
     */
    public static function backfill(int $limit = 200): array
    {
        $reason = self::disabledReason();
        if ($reason !== null) {
            return ['queued' => 0, 'resolved' => 0, 'failed' => 0, 'skipped' => $reason];
        }

        $pending = self::pendingCoordinates($limit);
        $resolved = 0;
        $failed = 0;

        foreach ($pending as $point) {
            $address = self::resolve((float) $point['latitude'], (float) $point['longitude']);
            if ($address === null) {
                $failed++;
            } else {
                $resolved++;
            }
        }

        return [
            'queued' => count($pending),
            'resolved' => $resolved,
            'failed' => $failed,
            'skipped' => null,
        ];
    }

    /**
     * Coordinates that appear in real records and have no cache row yet.
     *
     * Visit coordinates come first. A visit report is a document somebody may have
     * to defend years later, so naming its location is worth more than naming a
     * point on a trail that will be purged in ninety days.
     *
     * @return list<array{latitude:string, longitude:string}>
     */
    private static function pendingCoordinates(int $limit): array
    {
        $limit = max(1, min($limit, 1000));

        return Database::instance()->all(
            'SELECT `latitude`, `longitude` FROM ('
            . '  SELECT `gps_latitude` AS `latitude`, `gps_longitude` AS `longitude`, 1 AS `priority`'
            . '    FROM `visit_reports`'
            . '   WHERE `gps_latitude` IS NOT NULL AND `gps_longitude` IS NOT NULL'
            . '  UNION ALL'
            . '  SELECT `latitude`, `longitude`, 2 AS `priority`'
            . '    FROM `bc_location_logs`'
            . ') AS `points`'
            . ' LEFT JOIN `geocode_cache` `g`'
            . '   ON `g`.`grid_key` = CONCAT(FORMAT(`points`.`latitude`, ' . self::GRID_DECIMALS . '),'
            . '                              \',\','
            . '                              FORMAT(`points`.`longitude`, ' . self::GRID_DECIMALS . '))'
            . ' WHERE `g`.`id` IS NULL'
            . ' GROUP BY `latitude`, `longitude`, `priority`'
            . ' ORDER BY `priority` ASC'
            . ' LIMIT ' . $limit,
        );
    }

    // =======================================================================
    // Internals
    // =======================================================================

    /**
     * (0,0) is in the Gulf of Guinea. It is what a device reports when it has no
     * fix, never where a recovery agent is, and it must not become a cached
     * address that looks like a real place.
     */
    private static function usable(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
            return false;
        }

        return abs($latitude) <= 90.0 && abs($longitude) <= 180.0;
    }

    private static function gridKey(float $latitude, float $longitude): string
    {
        return sprintf(
            '%.' . self::GRID_DECIMALS . 'F,%.' . self::GRID_DECIMALS . 'F',
            $latitude,
            $longitude,
        );
    }

    /**
     * A failure is retried after a week, a few times, then left alone. Some
     * coordinates genuinely have no name in OpenStreetMap and never will; asking
     * again every night is rude to a service given away for free.
     *
     * @param  array<string,mixed>  $row
     */
    private static function worthRetrying(array $row): bool
    {
        if ((int) ($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return false;
        }

        $failedAt = $row['failed_at'] ?? null;
        if (!is_string($failedAt) || $failedAt === '') {
            return true;
        }

        $age = time() - (int) strtotime($failedAt);

        return $age >= self::RETRY_FAILED_AFTER_HOURS * 3600;
    }

    /**
     * Sleeps as long as needed to stay under one request per second.
     *
     * Enforced here rather than trusted to callers, because a rate limit that
     * depends on every caller remembering it is not a rate limit.
     */
    private static function throttle(): void
    {
        if (self::$lastRequestAt !== null) {
            $elapsed = (microtime(true) - self::$lastRequestAt) * 1_000_000;
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                usleep((int) (self::MIN_INTERVAL_MICROSECONDS - $elapsed));
            }
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * @return array{address:string, village:?string, district:?string, state:?string, postcode:?string}|null
     */
    private static function request(float $latitude, float $longitude): ?array
    {
        self::throttle();

        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'jsonv2',
            'lat' => sprintf('%.6F', $latitude),
            'lon' => sprintf('%.6F', $longitude),
            'zoom' => 16,
            'addressdetails' => 1,
            // Hindi first, English as a fallback: the people reading these are
            // reading the rest of the app in Hindi.
            'accept-language' => 'hi,en',
        ]);

        $body = self::fetch($url);

        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || isset($decoded['error'])) {
            return null;
        }

        return self::parse($decoded);
    }

    private static function fetch(string $url): ?string
    {
        // Nominatim requires a User-Agent that identifies the application and a
        // way to reach whoever runs it. A generic one is grounds for blocking.
        $userAgent = sprintf(
            '%s/%s (+%s)',
            str_replace(' ', '-', Settings::get('app_name', 'D2-Recovery') ?? 'D2-Recovery'),
            Settings::get('app_version', '1.0.0') ?? '1.0.0',
            self::contactEmail() ?? 'unknown',
        );

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return null;
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            return is_string($body) && $status === 200 ? $body : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: " . $userAgent . "\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if (!is_string($body)) {
            return null;
        }

        // $http_response_header is set by the stream wrapper; without checking it,
        // a rate-limit page would be cached as though it were an address.
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status === 200 ? $body : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{address:string, village:?string, district:?string, state:?string, postcode:?string}|null
     */
    private static function parse(array $payload): ?array
    {
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

        $pick = static function (array $keys) use ($address): ?string {
            foreach ($keys as $key) {
                $value = $address[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return mb_substr(trim($value), 0, 150);
                }
            }

            return null;
        };

        // Nominatim's shape varies by what is mapped locally: a hamlet in one
        // district is a "village", in the next it is a "suburb" or only a
        // "county". Taking the first that exists is why this is a list.
        $village = $pick(['village', 'hamlet', 'town', 'suburb', 'neighbourhood', 'city']);
        $district = $pick(['state_district', 'county', 'district']);
        $state = $pick(['state']);
        $postcode = $pick(['postcode']);

        $display = $payload['display_name'] ?? null;
        $display = is_string($display) ? trim($display) : '';

        if ($display === '') {
            $display = implode(', ', array_filter([$village, $district, $state, $postcode]));
        }

        if ($display === '') {
            return null;
        }

        return [
            'address' => mb_substr($display, 0, 400),
            'village' => $village,
            'district' => $district,
            'state' => $state,
            'postcode' => $postcode === null ? null : mb_substr($postcode, 0, 20),
        ];
    }

    /**
     * @param  array{address:string, village:?string, district:?string, state:?string, postcode:?string}  $parsed
     * @param  array<string,mixed>|null  $existing
     */
    private static function store(
        string $key,
        float $latitude,
        float $longitude,
        array $parsed,
        ?array $existing,
    ): void {
        $db = Database::instance();

        $data = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $parsed['address'],
            'village' => $parsed['village'],
            'district' => $parsed['district'],
            'state' => $parsed['state'],
            'postcode' => $parsed['postcode'],
            'provider' => 'nominatim',
            'failed_at' => null,
        ];

        if ($existing !== null) {
            $db->update('geocode_cache', $data, ['grid_key' => $key]);

            return;
        }

        $db->insert('geocode_cache', $data + ['grid_key' => $key, 'attempts' => 1]);
    }

    /**
     * @param  array<string,mixed>|null  $existing
     */
    private static function recordFailure(
        string $key,
        float $latitude,
        float $longitude,
        ?array $existing,
    ): void {
        $db = Database::instance();
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $db->update(
                'geocode_cache',
                ['failed_at' => $now, 'attempts' => (int) ($existing['attempts'] ?? 0) + 1],
                ['grid_key' => $key],
            );

            return;
        }

        $db->insert('geocode_cache', [
            'grid_key' => $key,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => null,
            'provider' => 'nominatim',
            'failed_at' => $now,
            'attempts' => 1,
        ]);
    }

    private static function contactEmail(): ?string
    {
        $email = trim((string) (Settings::get('geocode_contact_email', '') ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
