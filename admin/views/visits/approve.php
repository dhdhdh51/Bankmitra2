<?php
/**
 * Approve or reject a submitted field visit report.
 *
 * The approver's photograph and position are captured now, at the moment they act,
 * rather than read off their profile. A signature on file proves who somebody is; a
 * photograph and a coordinate taken at approval time are the only things that say
 * they actually looked at this report where and when they claim.
 *
 * @var array<string,mixed>        $report
 * @var array<string,mixed>        $old
 * @var array<string,list<string>> $errors
 */

$id = (int) $report['id'];
$action = url('/visits/' . $id . '/approve');
?>

<div class="lrms-page-head">
    <div>
        <nav aria-label="Breadcrumb" class="mb-1" style="font-size:.75rem">
            <a href="<?= e(url('/visits')) ?>" class="text-muted">Visit Reports</a>
            <span class="text-muted mx-1">/</span>
            <a href="<?= e(url('/visits/' . $id)) ?>" class="text-muted">#<?= e((string) $id) ?></a>
            <span class="text-muted mx-1">/</span>
            <span class="text-muted">Approve</span>
        </nav>
        <h1>Approve visit report</h1>
        <p>
            <?= e((string) $report['loan_account_number']) ?>
            &middot; <?= e((string) $report['customer_name']) ?>
            &middot; filed by <?= e((string) $report['agent_name']) ?>
            on <?= e(fmt_date((string) $report['visit_date'])) ?>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-7">
        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data"
              novalidate data-no-double-submit>
            <?= csrf_field() ?>

            <!--
                Filled by the browser's geolocation API. Hidden rather than editable:
                a coordinate somebody can type is not evidence of where they were.
            -->
            <input type="hidden" name="gps_latitude" id="gps_latitude" value="">
            <input type="hidden" name="gps_longitude" id="gps_longitude" value="">
            <input type="hidden" name="gps_accuracy_m" id="gps_accuracy_m" value="">
            <input type="hidden" name="gps_source" id="gps_source" value="unavailable">

            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2>Decision</h2></div>
                <div class="lrms-card-body">
                    <?php if ((string) ($report['approval_status'] ?? 'pending') !== 'pending'): ?>
                        <div class="alert alert-info" style="font-size:.8125rem">
                            <?= icon('info') ?>
                            <div>
                                This report was already
                                <strong><?= e((string) $report['approval_status']) ?></strong>
                                by <?= e((string) ($report['approver_name'] ?? 'someone')) ?>
                                on <?= e(fmt_datetime((string) $report['approved_at'])) ?>.
                                Recording a new decision replaces it, and the change is written to the audit log.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="decision">Decision <span class="req">*</span></label>
                            <?php $decision = (string) ($old['decision'] ?? 'approve'); ?>
                            <select class="form-select" id="decision" name="decision" required>
                                <option value="approve" <?= $decision === 'approve' ? 'selected' : '' ?>>Approve</option>
                                <option value="reject" <?= $decision === 'reject' ? 'selected' : '' ?>>Reject</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="approval_remarks">Remarks</label>
                            <textarea class="form-control<?= has_error($errors, 'approval_remarks') ?>"
                                      id="approval_remarks" name="approval_remarks" rows="3"
                                      maxlength="1000"><?= e((string) ($old['approval_remarks'] ?? '')) ?></textarea>
                            <?= field_error($errors, 'approval_remarks') ?>
                            <div class="form-text">Required when rejecting &mdash; the agent needs to know what to fix.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lrms-card mb-3">
                <div class="lrms-card-head"><h2>Your photograph &amp; signature</h2></div>
                <div class="lrms-card-body">
                    <p class="text-muted mb-3" style="font-size:.8125rem">
                        Both are printed on the report next to your decision. If you leave the
                        signature blank, the one held on your user record is used.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="approval_photo">Photograph</label>
                            <input type="file" class="form-control<?= has_error($errors, 'approval_photo') ?>"
                                   id="approval_photo" name="approval_photo"
                                   accept="image/jpeg,image/png,image/webp" capture="user">
                            <?= field_error($errors, 'approval_photo') ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="approval_signature">Signature</label>
                            <input type="file" class="form-control" id="approval_signature"
                                   name="approval_signature" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">Optional if your profile already has one.</div>
                        </div>
                    </div>

                    <div class="mt-3" style="font-size:.8125rem">
                        <span class="text-muted">Your position:</span>
                        <span id="gps_status" class="text-muted">asking your browser&hellip;</span>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <?= icon('check') ?> Record decision
                </button>
                <a href="<?= e(url('/visits/' . $id)) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
/*
 * Position is requested once, and a refusal is recorded as a refusal.
 *
 * The form stays submittable either way: refusing to accept an approval because a
 * desktop has no GPS would push approvals off the system altogether, which is worse
 * than an approval that honestly says no fix was available.
 */
(function () {
    var status = document.getElementById('gps_status');
    var source = document.getElementById('gps_source');

    if (!navigator.geolocation) {
        status.textContent = 'this browser cannot report a position';
        return;
    }

    navigator.geolocation.getCurrentPosition(function (position) {
        var lat = position.coords.latitude;
        var lng = position.coords.longitude;

        // (0,0) is what some devices report with no fix, and it is a real place in
        // the Gulf of Guinea - recording it would be worse than recording nothing.
        if (Math.abs(lat) < 0.0001 && Math.abs(lng) < 0.0001) {
            status.textContent = 'no usable fix';
            return;
        }

        document.getElementById('gps_latitude').value = lat;
        document.getElementById('gps_longitude').value = lng;
        if (position.coords.accuracy) {
            document.getElementById('gps_accuracy_m').value = Math.round(position.coords.accuracy);
        }
        source.value = 'device';
        status.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6)
            + (position.coords.accuracy ? ' (\u00b1' + Math.round(position.coords.accuracy) + ' m)' : '');
    }, function (error) {
        source.value = error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable';
        status.textContent = error.code === error.PERMISSION_DENIED
            ? 'declined - the report will say so'
            : 'unavailable - the report will say so';
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
})();
</script>
