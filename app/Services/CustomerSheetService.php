<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Pdf;
use App\Core\Settings;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\VisitReport;

/**
 * Builds the one-page customer data sheet an agent can carry into the field.
 *
 * An agent standing at a borrower's door needs the whole position on paper: who
 * this is, what is owed, whether the branch has already agreed to settle and at
 * what figure, and what happened on previous visits. Reading that off a phone in
 * sunlight while talking to someone does not work, and the sheet is also what gets
 * shown to the borrower and handed to the branch.
 *
 * The same builder serves the panel and the mobile API so the two can never
 * disagree about what a customer's record says.
 */
final class CustomerSheetService
{
    /**
     * Renders the sheet for one loan account.
     *
     * @return array{filename:string, bytes:string, account:string, customer:string}
     */
    public static function render(int $loanAccountId): array
    {
        $lead = LoanAccount::findWithPii($loanAccountId);
        if ($lead === null) {
            throw new \RuntimeException('That loan account could not be found.');
        }

        $bank = trim((string) Settings::get('bank_name', ''));
        $account = (string) $lead['loan_account_number'];
        $customer = (string) $lead['customer_name'];

        $pdf = new Pdf(
            'Customer Data Sheet',
            sprintf('%s · %s', $account, $customer),
            false,
            ($bank !== '' ? $bank . ' · ' : '') . 'D2 Recovery confidential - for authorised recovery use only'
        );

        // ---- Borrower ------------------------------------------------------
        $pdf->heading('Borrower');
        $pdf->keyValueBlock([
            'Customer Name'       => $customer,
            'Father/Husband Name' => $lead['father_husband_name'],
            // The mobile is shown in full: the agent has to be able to ring the
            // borrower from the printed sheet, and they already hold the number in
            // the app. Aadhaar stays masked - a field visit never needs the full
            // number, and a PDF is one forward away from being outside the bank.
            'Mobile'              => $lead['mobile'] ?? $lead['mobile_masked'],
            'Aadhaar'             => $lead['aadhaar_masked'],
            'Village'             => $lead['village'],
            'Address'             => $lead['address'],
        ], 2);

        // ---- Loan ----------------------------------------------------------
        $pdf->heading('Loan Account');
        $pdf->keyValueBlock([
            'Loan Account No'   => $account,
            'CIF Number'        => $lead['cif_number'],
            'Loan Type'         => $lead['loan_type'],
            'Branch'            => $lead['branch_name'],
            'BC Code'           => $lead['bc_code'],
            'Status'            => ucfirst(str_replace('_', ' ', (string) $lead['current_status'])),
            'Outstanding'       => self::money($lead['outstanding_amount']),
            'Overdue'           => self::money($lead['overdue_amount']),
            'Interest Overdue'  => self::money($lead['interest_overdue']),
            'Sanction Limit'    => self::money($lead['sanction_limit']),
            'Drawing Power'     => self::money($lead['drawing_power']),
            'Sanction Date'     => self::date($lead['sanction_date']),
            'NPA Date'          => self::date($lead['npa_date']),
            'NPA'               => ((int) $lead['is_npa']) === 1 ? 'Yes' : 'No',
            'CKCC Renewal Due'  => self::date($lead['ckcc_renewal_due_date']),
            'Assigned Agent'    => $lead['agent_name'],
        ], 2);

        // ---- Settlement position -------------------------------------------
        // Only printed when the branch actually stated one. A heading over four
        // dashes tells the agent nothing and invites them to assume a No.
        $hasPosition = $lead['ots_eligible'] !== null
            || $lead['krm_eligible'] !== null
            || $lead['ots_amount'] !== null
            || $lead['deposit_amount'] !== null;

        if ($hasPosition) {
            $pdf->heading('Settlement Position (as stated by the branch)');
            $pdf->keyValueBlock([
                'OTS Eligible'   => self::flag($lead['ots_eligible']),
                'KRM Eligible'   => self::flag($lead['krm_eligible']),
                'OTS Amount'     => self::money($lead['ots_amount']),
                'Deposit Amount' => self::money($lead['deposit_amount']),
            ], 2);
            $pdf->paragraph(
                'These figures come from the branch, not from a field visit. Do not '
                . 'commit to any settlement not confirmed in writing by the branch.',
                8.0,
                '#b3261e'
            );
        }

        // ---- Promises ------------------------------------------------------
        $promises = Promise::forLoanAccount($loanAccountId);
        $pdf->heading('Promises to Pay');
        if ($promises === []) {
            $pdf->paragraph('None recorded.');
        } else {
            $pdf->setColumns([
                ['label' => 'Promised On', 'width' => 1.1],
                ['label' => 'Due Date', 'width' => 1.1],
                ['label' => 'Amount', 'width' => 1.0, 'align' => 'right'],
                ['label' => 'Status', 'width' => 1.0],
                ['label' => 'Taken By', 'width' => 1.6],
            ]);
            $pdf->tableHeader();
            foreach ($promises as $promise) {
                $pdf->row([
                    self::date($promise['created_at'] ?? null),
                    self::date($promise['promise_date'] ?? null),
                    self::money($promise['promised_amount'] ?? null),
                    ucfirst((string) ($promise['status'] ?? '')),
                    (string) ($promise['agent_name'] ?? ''),
                ]);
            }
        }

        // ---- Visit history -------------------------------------------------
        $visits = VisitReport::forLoanAccount($loanAccountId, 40);
        $pdf->heading('Visit History');
        // Stated whether or not there are rows: it is a property of the record, and
        // it tells whoever reads the sheet that what is printed is the whole story
        // and that nothing has been quietly tidied up.
        $pdf->paragraph('Visit history is append-only: entries are never edited or deleted.', 8.0);
        if ($visits === []) {
            $pdf->paragraph('No visits recorded yet.');
        } else {
            $pdf->setColumns([
                ['label' => 'Date', 'width' => 1.0],
                ['label' => 'Type', 'width' => 1.0],
                ['label' => 'Agent', 'width' => 1.6],
                ['label' => 'Village', 'width' => 1.2],
                ['label' => 'Outcome', 'width' => 1.6],
            ]);
            $pdf->tableHeader();
            foreach ($visits as $visit) {
                $pdf->row([
                    self::date($visit['visit_date'] ?? null),
                    ucfirst(str_replace('_', ' ', (string) ($visit['report_type'] ?? 'recovery'))),
                    (string) ($visit['agent_name'] ?? ''),
                    (string) ($visit['village'] ?? ''),
                    (string) ($visit['recovery_status'] ?? $visit['customer_response'] ?? ''),
                ]);
            }
        }

        return [
            'filename' => self::filename($account),
            'bytes'    => $pdf->output(),
            'account'  => $account,
            'customer' => $customer,
        ];
    }

    /** A filename that is safe on every platform and identifies the account. */
    public static function filename(string $account): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $account) ?? 'account';
        return sprintf('customer-sheet-%s-%s.pdf', trim($safe, '-'), date('Ymd'));
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        return 'Rs. ' . number_format((float) $value, 2);
    }

    private static function date(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return '-';
        }
        $time = strtotime($raw);
        return $time === false ? $raw : date('d/m/Y', $time);
    }

    /** NULL means the branch did not say, which must not read as a No. */
    private static function flag(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not stated';
        }
        return ((int) $value) === 1 ? 'Yes' : 'No';
    }
}
