<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Geo;
use App\Core\Request;
use App\Models\Branch;
use App\Models\User;
use App\Services\TrackingService;

/**
 * An agent's day, on a map.
 *
 * This screen was missing entirely, and its absence made the whole tracking feature
 * dishonest: `bc_location_logs` collected a point every four minutes, the purge cron
 * deleted them after ninety days, and in between **nobody could look at any of it**.
 * `TrackingService::trailFor()` existed, complete with its audit entry, and had no
 * caller anywhere in the codebase. Recording somebody's movements and then never
 * reading them is the worst of both worlds: all of the intrusion, none of the use.
 *
 * OpenStreetMap tiles through Leaflet, which is the same choice already made for
 * reverse geocoding in GeocodingService: no API key, no account, no per-request
 * billing, and nothing that stops working when a card expires. The operator asked for
 * no paid keys and this respects that - Leaflet is BSD-2 and the tiles are free for
 * this volume, with the attribution their usage policy requires printed on the map.
 *
 * Two scoping rules, both enforced here rather than in the view:
 *
 *  - An agent sees themselves and nobody else. Not "defaults to themselves" - the
 *    requested agent id is ignored outright, because a colleague's movements are not
 *    theirs to read.
 *  - A branch manager sees the agents of their own branch. Auth::assertBranchAccess()
 *    is the second gate for a hand-typed id.
 */
final class TrackingController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'tracking.view', allowAgent: true);

        $ownAgentId = Auth::scopedAgentId();
        $scopedBranch = Auth::scopedBranchId();

        $agents = $ownAgentId === null ? User::agents($scopedBranch) : [];

        // An agent is pinned to themselves. A manager gets whoever they asked for, once
        // the branch check has passed, and otherwise the first agent they have.
        $agentId = $ownAgentId;
        if ($agentId === null) {
            $requested = $request->int('agent_id');
            if ($requested > 0) {
                $candidate = User::find($requested);
                if ($candidate !== null && (string) ($candidate['role_slug'] ?? '') === 'agent') {
                    Auth::assertBranchAccess($candidate['branch_id'] === null ? null : (int) $candidate['branch_id']);
                    $agentId = $requested;
                }
            }
            if ($agentId === null && $agents !== []) {
                $agentId = (int) $agents[0]['id'];
            }
        }

        // Today unless asked otherwise, and never a future date: there is nothing to draw
        // and it reads as a page that failed to load.
        $date = trim($request->str('date'));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            $date = date('Y-m-d');
        }

        $agent = $agentId === null ? null : User::find($agentId);
        $points = [];
        $visits = [];

        if ($agentId !== null) {
            // trailFor() writes the audit entry, and it skips it when somebody is reading
            // their own trail. Reading a colleague's movements is an event; reading your
            // own is not.
            $points = TrackingService::trailFor($agentId, $date, (int) Auth::id());
            $visits = $this->visitsOn($agentId, $date);
        }

        $this->view($request, 'tracking/index', [
            'title'         => 'Location trail',
            'agents'        => $agents,
            'agent'         => $agent,
            'agentId'       => $agentId,
            'date'          => $date,
            'points'        => $points,
            'visits'        => $visits,
            'summary'       => self::summarise($points),
            'ownAgentId'    => $ownAgentId,
            'branches'      => Branch::options($scopedBranch),
            'retentionDays' => TrackingService::retentionDays(),
        ]);
    }

    /**
     * The visit reports that agent filed that day, with a position.
     *
     * Drawn alongside the trail because the interesting question is not "where did they
     * go" but "was the report filed where the visit happened". A trail on its own cannot
     * answer that, and two separate screens make it a memory test.
     *
     * @return list<array<string,mixed>>
     */
    private function visitsOn(int $agentId, string $date): array
    {
        return Database::instance()->all(
            'SELECT vr.id, vr.visit_time, vr.gps_latitude, vr.gps_longitude, vr.gps_accuracy_m,
                    vr.gps_source, vr.report_type, la.loan_account_number, c.name AS customer_name
               FROM visit_reports vr
               JOIN loan_accounts la ON la.id = vr.loan_account_id
               JOIN customers c ON c.id = la.customer_id
              WHERE vr.agent_id = ? AND vr.visit_date = ?
                AND vr.gps_latitude IS NOT NULL AND vr.gps_longitude IS NOT NULL
              ORDER BY vr.visit_time ASC, vr.id ASC',
            [$agentId, $date]
        );
    }

    /**
     * What the trail adds up to.
     *
     * Distance is the sum of the legs, not the straight line from first point to last: an
     * agent who walks a village and comes back to the same road covered the village.
     * Points more than 400 m apart are still counted - a four-minute interval on a
     * motorcycle is a real leg - but a jump beyond 20 km is dropped as a bad fix rather
     * than credited as travel, because one wild coordinate would otherwise report a day's
     * ride to the next district.
     *
     * @param  list<array<string,mixed>> $points
     * @return array<string,mixed>
     */
    private static function summarise(array $points): array
    {
        $metres = 0.0;
        $dropped = 0;
        $previous = null;

        foreach ($points as $point) {
            if ($previous !== null) {
                $leg = Geo::distanceMetres(
                    (float) $previous['latitude'],
                    (float) $previous['longitude'],
                    (float) $point['latitude'],
                    (float) $point['longitude']
                );
                if ($leg === null) {
                    $dropped++;
                } elseif ($leg > 20000.0) {
                    $dropped++;
                } else {
                    $metres += $leg;
                }
            }
            $previous = $point;
        }

        $first = $points === [] ? null : (string) $points[0]['logged_at'];
        $last = $points === [] ? null : (string) $points[count($points) - 1]['logged_at'];

        return [
            'points'       => count($points),
            'kilometres'   => round($metres / 1000, 1),
            'dropped_legs' => $dropped,
            'first_at'     => $first,
            'last_at'      => $last,
            'on_duty_span' => $first === null || $last === null
                ? null
                : max(0, (int) round((strtotime($last) - strtotime($first)) / 60)),
        ];
    }
}
