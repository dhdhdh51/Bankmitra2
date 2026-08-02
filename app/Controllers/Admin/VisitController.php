<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Geo;
use App\Core\Pdf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Settings;
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
     * Renders the Field Visit Verification Report as a PDF.
     *
     * LAID OUT AS THE PRINTED FORM IS, section 1 to 13, using the snapshot stored on
     * the report rather than current customer data. The previous version printed the
     * same facts in its own order under its own headings, which made the printed copy
     * and the paper form two different documents that had to be reconciled by hand
     * before anything could be filed with a branch.
     *
     * Every tick box prints, ticked or not. That is a reversal of the earlier decision
     * to print only what was true, and the reason is that an unticked box and a
     * question the form never asked looked identical - so a reader could not tell
     * "neighbours were not asked" from "this report has no such field".
     */
    public function pdf(Request $request): void
    {
        $this->guard($request, 'visits.view');

        $report = $this->load($request);
        $id = (int) $report['id'];

        $ots = VisitReport::otsDetails($id);
        $ckcc = VisitReport::ckccDetails($id);
        $photos = VisitReport::photos($id);
        $documents = VisitReport::documents($id);

        $organisation = trim((string) Settings::get('bank_name', '')) !== ''
            ? (string) Settings::get('bank_name')
            : (string) Settings::get('app_name', 'D2 Recovery Solutions & Services');

        $pdf = new Pdf(
            'Field Visit Verification Report',
            sprintf(
                '%s · %s · %s',
                (string) $report['loan_account_number'],
                (string) $report['customer_name'],
                fmt_date((string) $report['visit_date'])
            ),
            false,
            'D2 Recovery confidential - field verification record'
        );

        // The paper form carries one grey line at the top of every page and its masthead
        // beneath it. The standard branded band is three lines tall and would push the
        // masthead a third of the way down page one.
        $pdf->useRunningHeader($organisation . '  |  Field Visit Verification Report');

        // The masthead, with the two strap lines under the title. They say what the
        // document is for and which rules it was collected under, and a printed copy
        // handed to a branch without them is a page of fields rather than the form.
        $pdf->titleBlock($organisation, 'Field Visit Verification Report', [
            '(KRM OTS / CKCC OD-2 Renewal / Recovery Verification Report)',
            "RBI Guidelines & Bank's Code of Conduct Compliant Format",
        ]);

        // ---- 1. General information -----------------------------------------
        $pdf->sectionBand(1, 'General Information');
        $pdf->formFields([
            'Visit Date' => fmt_date((string) $report['visit_date']),
            'Visit Time' => fmt_time((string) $report['visit_time']),
        ], 2);

        $pdf->groupLabel('Case Type');
        $pdf->checkboxGrid(
            self::pdfOptions(VisitReport::REPORT_TYPES, $report['report_type'] ?? null),
            3
        );
        if (($report['report_type_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other: ' . (string) $report['report_type_other_text'], 8.2, '#1c2128');
        }

        $pdf->formFields([
            'Branch Name'         => $report['branch_name'] ?: $report['branch_display_name'],
            'Branch Code'         => $report['branch_code'],
            'Regional Office'     => $report['regional_office'],
            'Zone'                => $report['zone'],
            'SP / CBC Name'       => $report['sp_cbc_name'],
            'BC Agent / DRA Name' => $report['agent_name'],
            'BC Code / DRA ID'    => $report['bc_code'],
            'Linked Branch'       => $report['linked_branch'],
            'District'            => $report['district'],
            'Village / Location'  => $report['village'],
            'GPS Latitude'        => $report['gps_latitude'],
            'GPS Longitude'       => $report['gps_longitude'],
        ], 2);

        // ---- 2. Borrower information ----------------------------------------
        $pdf->sectionBand(2, 'Borrower Information');
        $pdf->formFields([
            "Borrower Name"            => $report['customer_name'],
            "Father's / Husband's Name" => $report['father_husband_name'],
        ], 2);

        $pdf->groupLabel('Gender');
        $pdf->checkboxGrid(self::pdfOptions(VisitReport::GENDERS, $report['gender'] ?? null), 3);

        $pdf->formFields([
            'Date of Birth'          => $report['date_of_birth'] === null
                ? '-' : fmt_date((string) $report['date_of_birth']),
            'Mobile Number'          => $report['mobile_masked'],
            'Alternate Mobile'       => $report['alt_mobile_masked'],
            'Aadhaar (Last 4 Digits)' => $report['aadhaar_masked'],
            'PAN Number (Optional)'  => $report['pan_masked'],
        ], 2);

        $pdf->groupLabel('Address');
        $pdf->formFields([
            'Village'        => $report['addr_village'],
            'Gram Panchayat' => $report['gram_panchayat'],
            'Tehsil'         => $report['tehsil'],
            'District'       => $report['addr_district'],
            'State'          => $report['state'],
            'PIN Code'       => $report['pin_code'],
        ], 3);

        $pdf->groupLabel('Complete Residential Address');
        $pdf->paragraph(
            ($report['address'] ?? '') === '' ? 'Not recorded.' : (string) $report['address'],
            9.0,
            '#1c2128'
        );

        // ---- 3. Loan account details ----------------------------------------
        $pdf->sectionBand(3, 'Loan Account Details');
        $pdf->formFields([
            'Loan Account Number' => $report['loan_account_number'],
            'CIF Number'          => $report['cif_number'],
        ], 2);

        $pdf->groupLabel('Loan Type');
        $pdf->checkboxGrid(self::pdfLoanTypes($report['loan_type'] ?? null), 3);
        // A loan type the bank's own export wrote and the form has no box for. Printed in
        // words rather than dropped: it is the classification the account actually
        // carries, and a form with no box ticked would read as a missing answer.
        if (self::loanTypeKey($report['loan_type'] ?? null) === null
            && ($report['loan_type'] ?? '') !== '') {
            $pdf->paragraph('As recorded on the account: ' . (string) $report['loan_type'], 8.2, '#1c2128');
        }
        if (($report['loan_type_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other: ' . (string) $report['loan_type_other_text'], 8.2, '#1c2128');
        }

        $pdf->formFields([
            'Sanction Date'      => $report['sanction_date'] === null
                ? '-' : fmt_date((string) $report['sanction_date']),
            'Sanction Limit'     => self::pdfMoney($report['sanction_limit']),
            'Drawing Power'      => self::pdfMoney($report['drawing_power']),
            'Outstanding Amount' => rupees($report['outstanding_amount']),
            'Interest Overdue'   => self::pdfMoney($report['interest_overdue']),
            'Overdue Amount'     => rupees($report['overdue_amount']),
            'NPA Date'           => $report['npa_date'] === null
                ? 'Not classified' : fmt_date((string) $report['npa_date']),
            'Current Status'     => ucfirst((string) $report['current_status']),
        ], 2);

        $pdf->groupLabel('Asset Classification');
        $pdf->checkboxGrid(
            self::pdfOptions(VisitReport::ASSET_CLASSIFICATIONS, $report['asset_classification'] ?? null),
            3
        );

        // ---- 4. KRM OTS details ---------------------------------------------
        //
        // The band prints whether or not the section was filled in, and says so when it
        // was not. A missing section reads as an answer on a numbered form: skipping it
        // silently would leave the reader unsure whether section 4 was not applicable or
        // simply lost.
        $pdf->sectionBand(4, 'KRM OTS Details (If Applicable)');
        if ($ots === null) {
            $pdf->paragraph('Not applicable to this visit - no settlement section was filled in.', 8.6, '#6b7280');
        } else {
            $pdf->groupLabel('OTS Eligibility - Eligible for KRM OTS');
            $pdf->checkboxGrid(self::pdfYesNo((int) $ots['eligible_for_ots'] === 1), 3);

            $pdf->groupLabel('Applicable Scheme');
            $pdf->checkboxGrid(self::pdfOptions(VisitReport::OTS_SCHEMES, $ots['scheme'] ?? null), 3);
            if (($ots['scheme_other_text'] ?? '') !== '') {
                $pdf->paragraph('Other scheme: ' . (string) $ots['scheme_other_text'], 8.2, '#1c2128');
            }

            $pdf->formFields([
                'Outstanding Amount'       => self::pdfMoney($ots['outstanding_amount']),
                'Proposed Settlement'      => self::pdfMoney($ots['total_settlement_amount']),
                "Borrower's Share"         => self::pdfMoney($ots['borrower_payable_amount']),
                'Initial Deposit Required' => self::pdfMoney($ots['required_deposit_amount']),
            ], 2);

            // How those two figures were arrived at. A settlement amount without the
            // percentage it came from cannot be checked by whoever approves it.
            $pdf->formFields([
                'Relief / Waiver'        => self::pdfPercent($ots['relief_waiver_percent']),
                'Residual Loan Balance'  => self::pdfMoney($ots['rlb_amount']),
                'Payable Percent'        => self::pdfPercent($ots['payable_percent']),
                'Initial Deposit Percent' => self::pdfPercent($ots['initial_deposit_percent']),
                'Balance Payable'        => self::pdfMoney($ots['balance_payable']),
                'Borrower'               => $ots['borrower_name'] ?? $report['customer_name'],
            ], 2);

            $pdf->groupLabel('Customer Response');
            $pdf->checkboxGrid(
                self::pdfOptions(VisitReport::OTS_CUSTOMER_RESPONSES, $ots['customer_response'] ?? null),
                3
            );

            $pdf->formFields([
                'Expected Deposit Date' => $ots['expected_deposit_date'] === null
                    ? '-' : fmt_date((string) $ots['expected_deposit_date']),
                'Deposit Received'      => ((int) $ots['deposit_received'] === 1) ? 'Yes' : 'No',
                'Deposit Paid'          => self::pdfMoney($ots['deposit_amount']),
                'Deposit Date'          => $ots['deposit_date'] === null
                    ? '-' : fmt_date((string) $ots['deposit_date']),
                "Bank's Receipt / Txn"  => $ots['deposit_reference'] ?? '-',
                'Proposed Final Payment' => $ots['proposed_final_payment_date'] === null
                    ? '-' : fmt_date((string) $ots['proposed_final_payment_date']),
            ], 2);

            // The one place this system comes near money at all, so it says plainly that
            // the agent did not handle any of it.
            $pdf->paragraph(
                'Any deposit shown here was paid by the borrower at the bank. The agent does '
                . 'not collect money and this system records no cash handled by an agent.',
                8.0,
                '#6b7280'
            );

            $pdf->formFields([
                'Approval Status'   => VisitReport::OTS_APPROVAL_STATUSES[$ots['approval_status'] ?? ''] ?? '-',
                'Validity'          => ($ots['validity_from'] === null ? '-' : fmt_date((string) $ots['validity_from']))
                    . ' to ' . ($ots['validity_to'] === null ? '-' : fmt_date((string) $ots['validity_to'])),
                'Expected Closure'  => $ots['expected_closure_date'] === null
                    ? '-' : fmt_date((string) $ots['expected_closure_date']),
                'Borrower Accepted' => ((int) $ots['borrower_accepted'] === 1) ? 'Yes' : 'No',
            ], 2);

            if (($ots['rejection_reason'] ?? '') !== '') {
                $pdf->paragraph('Why the borrower declined: ' . (string) $ots['rejection_reason'], 8.6, '#8a5a00');
            }
        }

        // ---- 5. CKCC OD-2 renewal details -----------------------------------
        $pdf->sectionBand(5, 'CKCC OD-2 Renewal Details (If Applicable)');
        if ($ckcc === null) {
            $pdf->paragraph('Not applicable to this visit - no renewal section was filled in.', 8.6, '#6b7280');
        } else {
            $pdf->groupLabel('Eligible for Renewal');
            $pdf->checkboxGrid(self::pdfYesNo((int) $ckcc['eligible_for_renewal'] === 1), 3);

            $pdf->groupLabel('Renewal Due');
            $pdf->checkboxGrid(
                self::pdfOptions(VisitReport::CKCC_DUE_BUCKETS, $ckcc['renewal_due_bucket'] ?? null),
                3
            );

            // The deadline and what happens if it is missed, side by side. That pair is
            // the entire reason this report type exists.
            $pdf->formFields([
                'Renewal Due Date'  => $ckcc['renewal_due_date'] === null
                    ? '-' : fmt_date((string) $ckcc['renewal_due_date']),
                'Expected NPA Date' => $ckcc['expected_npa_date'] === null
                    ? '-' : fmt_date((string) $ckcc['expected_npa_date']),
                'Days Remaining'    => $ckcc['days_remaining'] === null
                    ? '-'
                    : ((int) $ckcc['days_remaining'] < 0
                        ? abs((int) $ckcc['days_remaining']) . ' day(s) overdue'
                        : (int) $ckcc['days_remaining'] . ' day(s)'),
            ], 2);

            $pdf->groupLabel('KYC Status');
            $pdf->checkboxGrid(
                self::pdfOptions(VisitReport::CKCC_KYC_STATUSES, $ckcc['kyc_status'] ?? null),
                3
            );

            $pdf->groupLabel('Renewal Readiness');
            $pdf->checkboxGrid(self::pdfFlags(VisitReport::CKCC_ELIGIBILITY_FLAGS, $ckcc), 2);

            $pdf->groupLabel('Renewal Consent');
            $pdf->checkboxGrid(self::pdfFlags(VisitReport::CKCC_CONSENT_FLAGS, $ckcc), 2);

            $pdf->formFields([
                'CIF Number'         => $ckcc['cif_number'] ?? '-',
                'Sanction Date'      => $ckcc['sanction_date'] === null
                    ? '-' : fmt_date((string) $ckcc['sanction_date']),
                'Sanction Limit'     => self::pdfMoney($ckcc['sanction_limit']),
                'Drawing Power'      => self::pdfMoney($ckcc['drawing_power']),
                'Outstanding Amount' => self::pdfMoney($ckcc['outstanding_amount']),
                'Interest Overdue'   => self::pdfMoney($ckcc['interest_overdue']),
            ], 2);

            if (($ckcc['agent_observation'] ?? '') !== '') {
                $pdf->paragraph('Agent observation on the renewal: ' . (string) $ckcc['agent_observation'], 8.6, '#1c2128');
            }
        }

        // ---- 6. Physical verification ---------------------------------------
        $pdf->sectionBand(6, 'Physical Verification');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::CONTACT_FLAGS, $report), 3);

        if ((int) $report['family_member_met'] === 1) {
            $pdf->formFields([
                'Family Member Met' => $report['family_member_name'],
                'Relationship'      => $report['family_member_relationship'],
            ], 2);
        }

        $pdf->groupLabel('Borrower Alive');
        $pdf->checkboxGrid(self::pdfYesNo((int) $report['borrower_alive'] === 1), 3);

        $pdf->groupLabel('Current Address');
        $pdf->checkboxGrid([
            ['label' => 'Same', 'checked' => (int) $report['same_address'] === 1],
            ['label' => 'Shifted', 'checked' => (int) $report['shifted'] === 1],
        ], 3);

        $pdf->groupLabel('Residence Verification');
        $pdf->checkboxGrid(
            self::pdfOptions(VisitReport::RESIDENCE_VERIFICATION, $report['residence_verified'] ?? null),
            3
        );

        $pdf->groupLabel('Neighbour Verification');
        $pdf->checkboxGrid(
            self::pdfOptions(VisitReport::NEIGHBOUR_VERIFICATION, $report['neighbour_verification'] ?? null),
            3
        );

        $pdf->groupLabel('Current Occupation');
        $pdf->checkboxGrid(self::pdfOccupations($report['occupation'] ?? null), 3);
        if (($report['occupation_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other occupation: ' . (string) $report['occupation_other_text'], 8.2, '#1c2128');
        }

        // ---- 7. Documents verified ------------------------------------------
        $pdf->sectionBand(7, 'Documents Verified');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::DOCUMENT_FLAGS, $report), 3);
        if (($report['doc_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other document: ' . (string) $report['doc_other_text'], 8.2, '#1c2128');
        }

        // ---- 8. BC agent / DRA observations ---------------------------------
        //
        // What the agent found out about payment sits here rather than under its own
        // numbered band. The form has thirteen sections and this system has to print
        // those thirteen if the paper copy is to match, so the recovery findings go where
        // a reader looks for what the agent learned - which is what they are.
        $pdf->sectionBand(8, 'BC Agent / DRA Observations');

        $pdf->groupLabel('Recovery Possibility');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::RECOVERY_FLAGS, $report), 4);

        if ((float) ($report['promise_amount'] ?? 0) > 0) {
            $pdf->formFields([
                'Promise Amount' => rupees($report['promise_amount']),
                'Promise Date'   => $report['promise_date'] === null
                    ? '-' : fmt_date((string) $report['promise_date']),
            ], 2);
        }

        $pdf->groupLabel('Reason for Non-Payment');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::REASON_FLAGS, $report), 3);
        if (($report['reason_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other reason: ' . (string) $report['reason_other_text'], 8.2, '#1c2128');
        }

        $pdf->groupLabel('Observations');
        $pdf->paragraph(
            ($report['remarks'] ?? '') === '' ? 'No observations recorded.' : (string) $report['remarks'],
            9.0,
            '#1c2128'
        );

        // ---- 9. Recommendation ----------------------------------------------
        $pdf->sectionBand(9, 'Recommendation');

        $pdf->groupLabel('KRM OTS');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::OTS_RECOMMENDATION_FLAGS, $ots ?? []), 3);

        $pdf->groupLabel('CKCC Renewal');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::CKCC_RECOMMENDATION_FLAGS, $ckcc ?? []), 3);
        if (($ckcc['rec_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other renewal recommendation: ' . (string) $ckcc['rec_other_text'], 8.2, '#1c2128');
        }

        $pdf->groupLabel('Recovery');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::RECOMMENDATION_FLAGS, $report), 3);
        if (($report['rec_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other recommendation: ' . (string) $report['rec_other_text'], 8.2, '#1c2128');
        }

        $pdf->groupLabel('General Recommendation');
        $pdf->paragraph(
            ($report['general_recommendation'] ?? '') === ''
                ? 'None recorded.'
                : (string) $report['general_recommendation'],
            9.0,
            '#1c2128'
        );

        // ---- 10. Evidence attached ------------------------------------------
        $pdf->sectionBand(10, 'Evidence Attached');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::EVIDENCE_FLAGS, $report), 3);
        if (($report['ev_other_text'] ?? '') !== '') {
            $pdf->paragraph('Other evidence: ' . (string) $report['ev_other_text'], 8.2, '#1c2128');
        }

        // What actually arrived, next to what was claimed above. The gap between the two
        // is the thing worth printing: a report that ticks "Passbook Copy" and carries no
        // file is exactly what a reviewer needs to see without opening the record.
        $pdf->formFields([
            'Photographs Attached' => (string) count($photos),
            'Documents Attached'   => (string) count($documents),
        ], 2);

        $pdf->groupLabel('GPS Location');
        if ((string) $report['gps_source'] === 'device' && $report['gps_latitude'] !== null) {
            $pdf->formFields([
                'Coordinates' => Geo::coordinates($report['gps_latitude'], $report['gps_longitude']),
                'Accuracy'    => $report['gps_accuracy_m'] === null
                    ? 'not reported'
                    : ((int) $report['gps_accuracy_m'] . ' m'
                        . (Geo::isPrecise($report['gps_accuracy_m']) ? '' : ' - too coarse to place a doorstep')),
                'Captured At' => $report['gps_captured_at'] === null
                    ? '-' : fmt_datetime((string) $report['gps_captured_at']),
                'Address'     => $report['gps_address'] ?? 'not resolved',
            ], 2);
        } else {
            // "Refused" and "no signal" are different conversations with a supervisor, so
            // the report says which it was rather than leaving a gap. Worded by Geo so the
            // panel and the print cannot disagree about it.
            $pdf->paragraph(Geo::visit($report), 9.0, '#1c2128');
        }

        // The agent's own photograph is held back out of this set and printed in the
        // certification block, above the line they sign - which is where a reader looking
        // for "who stood at this door" will look for it.
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
            $pdf->groupLabel('Field Photographs');

            // Three to a row: any more and a printed photograph is too small to show what
            // it was taken to show.
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

        // ---- 11. Declaration -------------------------------------------------
        //
        // In its own tinted box, as the form has it. This is the one paragraph on the
        // page somebody is agreeing to; running it in the same grey as a helper line
        // would make a certification look like guidance.
        $pdf->sectionBand(11, 'Declaration');
        $pdf->calloutBox(VisitReport::DECLARATION);
        // Whether the agent actually accepted it, stated rather than assumed. A printed
        // certification nobody agreed to is worth nothing, and a report filed by an older
        // app that never showed the tick box must not be printed as though it had.
        $pdf->paragraph(
            (int) ($report['declaration_accepted'] ?? 0) === 1
                ? 'The BC agent / DRA accepted this declaration when submitting the report.'
                : 'This report was submitted without the declaration being accepted in the app.',
            8.2,
            (int) ($report['declaration_accepted'] ?? 0) === 1 ? '#0f766e' : '#8a5a00'
        );

        // ---- 12. Certification -----------------------------------------------
        $pdf->sectionBand(12, 'Certification');

        $agent = User::find((int) $report['agent_id']);
        $agentIdentity = (string) $report['agent_name']
            . "\n" . (string) ($report['bc_code'] ?? $agent['employee_code'] ?? '');

        $pdf->groupLabel('BC Agent / DRA');
        $pdf->formFields([
            'Name'             => $report['agent_name'],
            'BC Code / DRA ID' => $report['bc_code'] ?? ($agent['employee_code'] ?? null),
            'Mobile Number'    => $report['agent_mobile'],
        ], 3);

        // ONLY a photograph taken at this visit is printed. There is deliberately no
        // fallback to the portrait on the agent's record: on a document that geo-captions
        // every other photograph, an uncaptioned one reads as more field evidence, and an
        // office portrait is evidence of nothing except that the agent has a face. An
        // absence is stated in words instead, which is a weaker claim and a true one.
        if ($agentPhoto !== null) {
            $pdf->imageStrip([[
                'path'    => Uploader::absolutePath((string) $agentPhoto['file_path']),
                'label'   => 'BC / DC Agent (at the visit)',
                'caption' => $agentIdentity . "\n" . Geo::photo($agentPhoto),
            ]], 96.0);
        } else {
            $pdf->paragraph('No photograph of the agent was taken at this visit.', 8.4, '#8a5a00');
        }

        $pdf->paragraph('To be signed by hand on this printed copy. Sign above the line.', 8.4, '#4b5563');

        $borrowerName = trim((string) $report['customer_name']);
        $pdf->signatureBlock([
            [
                'label'   => 'BC Agent / DRA Signature',
                'caption' => $agentIdentity . "\nDate:",
            ],
            [
                'label'   => 'Borrower Signature / Thumb Impression',
                'caption' => ($borrowerName !== '' ? $borrowerName : 'Borrower') . "\nDate:",
            ],
        ], 60.0);

        $pdf->groupLabel('Supervisor Verification');
        $pdf->formFields([
            'Name'                  => $report['supervisor_name'],
            'Designation'           => $report['supervisor_designation'],
            'Employee ID / DRA ID'  => $report['supervisor_employee_id'],
        ], 3);
        $pdf->ruledFields([
            'Verified On' => $report['supervisor_verified_at'] === null
                ? '' : fmt_date((string) $report['supervisor_verified_at']),
            'Date'        => '',
        ], 2);

        $supervisorName = trim((string) ($report['supervisor_name'] ?? ''));
        $pdf->signatureBlock([[
            'label'   => 'Supervisor Signature',
            'caption' => ($supervisorName !== '' ? $supervisorName : 'Supervisor') . "\nDate:",
        ]], 60.0, 16.0, 2);

        // ---- Approval, which is this system's own step -----------------------
        $pdf->groupLabel('Approval');
        $status = (string) ($report['approval_status'] ?? 'pending');

        if ($status === 'pending') {
            $pdf->paragraph('This report has not yet been reviewed.', 9.0, '#8a5a00');
        } else {
            $pdf->formFields([
                'Status'      => ucfirst($status),
                'Approved By' => $report['approver_name'] ?? '-',
                'Approved At' => $report['approved_at'] === null
                    ? '-' : fmt_datetime((string) $report['approved_at']),
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

        // ---- 13. Final report status ------------------------------------------
        $pdf->sectionBand(13, 'Final Report Status');

        $pdf->groupLabel('KRM OTS');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::OTS_STATUS_FLAGS, $ots ?? []), 3);

        $pdf->groupLabel('CKCC OD-2 Renewal');
        $pdf->checkboxGrid(self::pdfFlags(VisitReport::CKCC_STATUS_FLAGS, $ckcc ?? []), 3);

        // ---- Operator-defined fields -------------------------------------------
        // Only those marked "print on the visit report". Off by default, because a
        // field somebody added to track an internal note should not silently start
        // appearing on a document handed to a borrower.
        $extra = [];
        foreach ([
            ['customer', (int) $report['customer_id']],
            ['loan_account', (int) $report['loan_account_id']],
            ['visit_report', $id],
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
            $pdf->groupLabel('Additional Details');
            $pdf->formFields($extra, 2);
        }

        // ---- The closing note the form carries ---------------------------------
        $pdf->spacer(4);
        $pdf->calloutBox(
            [VisitReport::IMPORTANT_NOTE],
            '#eef4fb',
            '#12325e',
            '#3f3f46',
            'Important Note'
        );

        // Provenance. A report that has been corrected must say so on its face - the
        // alternative is a printed document that looks pristine while differing from what
        // the agent actually submitted.
        $revisions = (int) ($report['revision_count'] ?? 0);

        $pdf->paragraph(sprintf(
            'Report #%d submitted from %s%s on %s. %s',
            $id,
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

        $this->logExport('Visits', sprintf('Exported visit report #%d to PDF', $id));

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
     * A money figure for the printout, or a dash.
     *
     * Not `rupees()`: an empty settlement figure printed as "Rs.0.00" reads as a settlement
     * of nothing rather than a figure nobody filled in, and on a document somebody approves
     * those are different statements.
     */
    private static function pdfMoney(mixed $amount): string
    {
        return $amount === null || $amount === '' ? '-' : rupees($amount);
    }

    /**
     * A one-of-many row of tick boxes: every option, with the stored one ticked.
     *
     * @param  array<string,string> $map
     * @return list<array{label:string,checked:bool}>
     */
    private static function pdfOptions(array $map, mixed $current): array
    {
        $value = $current === null ? '' : trim((string) $current);

        $items = [];
        foreach ($map as $key => $label) {
            $items[] = ['label' => $label, 'checked' => $key === $value];
        }
        return $items;
    }

    /**
     * A row of independent tick boxes, one per flag column.
     *
     * Takes an empty array happily, which is what section 9 and section 13 hand it when
     * a report has no settlement or renewal row: the boxes still print, all unticked,
     * because the form asks the questions whether or not this visit answered them.
     *
     * @param  array<string,string> $map  column => label
     * @param  array<string,mixed>  $row
     * @return list<array{label:string,checked:bool}>
     */
    private static function pdfFlags(array $map, array $row): array
    {
        $items = [];
        foreach ($map as $column => $label) {
            $items[] = ['label' => $label, 'checked' => (int) ($row[$column] ?? 0) === 1];
        }
        return $items;
    }

    /**
     * The Yes / No pair the form uses for a single boolean.
     *
     * @return list<array{label:string,checked:bool}>
     */
    private static function pdfYesNo(bool $value): array
    {
        return [
            ['label' => 'Yes', 'checked' => $value],
            ['label' => 'No', 'checked' => !$value],
        ];
    }

    /**
     * The Loan Type row, ticked from a value the bank's own export may have written.
     *
     * `loan_type` is a snapshot, not a form field: it usually arrives from a core-banking
     * file as "CKCC" or "Crop Loan", and only sometimes as one of the form's own keys.
     * Matched case-insensitively and against the printed label as well, because the
     * commonest value on the commonest report type is "CKCC" and it ticking nothing
     * would make the form look unanswered on almost every renewal.
     *
     * @return list<array{label:string,checked:bool}>
     */
    private static function pdfLoanTypes(mixed $current): array
    {
        $matched = self::loanTypeKey($current);

        $items = [];
        foreach (VisitReport::LOAN_TYPES as $key => $label) {
            $items[] = ['label' => $label, 'checked' => $key === $matched];
        }
        return $items;
    }

    /**
     * Which Loan Type box a stored value belongs in, or null for one the form has no box
     * for - a "Doubtful 2" or a "Crop Loan", which prints in words instead.
     */
    private static function loanTypeKey(mixed $value): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }

        // Collapses "Agriculture Term Loan", "agri_term" and "AGRI-TERM" onto one key.
        $squash = static fn (string $text): string => preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
        $needle = $squash($raw);

        foreach (VisitReport::LOAN_TYPES as $key => $label) {
            if ($needle === $squash($key) || $needle === $squash($label)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The occupation row, built from the enum so it cannot fall out of step with it.
     *
     * @return list<array{label:string,checked:bool}>
     */
    private static function pdfOccupations(mixed $current): array
    {
        $value = $current === null ? '' : trim((string) $current);
        // A report filed by an older app still carries 'job'; the box it belongs in is
        // Service. Without this the printed form would show no occupation at all.
        if ($value === 'job') {
            $value = 'service';
        }

        $items = [];
        foreach (VisitReport::OCCUPATIONS as $key) {
            $items[] = ['label' => occupation_label($key), 'checked' => $key === $value];
        }
        return $items;
    }

    /** A percentage with its trailing zeros trimmed, or a dash. */
    private static function pdfPercent(mixed $percent, bool $bracketed = false): string
    {
        if ($percent === null || $percent === '') {
            return $bracketed ? '' : '-';
        }

        $text = rtrim(rtrim(number_format((float) $percent, 2), '0'), '.') . '%';

        return $bracketed ? '(' . $text . ')' : $text;
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
