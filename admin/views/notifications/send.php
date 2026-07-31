<?php
/**
 * @var list<array<string,mixed>>  $branches
 * @var array<string,string>       $roles
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

use App\Core\Notifier;
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/notifications')) ?>" class="text-muted">Notifications</a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted">Send</span>
        </nav>
        <h1>Send broadcast notification</h1>
        <p>Delivered to the in-app notification list, plus push where Firebase is configured</p>
    </div>
</div>

<?php if (!Notifier::pushConfigured()): ?>
    <div class="alert alert-info">
        <?= icon('info') ?>
        <div>
            Firebase is not configured, so this will be delivered as an in-app notification only.
            Recipients will see it next time they open the app or the panel.
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <form method="post" action="<?= e(url('/notifications/send')) ?>" novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <div class="lrms-card">
                <div class="lrms-card-body">
                    <div class="mb-3">
                        <label class="form-label" for="n-title">Title <span class="req">*</span></label>
                        <input type="text" class="form-control<?= has_error($errors, 'title') ?>"
                               id="n-title" name="title" value="<?= old($old, 'title') ?>"
                               maxlength="180" required autofocus
                               placeholder="e.g. Recovery drive this Saturday">
                        <?= field_error($errors, 'title') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="n-body">Message <span class="req">*</span></label>
                        <textarea class="form-control<?= has_error($errors, 'body') ?>" id="n-body" name="body"
                                  rows="4" maxlength="1000" required><?= old($old, 'body') ?></textarea>
                        <?= field_error($errors, 'body') ?>
                    </div>

                    <div class="row g-3">
                        <?php if (count($branches) > 1): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="n-branch">Branch</label>
                                <select class="form-select" id="n-branch" name="branch_id">
                                    <option value="">All branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= e((string) $branch['id']) ?>"
                                            <?= (string) ($old['branch_id'] ?? '') === (string) $branch['id'] ? 'selected' : '' ?>>
                                            <?= e($branch['name']) ?> (<?= e($branch['branch_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="n-role">Recipients</label>
                            <select class="form-select" id="n-role" name="role_slug">
                                <?php foreach ($roles as $slug => $label): ?>
                                    <option value="<?= e($slug) ?>"
                                        <?= (string) ($old['role_slug'] ?? '') === $slug ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only active accounts receive notifications.</div>
                        </div>
                    </div>
                </div>

                <div class="lrms-card-foot d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('send') ?> Send notification
                    </button>
                    <a href="<?= e(url('/notifications')) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
