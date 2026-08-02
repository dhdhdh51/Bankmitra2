<?php
/**
 * An agent's recorded day, drawn on an OpenStreetMap.
 *
 * The map is Leaflet against OSM's own tiles: no API key, no account, nothing that stops
 * working when somebody's card expires. Both files are pinned with an SRI hash and checked
 * by tools/verify-cdn-integrity.php, like Bootstrap - a wrong hash on a map library is a
 * blank grey box with nothing in the console that names the cause.
 *
 * The points are handed to the browser as JSON in a data attribute rather than inlined into
 * a <script> block: this page renders coordinates for a named person, and a CSP that
 * forbids inline script is a thing this project should be able to turn on later without
 * rewriting the screen.
 *
 * @var list<array<string,mixed>>  $agents
 * @var array<string,mixed>|null   $agent
 * @var int|null                   $agentId
 * @var string                     $date
 * @var list<array<string,mixed>>  $points
 * @var list<array<string,mixed>>  $visits
 * @var array<string,mixed>        $summary
 * @var int|null                   $ownAgentId
 * @var int                        $retentionDays
 */

use App\Core\Geo;

$mapData = [
    'points' => array_map(static fn (array $p): array => [
        'lat'      => (float) $p['latitude'],
        'lng'      => (float) $p['longitude'],
        'accuracy' => $p['accuracy_m'] === null ? null : (int) $p['accuracy_m'],
        'at'       => fmt_datetime((string) $p['logged_at']),
        'on_duty'  => (int) ($p['on_duty'] ?? 1) === 1,
    ], $points),
    'visits' => array_map(static fn (array $v): array => [
        'lat'     => (float) $v['gps_latitude'],
        'lng'     => (float) $v['gps_longitude'],
        'label'   => (string) $v['loan_account_number'],
        'who'     => (string) $v['customer_name'],
        'at'      => $v['visit_time'] === null ? '' : substr((string) $v['visit_time'], 0, 5),
        'url'     => url('/visits/' . (int) $v['id']),
    ], $visits),
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H"
      crossorigin="anonymous">

<div class="lrms-page-head">
    <div>
        <h1>Location trail</h1>
        <p>
            Where an agent's phone reported being, and where their visit reports were filed
            &mdash; points are deleted automatically after <?= e((string) $retentionDays) ?> days
        </p>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/tracking')) ?>">
            <div class="lrms-filters">
                <?php if ($ownAgentId === null): ?>
                    <div>
                        <label class="form-label" for="t-agent">Agent</label>
                        <select class="form-select" id="t-agent" name="agent_id" data-auto-submit>
                            <?php if ($agents === []): ?>
                                <option value="">No agents in this branch</option>
                            <?php endif; ?>
                            <?php foreach ($agents as $one): ?>
                                <option value="<?= e((string) $one['id']) ?>"
                                    <?= (int) $one['id'] === (int) $agentId ? 'selected' : '' ?>>
                                    <?= e((string) $one['name']) ?> (<?= e((string) $one['employee_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div>
                        <label class="form-label">Agent</label>
                        <p class="form-control-plaintext mb-0"><?= e((string) ($agent['name'] ?? '')) ?></p>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="form-label" for="t-date">Date</label>
                    <input type="date" class="form-control" id="t-date" name="date"
                           value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" data-auto-submit>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Show</button>
                    <a href="<?= e(url('/tracking')) ?>" class="btn btn-outline-secondary">Today</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($ownAgentId !== null): ?>
    <div class="alert alert-info">
        <?= icon('info') ?>
        <div>
            This is your own trail. It is recorded while you are signed in to the app with
            location permission granted, and it is deleted after
            <?= e((string) $retentionDays) ?> days.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="lrms-card">
            <div class="lrms-card-head">
                <h2><?= icon('map-pin') ?> <?= e((string) ($agent['name'] ?? 'No agent selected')) ?></h2>
                <span class="text-muted" style="font-size:.75rem"><?= e(fmt_date($date)) ?></span>
            </div>

            <?php if ($points === [] && $visits === []): ?>
                <div class="lrms-card-body">
                    <p class="text-muted mb-0" style="font-size:.875rem">
                        Nothing was recorded for this agent on this date.
                        <?php if ($ownAgentId === null): ?>
                            That means the app was not open with location permission granted, not that
                            the agent did no work &mdash; visit reports are the record of work.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php
                /*
                 * A fixed height, not a viewport unit: this card sits in a column that
                 * already scrolls, and a map that resizes with the window fights the page
                 * on a phone - which is where a branch manager actually opens this.
                 */
                ?>
                <div id="trailMap" style="height:460px;border-radius:0 0 var(--lrms-radius) var(--lrms-radius)"
                     data-trail="<?= e(json_encode($mapData, JSON_THROW_ON_ERROR)) ?>"></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="lrms-card mb-3">
            <div class="lrms-card-head"><h2>The day</h2></div>
            <div class="lrms-card-body">
                <dl class="lrms-dl">
                    <div>
                        <dt>Points recorded</dt>
                        <dd><?= e((string) $summary['points']) ?></dd>
                    </div>
                    <div>
                        <dt>Distance covered</dt>
                        <dd><?= e((string) $summary['kilometres']) ?> km</dd>
                    </div>
                    <div>
                        <dt>First point</dt>
                        <dd><?= $summary['first_at'] === null ? '&mdash;' : e(fmt_datetime((string) $summary['first_at'])) ?></dd>
                    </div>
                    <div>
                        <dt>Last point</dt>
                        <dd><?= $summary['last_at'] === null ? '&mdash;' : e(fmt_datetime((string) $summary['last_at'])) ?></dd>
                    </div>
                    <div>
                        <dt>Span</dt>
                        <dd><?= $summary['on_duty_span'] === null ? '&mdash;' : e((string) $summary['on_duty_span']) . ' min' ?></dd>
                    </div>
                    <div>
                        <dt>Visits filed with a position</dt>
                        <dd><?= e((string) count($visits)) ?></dd>
                    </div>
                </dl>

                <?php
                /*
                 * Said out loud rather than folded into the distance. A dropped leg is a bad
                 * fix, and a total that silently absorbed it would be a number somebody
                 * could be judged on without knowing it was partly invented.
                 */
                ?>
                <?php if ((int) $summary['dropped_legs'] > 0): ?>
                    <p class="text-muted mb-0 mt-2" style="font-size:.75rem">
                        <?= e((string) $summary['dropped_legs']) ?> implausible jump(s) were left out of
                        the distance rather than counted as travel.
                    </p>
                <?php endif; ?>

                <p class="text-muted mb-0 mt-2" style="font-size:.75rem">
                    Distance is the sum of the legs between recorded points, so it under-reports
                    whenever the phone had no signal. It is not a timesheet and no pay or target
                    is derived from it.
                </p>
            </div>
        </div>

        <?php if ($visits !== []): ?>
            <div class="lrms-card">
                <div class="lrms-card-head"><h2>Reports filed that day</h2></div>
                <div class="lrms-table-wrap">
                    <table class="lrms-table">
                        <thead><tr><th>Time</th><th>Account</th><th>Borrower</th></tr></thead>
                        <tbody>
                            <?php foreach ($visits as $visit): ?>
                                <tr>
                                    <td class="nowrap" style="font-size:.8125rem">
                                        <?= e(substr((string) ($visit['visit_time'] ?? ''), 0, 5)) ?>
                                    </td>
                                    <td>
                                        <a class="font-mono" style="font-size:.75rem"
                                           href="<?= e(url('/visits/' . (int) $visit['id'])) ?>">
                                            <?= e((string) $visit['loan_account_number']) ?>
                                        </a>
                                    </td>
                                    <td style="font-size:.8125rem"><?= e((string) $visit['customer_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH"
        crossorigin="anonymous"></script>
<script src="<?= e(asset('js/trail.js')) ?>"></script>
