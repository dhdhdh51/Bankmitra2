<?php

/**
 * Names the coordinates already stored on visits and location trails.
 *
 * cPanel -> Cron Jobs, hourly is plenty:
 *   20 * * * * /usr/local/bin/php /home/USER/public_html/cron/geocode-backfill.php
 *
 *   --limit=N    how many coordinates to resolve this run (default 120)
 *   --dry-run    report the backlog, resolve nothing
 *
 * CLI only.
 *
 * This exists so that no page render ever waits on a third party. The panel reads
 * addresses out of `geocode_cache` and shows raw coordinates for anything not yet
 * resolved; this job is what fills the cache, slowly, in the background.
 *
 * "Slowly" is the requirement, not a limitation. The provider is OpenStreetMap's
 * free Nominatim service, which asks for at most one request per second and no
 * bulk reverse-geocoding. So --limit is small by default and the service enforces
 * its own throttle internally: at 120 per run this walks a backlog down at about
 * three thousand a day without ever looking like an abusive client. Raising the
 * limit past a few hundred is how a shared host's IP gets blocked, which would
 * take the feature away from every branch at once.
 *
 * If lookups are switched off in Settings, or no contact email is configured, this
 * job says so and exits cleanly. Coordinates on their own are a perfectly usable
 * record; that is the designed fallback, not a failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\GeocodingService;

$options = getopt('', ['limit::', 'dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$limit = isset($options['limit']) && $options['limit'] !== false
    ? (int) $options['limit']
    : 120;

if ($limit < 1) {
    fwrite(STDERR, "!! --limit must be 1 or more\n");
    exit(1);
}

printf("Geocode backfill%s\n", $dryRun ? '  (dry run)' : '');
echo str_repeat('=', 66), "\n";

$reason = GeocodingService::disabledReason();

if ($reason !== null) {
    printf("  skipped          : %s\n", $reason);
    echo "\n  Coordinates are still recorded and shown; only the address lookup is off.\n";
    echo "\nGEOCODE BACKFILL OK\n";
    exit(0);
}

printf("  limit            : %d\n", $limit);

if ($dryRun) {
    echo "\n  Dry run - nothing was resolved.\n";
    echo "\nGEOCODE BACKFILL OK\n";
    exit(0);
}

$started = microtime(true);
$result = GeocodingService::backfill($limit);
$elapsed = microtime(true) - $started;

printf("  attempted        : %d\n", $result['queued']);
printf("  resolved         : %d\n", $result['resolved']);
printf("  not resolvable   : %d\n", $result['failed']);
printf("  elapsed          : %.1fs\n", $elapsed);

if ($result['queued'] === 0) {
    echo "\n  Nothing pending.\n";
}

echo "\nGEOCODE BACKFILL OK\n";
exit(0);
