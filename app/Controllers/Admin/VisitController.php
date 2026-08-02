<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Geo;
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
            'revisions'  => VisitReport::revisions((int) $report['id']),
        ]);
    }

    /**
     * Approve or reject a submitted report.
     *
     * The approver's photograph and position are captured at the moment they act,
     * not read off their profile: a photograph and a coordinate taken now are the only
     * things that say they actually looked at this report where and when they claim.
     * Their signature goes on the printed page by hand, like every other signature.
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
        } catch (\Throwable $e) {
            $this->backWithErrors(
                '/visits/' . $id . '/approve',
                ['approval_photo' => [$e->getMessage()]],
                $request->all(),
                'The image could not be accepted.'
            );
        }

        $approver = Auth::user();
        $position = $this->submittedPosition($request);

        VisitReport::recordApproval($id, [
            'approval_status'          => $decision,
            'approved_by'              => Auth::id(),
            'approver_name'            => (string) ($approver['name'] ?? ''),
            'approved_at'              => date('Y-m-d H:i:s'),
            'approval_remarks'         => $remarks,
            'approval_photo_path'      => $photoPath,
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

        $photos = VisitReport::photos((int) $report['id']);
        $documents = VisitReport::documents((int) $report['id']);

        // ---- Where the report was filed ------------------------------------
        $pdf->heading('Location Recorded');
        if ((string) $report['gps_source'] === 'device' && $report['gps_latitude'] !== null) {
            $pdf->keyValueBlock([
                'Coordinates' => Geo::coordinates($report['gps_latitude'], $report['gps_longitude']),
                'Accuracy'    => $report['gps_accuracy_m'] === null
                    ? 'not reported'
                    : ((int) $report['gps_accuracy_m'] . ' m'
                        . (Geo::isPrecise($report['gps_accuracy_m']) ? '' : ' - too coarse to place a doorstep')),
                'Captured At' => $report['gps_captured_at'] === null ? '-' : fmt_datetime((string) $report['gps_captured_at']),
                'Address'     => $report['gps_address'] ?? 'not resolved',
            ], 2);
        } else {
            // "Refused" and "no signal" are different conversations with a
            // supervisor, so the report says which it was rather than leaving a gap.
            // Worded by Geo so the panel and the print cannot disagree about it.
            $pdf->paragraph(Geo::visit($report), 9.0, '#1c2128');
        }

        // ---- Field photographs, each with the position it was taken at -----
        //
        // The agent's own photograph is pulled out of this set and printed directly
        // above the signature boxes, where a reader looking for "who stood at this
        // door" will actually look for it - and where whoever signs can see it.
        $agentPhoto = null;
        $fieldPhotos = [];
        foreach ($photos as $photo) {
            if ((string) $photo['photo_type'] === 'agent' && $agentPhoto === null) {
                $agentPhoto = $photo;
                continue;
            }
            $fieldPhotos[] = $photo;
        }

        if ($fieldPhotos !== []) {
            $pdf->heading('Field Photographs');

            // Three to a row: any more and a printed photograph is too small to show
            // what it was taken to show.
            foreach (array_chunk($fieldPhotos, 3) as $chunk) {
                $pdf->imageStrip(array_map(
                    fn (array $photo): array => [
                        'path'    => Uploader::absolutePath((string) $photo['file_path']),
                        'label'   => self::photoLabel((string) $photo['photo_type']),
                        'caption' => Geo::photo($photo),
                    ],
                    $chunk
                ), 104.0);
            }
        }

        // ---- The agent at the door, and the space to sign -------------------
        //
        // Signatures are no longer captured on the phone. They are signed by hand on
        // this printed page, so what goes here is the photograph of who was standing
        // there, and directly beneath it the empty boxes to sign in.
        //
        // ONLY a photograph taken at this visit is printed. There is deliberately no
        // fallback to a portrait held on the agent's record: on a document that
        // geo-captions every other photograph, an uncaptioned one reads as more field
        // evidence, and an office portrait is not evidence of anything except that the
        // agent has a face. An absence is stated in words instead, which is a weaker
        // claim and a true one.
        $pdf->heading('Signatures');

        $agent = User::find((int) $report['agent_id']);
        $agentIdentity = (string) $report['agent_name']
            . "\n" . (string) ($report['bc_code'] ?? $agent['employee_code'] ?? '');

        if ($agentPhoto !== null) {
            $pdf->imageStrip([[
                'path'    => Uploader::absolutePath((string) $agentPhoto['file_path']),
                'label'   => 'BC / DC Agent (at the visit)',
                'caption' => $agentIdentity . "\n" . Geo::photo($agentPhoto),
            ]], 96.0);
        } else {
            $pdf->paragraph('No photograph of the agent was taken at this visit.', 8.4, '#8a5a00');
        }

        $pdf->paragraph(
            'To be signed by hand on this printed copy. Sign above the line.',
            8.4,
            '#4b5563'
        );

        $borrowerName = trim((string) $report['customer_name']);
        $pdf->signatureBlock([
            [
                'label'   => 'Borrower Signature / Thumb Impression',
                'caption' => ($borrowerName !== '' ? $borrowerName : 'Borrower') . "\nDate:",
            ],
            [
                'label'   => 'BC / DC Agent Signature',
                'caption' => $agentIdentity . "\nDate:",
            ],
        ], 60.0);

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
                'Position'    => Geo::approval($report),
            ], 2);

            if (($report['approval_remarks'] ?? '') !== '') {
                $pdf->paragraph('Remarks: ' . (string) $report['approval_remarks'], 8.6, '#1c2128');
            }
        }

        // The approving officer gets exactly what the agent gets: their photograph, and
        // an empty box beneath it to sign by hand.
        //
        // PRINTED IN BOTH STATES, and that is the point of this block rather than an
        // oversight in it. The copy somebody prints in order to sign it is precisely the
        // one that has not been approved yet - so putting the box behind "approved"
        // produced a form with nowhere for the manager to sign at the only moment they
        // would want to. The box is empty either way; nothing fills it but a pen.
        if (($report['approval_photo_path'] ?? null) !== null) {
            $pdf->imageStrip([[
                'path'    => Uploader::absolutePath((string) $report['approval_photo_path']),
                'label'   => 'Approver Photograph (at the approval)',
                'caption' => (string) ($report['approver_name'] ?? '') . "\n" . Geo::approval($report),
            ]], 84.0);
        } elseif ($status !== 'pending') {
            // Only worth saying once somebody HAS approved it. On a pending report there
            // is no approver yet, so an absent photograph is not a fact about anything.
            $pdf->paragraph('No photograph of the approver was taken at the approval.', 8.4, '#8a5a00');
        }

        $approverName = trim((string) ($report['approver_name'] ?? ''));
        $pdf->signatureBlock([[
            'label'   => 'Approver Signature',
            // Named when known, and a role when not: a box labelled only "Signature" on
            // an unapproved report tells whoever picks it up nothing about who signs it.
            'caption' => ($approverName !== '' ? $approverName : 'Branch Manager / Admin') . "\nDate:",
        ]], 60.0, 16.0, 2);

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

    /**
     * The printed and on-screen name for a photo_type.
     *
     * A map rather than ucwords() on the raw enum, because "Renewal Form" reads fine
     * but "Aadhaar" does not survive ucwords('aadhaar') on a document a borrower is
     * handed, and 'agent' on its own says nothing about whose photograph it is.
     */
    public static function photoLabel(string $photoType): string
    {
        return [
            'customer'     => 'Borrower',
            'house'        => 'House',
            'land'         => 'Land',
            'aadhaar'      => 'Aadhaar',
            'passbook'     => 'Passbook',
            'renewal_form' => 'Renewal Form',
            'agent'        => 'BC / DC Agent',
            'other'        => 'Other',
        ][$photoType] ?? ucwords(str_replace('_', ' ', $photoType));
    }
}
