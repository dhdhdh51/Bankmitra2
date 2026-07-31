<?php
/**
 * @var \App\Core\Paginator       $logs
 * @var list<string>              $actions
 * @var list<string>              $entityTypes
 * @var list<array<string,mixed>> $users
 * @var array<string,mixed>       $filters
 */

/** Pretty-prints a JSON old/new value blob. */
$renderValues = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '<span class="text-muted">—</span>';
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return '<span class="text-muted">—</span>';
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $display = $value === null ? 'null' : (string) $value;
        if (mb_strlen($display) > 60) {
            $display = mb_substr($display, 0, 60) . '…';
        }
        $parts[] = '<span class="text-muted">' . e($key) . ':</span> ' . e($display);
    }

    return implode('<br>', $parts);
};
?>

<div class="lrms-page-head">
    <div>
        <h1>Audit Logs</h1>
        <p>Entity-level changes with the old and new values</p>
    </div>
</div>

<div class="lrms-card mb-3">
    <div class="lrms-card-body">
        <form method="get" action="<?= e(url('/logs/audit')) ?>">
            <div class="lrms-filters">
                <div>
                    <label class="form-label" for="a-search">Search</label>
                    <input type="search" class="form-control" id="a-search" name="search"
                           value="<?= e($filters['search']) ?>" placeholder="User, entity, summary">
                </div>

                <div>
                    <label class="form-label" for="a-action">Action</label>
                    <select class="form-select" id="a-action" name="action" data-auto-submit>
                        <option value="">All actions</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>>
                                <?= e(ucfirst(str_replace('_', ' ', $action))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="a-entity">Entity type</label>
                    <select class="form-select" id="a-entity" name="entity_type" data-auto-submit>
                        <option value="">All entities</option>
                        <?php foreach ($entityTypes as $entity): ?>
                            <option value="<?= e($entity) ?>" <?= $filters['entity_type'] === $entity ? 'selected' : '' ?>>
                                <?= e(ucfirst(str_replace('_', ' ', $entity))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="a-user">User</label>
                    <select class="form-select" id="a-user" name="user_id" data-auto-submit>
                        <option value="">All users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>"
                                <?= ($filters['user_id'] ?? null) === (int) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name'] ?? ('User #' . $user['id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="a-from">From</label>
                    <input type="date" class="form-control" id="a-from" name="date_from" value="<?= e($filters['date_from']) ?>">
                </div>
                <div>
                    <label class="form-label" for="a-to">To</label>
                    <input type="date" class="form-control" id="a-to" name="date_to" value="<?= e($filters['date_to']) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Filter</button>
                    <a href="<?= e(url('/logs/audit')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lrms-card">
    <?php if ($logs->isEmpty()): ?>
        <?= \App\Core\View::partial('partials/empty', [
            'heading'  => 'No audit entries',
            'message'  => 'Creates, updates, deletes, imports and assignments are recorded here.',
            'iconName' => 'logs',
        ]) ?>
    <?php else: ?>
        <div class="lrms-table-wrap">
            <table class="lrms-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Summary</th>
                        <th>Before</th>
                        <th>After</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs->items as $log): ?>
                        <tr>
                            <td class="nowrap" style="font-size:.75rem">
                                <?= e(fmt_date((string) $log['created_at'], 'd M y')) ?>
                                <div class="text-muted"><?= e(fmt_date((string) $log['created_at'], 'H:i:s')) ?></div>
                            </td>
                            <td style="font-size:.8125rem"><?= nullable($log['user_name']) ?></td>
                            <td>
                                <?php
                                $actionClass = match ((string) $log['action']) {
                                    'create', 'import'      => 'badge-visited',
                                    'delete'                => 'badge-legal',
                                    'assign', 'reassign', 'transfer' => 'badge-promise',
                                    default                 => 'badge-followup',
                                };
                                ?>
                                <span class="lrms-badge <?= $actionClass ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', (string) $log['action']))) ?>
                                </span>
                            </td>
                            <td style="font-size:.8125rem">
                                <?= e(str_replace('_', ' ', (string) $log['entity_type'])) ?>
                                <?php if (!empty($log['entity_id'])): ?>
                                    <span class="text-muted font-mono" style="font-size:.6875rem">
                                        #<?= e($log['entity_id']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.8125rem;max-width:280px"><?= nullable($log['summary']) ?></td>
                            <td style="font-size:.6875rem;max-width:220px"><?= $renderValues($log['old_values']) ?></td>
                            <td style="font-size:.6875rem;max-width:220px"><?= $renderValues($log['new_values']) ?></td>
                            <td class="text-muted font-mono" style="font-size:.6875rem"><?= nullable($log['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="lrms-card-foot">
            <?= \App\Core\View::partial('partials/pagination', ['paginator' => $logs, 'label' => 'audit entries']) ?>
        </div>
    <?php endif; ?>
</div>
