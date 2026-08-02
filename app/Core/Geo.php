<?php

declare(strict_types=1);

namespace App\Core;

/**
 * How a recorded position is put into words.
 *
 * This exists because the wording was previously private to VisitController::pdf(),
 * which meant the panel could not reuse it and simply showed nothing: every
 * photograph on screen was a bare thumbnail with a type label, while the printed
 * version of the same photograph carried coordinates, accuracy and whether it came
 * from the camera. Two renderings of one fact, and the screen was the one people
 * actually look at before approving a report.
 *
 * One rule runs through all of it: a missing coordinate is never silently missing.
 * "The agent declined location recording", "there was no fix indoors" and "this was
 * picked out of the gallery so it was never going to have one" are three different
 * statements, and a caption that collapsed them into a blank space would let the
 * weakest be read as the strongest.
 */
final class Geo
{
    /** Below this many metres a fix is precise enough to place someone at a door. */
    public const DOORSTEP_ACCURACY_M = 50;

    /**
     * A coordinate pair, at the precision the accuracy actually justifies.
     *
     * Six decimal places is roughly 0.11 m at the equator. Printing more would
     * suggest a precision no consumer GPS has.
     */
    public static function coordinates(mixed $latitude, mixed $longitude): string
    {
        return sprintf('%.6F, %.6F', (float) $latitude, (float) $longitude);
    }

    /** "+/-12 m", or an empty string when the device did not report accuracy. */
    public static function accuracy(mixed $metres): string
    {
        return $metres === null || $metres === '' ? '' : sprintf('+/-%d m', (int) $metres);
    }

    /**
     * True when the fix is tight enough to say somebody stood at a particular house.
     *
     * A 2 km fix is a cell-tower triangulation. It is not evidence of a doorstep and
     * the panel marks it so, because "26.912400, 75.787300" reads identically whether
     * it is accurate to 8 metres or to a district.
     */
    public static function isPrecise(mixed $accuracyMetres): bool
    {
        return $accuracyMetres !== null && $accuracyMetres !== ''
            && (int) $accuracyMetres > 0 && (int) $accuracyMetres <= self::DOORSTEP_ACCURACY_M;
    }

    /**
     * The one-line caption for anything that carries a position.
     *
     * @param string $absent  What to say when there is no coordinate and no more
     *                        specific explanation applies.
     */
    public static function caption(
        mixed $latitude,
        mixed $longitude,
        mixed $accuracyMetres = null,
        ?string $when = null,
        string $source = '',
        string $absent = 'No location recorded.'
    ): string {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            $reason = match ($source) {
                'gallery'     => 'Chosen from the gallery - no location recorded.',
                'camera'      => 'Camera photograph, no location fix.',
                'denied'      => 'Location recording was declined.',
                'unavailable' => 'No location fix was available.',
                default       => $absent,
            };

            return $when === null || $when === '' ? $reason : $reason . ' ' . $when;
        }

        $parts = self::coordinates($latitude, $longitude);
        $accuracy = self::accuracy($accuracyMetres);
        if ($accuracy !== '') {
            $parts .= ' (' . $accuracy . ')';
        }

        return $when === null || $when === '' ? $parts : $parts . ' - ' . $when;
    }

    /**
     * The caption under a field photograph.
     *
     * @param array<string,mixed> $photo A row from `photos`.
     */
    public static function photo(array $photo): string
    {
        return self::caption(
            $photo['gps_latitude'] ?? null,
            $photo['gps_longitude'] ?? null,
            $photo['gps_accuracy_m'] ?? null,
            ($photo['captured_at'] ?? null) !== null ? fmt_datetime((string) $photo['captured_at']) : null,
            (string) ($photo['capture_source'] ?? 'unknown')
        );
    }

    /**
     * Where the approver was when they approved.
     *
     * @param array<string,mixed> $report A row from `visit_reports`.
     */
    public static function approval(array $report): string
    {
        if ((string) ($report['approval_gps_source'] ?? '') !== 'device'
            || ($report['approval_gps_latitude'] ?? null) === null) {
            return (string) ($report['approval_gps_source'] ?? '') === 'denied'
                ? 'Location declined by the approver'
                : 'No location fix at approval';
        }

        return self::caption(
            $report['approval_gps_latitude'],
            $report['approval_gps_longitude'],
            $report['approval_gps_accuracy_m'] ?? null
        );
    }

    /**
     * Where the visit itself was recorded.
     *
     * @param array<string,mixed> $report A row from `visit_reports`.
     */
    public static function visit(array $report): string
    {
        $source = (string) ($report['gps_source'] ?? 'unavailable');

        if (($report['gps_latitude'] ?? null) === null || $source !== 'device') {
            return $source === 'denied'
                ? 'The agent declined location recording for this report.'
                : 'No location fix was available for this visit.';
        }

        return self::caption(
            $report['gps_latitude'],
            $report['gps_longitude'],
            $report['gps_accuracy_m'] ?? null,
            ($report['gps_captured_at'] ?? null) !== null
                ? fmt_datetime((string) $report['gps_captured_at'])
                : null
        );
    }

    /** True when this row has a usable pair of coordinates. */
    public static function has(array $row, string $latitudeKey = 'gps_latitude', string $longitudeKey = 'gps_longitude'): bool
    {
        return ($row[$latitudeKey] ?? null) !== null && ($row[$longitudeKey] ?? null) !== null;
    }

    /**
     * A map link for a recorded position.
     *
     * Opened by a supervisor checking whether a photograph was taken anywhere near
     * the village it claims. OpenStreetMap, not Google Maps: the same open-source
     * choice already made for the location trail (Leaflet) and for reverse geocoding
     * (Nominatim), so a click-through link is not the one place in the panel that
     * quietly depends on a Google account. Deliberately a plain link with no API key
     * and no embedded map on this page: nothing about a borrower's location is sent
     * anywhere until a human decides to click, and no page in the panel loads a
     * remote script that would leak it automatically.
     *
     * `mlat`/`mlon` drop a marker at the exact point rather than just centring the
     * view, and `#map=<zoom>/lat/lon` is what actually sets the zoom - OSM ignores a
     * bare `zoom` query parameter.
     */
    public static function mapUrl(mixed $latitude, mixed $longitude): string
    {
        $lat = sprintf('%.6F', (float) $latitude);
        $lng = sprintf('%.6F', (float) $longitude);

        return sprintf(
            'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=17/%s/%s',
            $lat,
            $lng,
            $lat,
            $lng
        );
    }

    /**
     * How far apart two fixes are, in metres, or null if either is missing.
     *
     * Haversine on a sphere. Good to about 0.3% - far better than the accuracy of
     * the fixes being compared, which is the only precision that matters here.
     */
    public static function distanceMetres(mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): ?int
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null
            || $lat1 === '' || $lng1 === '' || $lat2 === '' || $lng2 === '') {
            return null;
        }

        $earthRadius = 6371000.0;
        $phi1 = deg2rad((float) $lat1);
        $phi2 = deg2rad((float) $lat2);
        $deltaPhi = $phi2 - $phi1;
        $deltaLambda = deg2rad((float) $lng2 - (float) $lng1);

        $a = sin($deltaPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
    }
}
