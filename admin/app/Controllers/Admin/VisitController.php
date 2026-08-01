<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Pdf;
use App\Core\Request;
use App\Core\Response;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\User;
use App\Models\VisitReport;

/**
 * Read-only access to submitted field visit reports.
 *
 * There is deliberately no create/update/delete here: reports are filed from the
 * Android app and are append-only, so the panel can view and print them only.
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
        ]);
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

        // Attachment inventory. Images are not embedded: the PDF writer uses core
        // fonts only and stays dependency-free, so the report references them.
        $signatures = VisitReport::signatures((int) $report['id']);
        $photos = VisitReport::photos((int) $report['id']);

        $pdf->heading('Attachments');
        $pdf->keyValueBlock([
            'Photos'              => (string) count($photos),
            'Documents'           => (string) count(VisitReport::documents((int) $report['id'])),
            'Customer Signature'  => $this->hasSignature($signatures, 'customer') ? 'Captured' : 'Not captured',
            'Agent Signature'     => $this->hasSignature($signatures, 'agent') ? 'Captured' : 'Not captured',
        ], 4);

        $pdf->spacer(8);
        $pdf->paragraph(sprintf(
            'Report #%d submitted from %s%s on %s. This is an append-only record and has not been modified.',
            (int) $report['id'],
            (string) $report['source'],
            $report['app_version'] === null ? '' : ' v' . (string) $report['app_version'],
            fmt_datetime((string) $report['created_at'])
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
