<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Pdf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Uploader;
use App\Models\Timeline;
use App\Services\TrackingService;
use App\Models\Branch;
use App\Models\CustomField;
use App\Models\LoanAccount;
use App\Models\User;
use App\Models\VisitReport;

/**
 * Submitted field visit reports: viewing, printing, approval and correction.
 *
 * This used to be read-only, on the grounds that reports are append-only. The
 * append-only rule still holds and is worth stating precisely, because it now needs
 * to coexist with an approve-and-correct workflow:
 *
 *   Nothing is ever deleted, and nothing changes without leaving the previous value
 *   behind. An approval adds to the row. A correction updates the row AND writes what
 *   it changed to visit_report_revisions with the before and after, so the submitted
 *   original is always reconstructible and the printed report says how many times it
 *   was corrected.
 *
 * Refusing corrections outright was the alternative, and it does not work: a
 * misheard name or a transposed digit still has to be fixed, and forbidding it here
 * just moves the fix into a phone call where nothing is recorded at all.
 */
final class VisitController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'visits.view');

        $scoped = Auth::scopedBranchId();

        $filters = [
            'branch_id' => $this->branchFilter($request),
            'agent_id'  => $this->agentFilter($request),
            'date_from' => $request->str('date_from'),
            'date_to'   => $request->str('date_to'),
            'village'   => $request->str('village'),
            'loan_type' => $request->str('loan_type'),
            'search'    => $request->str('search'),
        ];

        $visits = VisitReport::paginate($filters, $request->page(), $this->perPage($request));

        $this->view($request, 'visits/index', [
            'title'     => 'Visit Reports',
            'visits'    => $visits,
            'filters'   => $filters,
            'branches'  => Branch::options($scoped),
            'agents'    => User::agents($scoped ?? ($filters['branch_id'] ?? null)),
            'villages'  => LoanAccount::villages($scoped),
            'loanTypes' => LoanAccount::loanTypes($scoped),
        ]);
    }

    public function show(Request $request): void
    {
        $this->guard($request, 'visits.view');

        $report = $this->load($request);

        $this->view($request, 'visits/show', [
            'title'      => 'Visit report',
            'report'     => $report,
            // Null unless this report carried that section, so the view can skip
            // the whole card rather than print a heading over nothing.
            'ots'        => VisitReport::otsDetails((int) $report['id']),
            'ckcc'       => VisitReport::ckccDetails((int) $report['id']),
            'photos'     => VisitReport::photos((int) $report['id']),
            'documents'  => VisitReport::documents((int) $report['id']),
            'signatures' => VisitReport::signatures((int) $report['id']),
            'revisions'  => VisitReport::revisions((int) $report['id']),
        ]);
    }

    /**
     * Approve or reject a submitted report.
     *
     * The approver's photograph, signature and position are captured at the moment
     * they act, not read off their profile. A signature on file proves who they are;
     * a photograph and a coordinate taken now are the only things that say they
     * actually looked at this report where and when they claim.
     *
     * Position comes from the browser and is allowed to be absent. Refusing to record
     * an approval because a laptop has no GPS would push approvals off the system
     * entirely, so "no fix" and "declined" are recorded as what they are.
     */
    public function approve(Request $request): void
    {
        $this->guard($request, 'visits.approve');

        $report = $this->load($request);
        $id = (int) $report['id'];

        if (!$request->isPost()) {
            $this->view($request, 'visits/approve', [
                'title'  => 'Approve visit report',
                'report' => $report,
            ]);
        }

        $decision = $request->str('decision') === 'reject' ? 'rejected' : 'approved';

        // Rejecting without saying why leaves the agent nothing to act on.
        $remarks = $request->nullableStr('approval_remarks');
        if ($decision === 'rejected' && ($remarks === null || trim($remarks) === '')) {
            $this->backWithErrors(
                '/visits/' . $id . '/approve',
                ['approval_remarks' => ['Say why the report is being rejected - the agent has to know what to fix.']],
                $request->all()
            );
        }

        try {
            // A new decision replaces its own evidence. Passing "remove" whenever no
            // file was supplied is deliberate: keeping the previous photograph would
            // present an image taken at an earlier decision as evidence of this one,
            // and leaving it behind unreferenced would litter the uploads directory.
            $photoPath = $this->optionalImage(
                'approval_photo',
                'approvals',
                $report['approval_photo_path'] ?? null,
                !Uploader::hasUpload('approval_photo')
            );

            // The signature needs more care. The stored path may be one BORROWED from
            // the approver's user record by the fallback below, and deleting that
            // would destroy their profile signature - which appears on every report
            // they have ever approved. Only a file that was uploaded here is ours to
            // remove.
            $previousSignature = $report['approval_signature_path'] ?? null;
            $signatureIsOurs = is_string($previousSignature)
                && str_starts_with($previousSignature, 'approvals/');

            $signaturePath = $this->optionalImage(
                'approval_signature',
                'approvals',
                $signatureIsOurs ? $previousSignature : null,
                $signatureIsOurs && !Uploader::hasUpload('approval_signature')
            );
        } catch (\Throwable $e) {
            $this->backWithErrors(
                '/visits/' . $id . '/approve',
                ['approval_photo' => [$e->getMessage()]],
                $request->all(),
                'The image could not be accepted.'
            );
        }

        // Falls back to the signature already on the approver's record. Somebody who
        // signed a sheet once should not have to re-upload it for every report.
        $approver = Auth::user();
        if ($signaturePath === null && ($approver['signature_path'] ?? null) !== null) {
            $signaturePath = (string) $approver['signature_path'];
        }

        $position = $this->submittedPosition($request);

        VisitReport::recordApproval($id, [
            'approval_status'          => $decision,
            'approved_by'              => Auth::id(),
            'approver_name'            => (string) ($approver['name'] ?? ''),
            'approved_at'              => date('Y-m-d H:i:s'),
            'approval_remarks'         => $remarks,
            'approval_photo_path'      => $photoPath,
            'approval_signature_path'  => $signaturePath,
            'approval_gps_latitude'    => $position['latitude'],
            'approval_gps_longitude'   => $position['longitude'],
            'approval_gps_accuracy_m'  => $position['accuracy'],
            'approval_gps_source'      => $position['source'],
        ]);

        Timeline::record(
            (int) $report['loan_account_id'],
            $decision === 'approved' ? 'visit_approved' : 'visit_rejected',
            $decision === 'approved' ? 'Visit report approved' : 'Visit report rejected',
            $remarks,
            Auth::id(),
            (string) ($approver['name'] ?? ''),
            $id,
            null,
            ['position' => $position['source']]
        );

        Logger::audit(
            'update',
            'visit_report',
            $id,
            ['approval_status' => (string) ($report['approval_status'] ?? 'pending')],
            ['approval_status' => $decision],
            sprintf('Visit report #%d %s', $id, $decision)
        );

        $this->back(
            '/visits/' . $id,
            $decision === 'approved' ? 'success' : 'warning',
            sprintf('Report #%d %s.', $id, $decision)
        );
    }

    /**
     * Correct a submitted report.
     *
     * The row is updated and the previous value of every changed field is kept, so
     * nothing the agent filed is lost and the printed report says how many times it
     * has been corrected. See VisitReport::applyRevision().
     */
    public function revise(Request $request): void
    {
        $this->guard($request, 'visits.revise');

        $report = $this->load($request);
        $id = (int) $report['id'];

        if (!$request->isPost()) {
            $this->view($request, 'visits/revise', [
                'title'     => 'Correct visit report',
                'report'    => $report,
                'revisions' => VisitReport::revisions($id),
            ]);
        }

        $reason = $request->nullableStr('reason');
        if ($reason === null || trim($reason) === '') {
            $this->backWithErrors(
                '/visits/' . $id . '/revise',
                ['reason' => ['Say why this correction is being made. It is recorded with the change.']],
                $request->all()
            );
        }

        $proposed = [];
        foreach (array_keys(VisitReport::CORRECTABLE) as $column) {
            if ($request->has($column)) {
                $proposed[$column] = $request->nullableStr($column);
            }
        }

        $user = Auth::user();
        $revisionNo = VisitReport::applyRevision(
            $id,
            $proposed,
            Auth::id(),
            (string) ($user['name'] ?? ''),
            $reason,
            $request->ip()
        );

        if ($revisionNo === null) {
            $this->back('/visits/' . $id, 'info', 'Nothing was changed, so no revision was recorded.');
        }

        Timeline::record(
            (int) $report['loan_account_id'],
            'visit_revised',
            sprintf('Visit report corrected (revision %d)', $revisionNo),
            $reason,
            Auth::id(),
            (string) ($user['name'] ?? ''),
            $id,
            null,
            ['revision' => $revisionNo]
        );

        Logger::audit(
            'update',
            'visit_report',
            $id,
            null,
            ['revision' => $revisionNo, 'reason' => $reason],
            sprintf('Corrected visit report #%d (revision %d)', $id, $revisionNo)
        );

        $this->back('/visits/' . $id, 'success', sprintf(
            'Correction saved as revision %d. The original values are retained.',
            $revisionNo
        ));
    }

    /**
     * The position the browser reported, if it did.
     *
     * The three outcomes are kept distinct because they mean different things to
     * whoever reads the approval later: a coordinate, a refusal, or a device that
     * could not tell. Collapsing them would make a laptop indistinguishable from
     * somebody who declined.
     *
     * @return array{latitude:float|null,longitude:float|null,accuracy:int|null,source:string}
     */
    private function submittedPosition(Request $request): array
    {
        $blank = ['latitude' => null, 'longitude' => null, 'accuracy' => null];

        if ($request->str('gps_source') === 'denied') {
            return $blank + ['source' => 'denied'];
        }

        $latitude = $request->nullableFloat('gps_latitude');
        $longitude = $request->nullableFloat('gps_longitude');

        if ($latitude === null || $longitude === null
            || !TrackingService::plausible($latitude, $longitude)) {
            return $blank + ['source' => 'unavailable'];
        }

        $accuracy = $request->nullableInt('gps_accuracy_m');

        return [
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'accuracy'  => $accuracy === null ? null : max(0, $accuracy),
            'source'    => 'device',
        ];
    }

    /**
     * Renders the Digital BC Field Visit Report as a PDF, using the snapshot
     * stored on the report rather than current customer data.
     */
    public function pdf(Request $request): void
    {
        $this->guard($request, 'visits.view');

        $report = $this->load($request);

        $pdf = new Pdf(
            'Digital BC Field Visit Report',
            sprintf(
                '%s · %s · %s',
                (string) $report['loan_account_number'],
                (string) $report['customer_name'],
                fmt_date((string) $report['visit_date'])
            ),
            false,
            'D2 Recovery confidential - field verification record'
        );

        $pdf->heading('General');
        $pdf->keyValueBlock([
            'Visit Date' => fmt_date((string) $report['visit_date']),
            'Visit Time' => fmt_time((string) $report['visit_time']),
            'BC Code'    => $report['bc_code'],
            'Branch'     => $report['branch_name'] ?: $report['branch_display_name'],
            'Agent Name' => $report['agent_name'],
            'Village'    => $report['village'],
        ], 3);

        $pdf->heading('Borrower Details');
        $pdf->keyValueBlock([
            'Customer Name'        => $report['customer_name'],
            'Father/Husband Name'  => $report['father_husband_name'],
            'Mobile'               => $report['mobile_masked'],
            'Aadhaar'              => $report['aadhaar_masked'],
            'Address'              => $report['address'],
        ], 2);

        $pdf->heading('Loan Details');
        $pdf->keyValueBlock([
            'Loan Account Number' => $report['loan_account_number'],
            'Loan Type'           => $report['loan_type'],
            'Outstanding Amount'  => money($report['outstanding_amount']),
            'Overdue Amount'      => money($report['overdue_amount']),
            'NPA Date'            => $report['npa_date'] === null ? 'Not classified' : fmt_date((string) $report['npa_date']),
            'Current Status'      => ucfirst((string) $report['current_status']),
        ], 3);

        $pdf->heading('Customer Contact');
        $contact = VisitReport::tickedLabels($report, 'contact');
        $pdf->paragraph($contact === [] ? 'None recorded.' : implode(', ', $contact), 9.0, '#1c2128');
        if ((int) $report['family_member_met'] === 1) {
            $pdf->keyValueBlock([
                'Family Member Name' => $report['family_member_name'],
                'Relationship'       => $report['family_member_relationship'],
            ], 2);
        }

        $pdf->heading('Physical Verification');
        $pdf->keyValueBlock([
            'Borrower Alive' => (int) $report['borrower_alive'] === 1 ? 'Yes' : 'No',
            'Same Address'   => (int) $report['same_address'] === 1 ? 'Yes' : 'No',
            'Shifted'        => (int) $report['shifted'] === 1 ? 'Yes' : 'No',
            'Occupation'     => occupation_label($report['occupation']),
        ], 4);

        $pdf->heading('Recovery Possibility');
        $recovery = VisitReport::tickedLabels($report, 'recovery');
        $pdf->paragraph($recovery === [] ? 'None recorded.' : implode(', ', $recovery), 9.0, '#1c2128');
        if ((float) ($report['promise_amount'] ?? 0) > 0) {
            $pdf->keyValueBlock([
                'Promise Amount' => money($report['promise_amount']),
                'Promise Date'   => $report['promise_date'] === null ? '-' : fmt_date((string) $report['promise_date']),
            ], 2);
        }

        $pdf->heading('Non-Payment Reason');
        $reasons = VisitReport::tickedLabels($report, 'reason');
        if (!empty($report['reason_other_text'])) {
            $reasons[] = 'Other: ' . (string) $report['reason_other_text'];
        }
        $pdf->paragraph($reasons === [] ? 'None recorded.' : implode(', ', $reasons), 9.0, '#1c2128');

        $pdf->heading('Agent Recommendation');
        $recommendations = VisitReport::tickedLabels($report, 'recommendation');
        if (!empty($report['rec_other_text'])) {
            $recommendations[] = 'Other: ' . (string) $report['rec_other_text'];
        }
        $pdf->paragraph($recommendations === [] ? 'None recorded.' : implode(', ', $recommendations), 9.0, '#1c2128');

        $pdf->heading('Remarks');
        $pdf->paragraph(($report['remarks'] ?? '') === '' ? 'No remarks recorded.' : (string) $report['remarks'], 9.0, '#1c2128');

        $signatures = VisitReport::signatures((int) $report['id']);
        $photos = VisitReport::photos((int) $report['id']);
        $documents = VisitReport::documents((int) $report['id']);

        // ---- Where the report was filed ------------------------------------
        $pdf->heading('Location Recorded');
        if ((string) $report['gps_source'] === 'device' && $report['gps_latitude'] !== null) {
            $pdf->keyValueBlock([
                'Coordinates' => sprintf('%.6F, %.6F', (float) $report['gps_latitude'], (float) $report['gps_longitude']),
                'Accuracy'    => $report['gps_accuracy_m'] === null ? 'not reported' : ((int) $report['gps_accuracy_m'] . ' m'),
                'Captured At' => $report['gps_captured_at'] === null ? '-' : fmt_datetime((string) $report['gps_captured_at']),
                'Address'     => $report['gps_address'] ?? 'not resolved',
            ], 2);
        } else {
            // "Refused" and "no signal" are different conversations with a
            // supervisor, so the report says which it was rather than leaving a gap.
            $pdf->paragraph(
                (string) $report['gps_source'] === 'denied'
                    ? 'The agent declined location recording for this report.'
                    : 'No location fix was available when this report was filed.',
                9.0,
                '#1c2128'
            );
        }

        // ---- Field photographs, each with the position it was taken at -----
        if ($photos !== []) {
            $pdf->heading('Field Photographs');

            // Three to a row: any more and a printed photograph is too small to show
            // what it was taken to show.
            foreach (array_chunk($photos, 3) as $chunk) {
                $pdf->imageStrip(array_map(
                    fn (array $photo): array => [
                        'path'    => Uploader::absolutePath((string) $photo['file_path']),
                        'label'   => ucwords(str_replace('_', ' ', (string) $photo['photo_type'])),
                        'caption' => $this->photoCaption($photo),
                    ],
                    $chunk
                ), 104.0);
            }
        }

        // ---- Signatures, and who signed ------------------------------------
        $pdf->heading('Signatures');

        $agent = User::find((int) $report['agent_id']);
        $signatureCells = [];

        $customerSignature = $this->signatureOf($signatures, 'customer');
        if ($customerSignature !== null) {
            $signatureCells[] = [
                'path'    => Uploader::absolutePath((string) $customerSignature['file_path']),
                'label'   => 'Borrower Signature',
                'caption' => trim(((string) ($customerSignature['signed_name'] ?? '')) . "\n") !== ''
                    ? (string) $customerSignature['signed_name']
                    : (string) $report['customer_name'],
            ];
        }

        // The agent's photograph next to their signature. This is the point of
        // holding both on the user record: a report a borrower signed should show who
        // was standing there, and a name in a text field does not.
        if ($agent !== null && ($agent['photo_path'] ?? null) !== null) {
            $signatureCells[] = [
                'path'    => Uploader::absolutePath((string) $agent['photo_path']),
                'label'   => 'BC / DC Agent',
                'caption' => sprintf(
                    "%s\n%s",
                    (string) $report['agent_name'],
                    (string) ($report['bc_code'] ?? $agent['employee_code'] ?? '')
                ),
            ];
        }

        $agentSignature = $this->signatureOf($signatures, 'agent');
        if ($agentSignature !== null) {
            $signatureCells[] = [
                'path'    => Uploader::absolutePath((string) $agentSignature['file_path']),
                'label'   => 'Agent Signature',
                'caption' => (string) ($agentSignature['signed_name'] ?? $report['agent_name']),
            ];
        } elseif ($agent !== null && ($agent['signature_path'] ?? null) !== null) {
            // Falls back to the signature held on the agent's record. An agent who
            // signed a sheet once should not have to redraw it per report - and the
            // same mark on every report is what makes two of them comparable.
            $signatureCells[] = [
                'path'    => Uploader::absolutePath((string) $agent['signature_path']),
                'label'   => 'Agent Signature (on file)',
                'caption' => (string) $report['agent_name'],
            ];
        }

        if ($signatureCells === []) {
            $pdf->paragraph('No signatures were captured for this visit.', 9.0, '#1c2128');
        } else {
            $pdf->imageStrip($signatureCells, 84.0);
        }

        // ---- Approval -------------------------------------------------------
        $pdf->heading('Approval');
        $status = (string) ($report['approval_status'] ?? 'pending');

        if ($status === 'pending') {
            $pdf->paragraph('This report has not yet been reviewed.', 9.0, '#8a5a00');
        } else {
            $pdf->keyValueBlock([
                'Status'      => ucfirst($status),
                'Approved By' => $report['approver_name'] ?? '-',
                'Approved At' => $report['approved_at'] === null ? '-' : fmt_datetime((string) $report['approved_at']),
                'Position'    => $this->approvalPosition($report),
            ], 2);

            if (($report['approval_remarks'] ?? '') !== '') {
                $pdf->paragraph('Remarks: ' . (string) $report['approval_remarks'], 8.6, '#1c2128');
            }

            $approvalCells = [];
            if (($report['approval_photo_path'] ?? null) !== null) {
                $approvalCells[] = [
                    'path'    => Uploader::absolutePath((string) $report['approval_photo_path']),
                    'label'   => 'Approver Photograph',
                    'caption' => $this->approvalPosition($report),
                ];
            }
            if (($report['approval_signature_path'] ?? null) !== null) {
                $approvalCells[] = [
                    'path'    => Uploader::absolutePath((string) $report['approval_signature_path']),
                    'label'   => 'Approver Signature',
                    'caption' => (string) ($report['approver_name'] ?? ''),
                ];
            }
            if ($approvalCells !== []) {
                $pdf->imageStrip($approvalCells, 84.0);
            }
        }

        // ---- Operator-defined fields ----------------------------------------
        // Only those marked "print on the visit report". Off by default, because a
        // field somebody added to track an internal note should not silently start
        // appearing on a document handed to a borrower.
        $extra = [];
        foreach ([
            ['customer', (int) $report['customer_id']],
            ['loan_account', (int) $report['loan_account_id']],
            ['visit_report', (int) $report['id']],
        ] as [$entity, $entityId]) {
            foreach (CustomField::withValues($entity, $entityId) as $definition) {
                if ((int) $definition['show_in_report'] !== 1) {
                    continue;
                }

                $display = CustomField::display($definition);
                $extra[(string) $definition['label']] = $display === '' ? 'Not recorded' : $display;
            }
        }

        if ($extra !== []) {
            $pdf->heading('Additional Details');
            $pdf->keyValueBlock($extra, 2);
        }

        $pdf->heading('Attachments');
        $pdf->keyValueBlock([
            'Photos'    => (string) count($photos),
            'Documents' => (string) count($documents),
        ], 4);

        $pdf->spacer(8);

        // Revision history, if there is any. A report that has been corrected must say
        // so on its face - the alternative is a printed document that looks pristine
        // while differing from what the agent actually submitted.
        $revisions = (int) ($report['revision_count'] ?? 0);

        $pdf->paragraph(sprintf(
            'Report #%d submitted from %s%s on %s. %s',
            (int) $report['id'],
            (string) $report['source'],
            $report['app_version'] === null ? '' : ' v' . (string) $report['app_version'],
            fmt_datetime((string) $report['created_at']),
            $revisions === 0
                ? 'The submitted record has not been modified.'
                : sprintf(
                    'Corrected %d time(s) after submission; every change is retained with its before and after value. Last change %s.',
                    $revisions,
                    $report['updated_at'] === null ? 'unknown' : fmt_datetime((string) $report['updated_at'])
                )
        ), 8.0);

        $this->logExport('Visits', sprintf('Exported visit report #%d to PDF', (int) $report['id']));

        Response::download(
            $pdf->output(),
            sprintf('lrms_visit_%s_%d.pdf', (string) $report['loan_account_number'], (int) $report['id']),
            Pdf::MIME
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function load(Request $request): array
    {
        $id = $request->paramInt('id');

        $report = Auth::can('customers.view_pii')
            ? VisitReport::findWithPii($id)
            : VisitReport::find($id);

        if ($report === null) {
            $this->back('/visits', 'danger', 'That visit report could not be found.');
        }

        Auth::assertBranchAccess((int) $report['branch_id']);

        return $report;
    }

    /** @param list<array<string,mixed>> $signatures */
    /**
     * The geo caption printed under a field photograph.
     *
     * A photograph on a recovery file is only worth something if it says where and
     * when it was taken. A gallery pick says so explicitly rather than being passed
     * off as a doorstep photograph - the app never attaches coordinates to one, and a
     * caption that stayed silent about it would let it be read as though it had.
     *
     * @param array<string,mixed> $photo
     */
    private function photoCaption(array $photo): string
    {
        $source = (string) ($photo['capture_source'] ?? 'unknown');

        $when = ($photo['captured_at'] ?? null) !== null
            ? fmt_datetime((string) $photo['captured_at'])
            : null;

        if ($photo['gps_latitude'] === null || $photo['gps_longitude'] === null) {
            return match ($source) {
                'gallery' => 'Chosen from the gallery - no location recorded.'
                    . ($when === null ? '' : ' ' . $when),
                'camera'  => 'Camera photograph, no location fix.' . ($when === null ? '' : ' ' . $when),
                default   => $when ?? 'No location recorded.',
            };
        }

        return sprintf(
            '%.6F, %.6F%s%s',
            (float) $photo['gps_latitude'],
            (float) $photo['gps_longitude'],
            $photo['gps_accuracy_m'] === null ? '' : sprintf(' (+/-%d m)', (int) $photo['gps_accuracy_m']),
            $when === null ? '' : ' - ' . $when
        );
    }

    /**
     * Where the approver was when they approved.
     *
     * Printed because "I approved it at the branch" and "I approved forty of them
     * from home at midnight" are different claims, and only one of them is
     * verification.
     *
     * @param array<string,mixed> $report
     */
    private function approvalPosition(array $report): string
    {
        if ((string) ($report['approval_gps_source'] ?? '') !== 'device'
            || ($report['approval_gps_latitude'] ?? null) === null) {
            return (string) ($report['approval_gps_source'] ?? '') === 'denied'
                ? 'Location declined by the approver'
                : 'No location fix at approval';
        }

        return sprintf(
            '%.6F, %.6F%s',
            (float) $report['approval_gps_latitude'],
            (float) $report['approval_gps_longitude'],
            ($report['approval_gps_accuracy_m'] ?? null) === null
                ? ''
                : sprintf(' (+/-%d m)', (int) $report['approval_gps_accuracy_m'])
        );
    }

    /**
     * @param  list<array<string,mixed>>  $signatures
     * @return array<string,mixed>|null
     */
    private function signatureOf(array $signatures, string $type): ?array
    {
        foreach ($signatures as $signature) {
            if ((string) $signature['signature_type'] === $type) {
                return $signature;
            }
        }

        return null;
    }

    private function hasSignature(array $signatures, string $type): bool
    {
        foreach ($signatures as $signature) {
            if ((string) $signature['signature_type'] === $type) {
                return true;
            }
        }
        return false;
    }
}
