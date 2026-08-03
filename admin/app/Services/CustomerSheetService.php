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
    /** Every string this sheet's labels/headings can be, in the order they need it. */
    private const STRINGS = [
        'en' => [
            'title'              => 'Customer Data Sheet',
            'borrower'           => 'Borrower',
            'customer_name'      => 'Customer Name',
            'father_husband'     => 'Father/Husband Name',
            'mobile'             => 'Mobile',
            'aadhaar'            => 'Aadhaar',
            'village'            => 'Village',
            'address'            => 'Address',
            'loan_account'       => 'Loan Account',
            'loan_account_no'    => 'Loan Account No',
            'cif_number'         => 'CIF Number',
            'loan_type'          => 'Loan Type',
            'branch'             => 'Branch',
            'bc_code'            => 'BC Code',
            'status'             => 'Status',
            'outstanding'        => 'Outstanding',
            'overdue'            => 'Overdue',
            'interest_overdue'   => 'Interest Overdue',
            'sanction_limit'     => 'Sanction Limit',
            'drawing_power'      => 'Drawing Power',
            'sanction_date'      => 'Sanction Date',
            'npa_date'           => 'Probable NPA/NPA DATE',
            'npa'                => 'NPA',
            'yes'                => 'Yes',
            'no'                 => 'No',
            'ckcc_renewal_due'   => 'CKCC Renewal Due',
            'assigned_agent'     => 'Assigned Agent',
            'settlement_position' => 'Settlement Position (as stated by the branch)',
            'ots_eligible'       => 'OTS Eligible',
            'krm_eligible'       => 'KRM Eligible',
            'ots_amount'         => 'OTS Amount',
            'deposit_amount'     => 'Deposit Amount',
            'not_stated'         => 'Not stated',
            'settlement_note'    => 'These figures come from the branch, not from a field visit. Do not '
                . 'commit to any settlement not confirmed in writing by the branch.',
            'promises_to_pay'    => 'Promises to Pay',
            'none_recorded'      => 'None recorded.',
            'promised_on'        => 'Promised On',
            'due_date'           => 'Due Date',
            'amount'             => 'Amount',
            'taken_by'           => 'Taken By',
            'visit_history'      => 'Visit History',
            'visit_history_note' => 'Visit history is append-only: entries are never edited or deleted.',
            'no_visits'          => 'No visits recorded yet.',
            'date'               => 'Date',
            'type'               => 'Type',
            'agent'              => 'Agent',
            'outcome'            => 'Outcome',
            'confidential'       => 'confidential - for authorised recovery use only',
        ],
        // Field labels only - a borrower's own name, address and figures are data,
        // not translated text, and print exactly as recorded regardless of language.
        'hi' => [
            'title'              => 'ग्राहक विवरण पत्र',
            'borrower'           => 'उधारकर्ता',
            'customer_name'      => 'ग्राहक का नाम',
            'father_husband'     => 'पिता/पति का नाम',
            'mobile'             => 'मोबाइल',
            'aadhaar'            => 'आधार',
            'village'            => 'गांव',
            'address'            => 'पता',
            'loan_account'       => 'ऋण खाता',
            'loan_account_no'    => 'ऋण खाता संख्या',
            'cif_number'         => 'सीआईएफ संख्या',
            'loan_type'          => 'ऋण प्रकार',
            'branch'             => 'शाखा',
            'bc_code'            => 'बीसी कोड',
            'status'             => 'स्थिति',
            'outstanding'        => 'बकाया राशि',
            'overdue'            => 'अतिदेय राशि',
            'interest_overdue'   => 'अतिदेय ब्याज',
            'sanction_limit'     => 'स्वीकृत सीमा',
            'drawing_power'      => 'आहरण शक्ति',
            'sanction_date'      => 'स्वीकृति तिथि',
            'npa_date'           => 'संभावित एनपीए/एनपीए तिथि',
            'npa'                => 'एनपीए',
            'yes'                => 'हाँ',
            'no'                 => 'नहीं',
            'ckcc_renewal_due'   => 'सीकेसीसी नवीनीकरण देय',
            'assigned_agent'     => 'नियुक्त एजेंट',
            'settlement_position' => 'निपटान स्थिति (शाखा द्वारा बताई गई)',
            'ots_eligible'       => 'ओटीएस पात्र',
            'krm_eligible'       => 'केआरएम पात्र',
            'ots_amount'         => 'ओटीएस राशि',
            'deposit_amount'     => 'जमा राशि',
            'not_stated'         => 'नहीं बताया गया',
            'settlement_note'    => 'यह आंकड़े शाखा से हैं, फील्ड विजिट से नहीं। शाखा द्वारा लिखित में '
                . 'पुष्टि किए बिना किसी निपटान के लिए प्रतिबद्ध न हों।',
            'promises_to_pay'    => 'भुगतान का वादा',
            'none_recorded'      => 'कोई दर्ज नहीं।',
            'promised_on'        => 'वादा किया गया',
            'due_date'           => 'देय तिथि',
            'amount'             => 'राशि',
            'taken_by'           => 'द्वारा लिया गया',
            'visit_history'      => 'विजिट इतिहास',
            'visit_history_note' => 'विजिट इतिहास केवल-जोड़ने योग्य है: प्रविष्टियां कभी संपादित या हटाई नहीं जाती हैं।',
            'no_visits'          => 'अभी तक कोई विजिट दर्ज नहीं।',
            'date'               => 'तिथि',
            'type'               => 'प्रकार',
            'agent'              => 'एजेंट',
            'outcome'            => 'परिणाम',
            'confidential'       => 'गोपनीय - केवल अधिकृत रिकवरी उपयोग के लिए',
        ],
    ];

    /**
     * Renders the sheet for one loan account.
     *
     * @param string $language 'en' or 'hi'. Anything else falls back to 'en' -
     *                          a bad or missing language code must produce the
     *                          sheet an agent can already read, not an error.
     * @return array{filename:string, bytes:string, account:string, customer:string}
     */
    public static function render(int $loanAccountId, string $language = 'en'): array
    {
        $lead = LoanAccount::findWithPii($loanAccountId);
        if ($lead === null) {
            throw new \RuntimeException('That loan account could not be found.');
        }

        $t = self::STRINGS[$language] ?? self::STRINGS['en'];

        $bank = trim((string) Settings::get('bank_name', ''));
        $account = (string) $lead['loan_account_number'];
        $customer = (string) $lead['customer_name'];

        // Same masthead the field visit report uses, not the generic header band -
        // every printed document this system produces is recognised by the same head,
        // and the agency's own name (not the bank's) is what belongs across the top of
        // it. See VisitController::pdf() for the fuller reasoning.
        $organisation = trim((string) Settings::get('report_org_name', '')) !== ''
            ? (string) Settings::get('report_org_name')
            : 'D2 Recovery Solutions & Services';

        $pdf = new Pdf(
            $t['title'],
            sprintf('%s · %s', $account, $customer),
            false,
            ($bank !== '' ? $bank . ' · ' : '') . 'D2 Recovery Solutions & Services ' . $t['confidential']
        );

        $pdf->useRunningHeader($organisation . '  |  ' . $t['title']);
        $pdf->titleBlock($organisation, $t['title'], [
            sprintf('%s · %s', $account, $customer),
        ]);

        // ---- Borrower ------------------------------------------------------
        $pdf->heading($t['borrower']);
        $pdf->keyValueBlock([
            $t['customer_name']  => $customer,
            $t['father_husband'] => $lead['father_husband_name'],
            // The mobile is shown in full: the agent has to be able to ring the
            // borrower from the printed sheet, and they already hold the number in
            // the app. Aadhaar stays masked - a field visit never needs the full
            // number, and a PDF is one forward away from being outside the bank.
            $t['mobile']         => $lead['mobile'] ?? $lead['mobile_masked'],
            $t['aadhaar']        => $lead['aadhaar_masked'],
            $t['village']        => $lead['village'],
            $t['address']        => $lead['address'],
        ], 2);

        // ---- Loan ----------------------------------------------------------
        $pdf->heading($t['loan_account']);
        $pdf->keyValueBlock([
            $t['loan_account_no'] => $account,
            $t['cif_number']      => $lead['cif_number'],
            $t['loan_type']       => $lead['loan_type'],
            $t['branch']          => $lead['branch_name'],
            $t['bc_code']         => $lead['bc_code'],
            $t['status']          => ucfirst(str_replace('_', ' ', (string) $lead['current_status'])),
            $t['outstanding']     => self::money($lead['outstanding_amount']),
            $t['overdue']         => self::money($lead['overdue_amount']),
            $t['interest_overdue'] => self::money($lead['interest_overdue']),
            $t['sanction_limit']  => self::money($lead['sanction_limit']),
            $t['drawing_power']   => self::money($lead['drawing_power']),
            $t['sanction_date']   => self::date($lead['sanction_date']),
            // Probable until the account is classified, actual afterwards - one column,
            // two readings, so the label says both.
            $t['npa_date']        => self::date($lead['npa_date']),
            $t['npa']             => ((int) $lead['is_npa']) === 1 ? $t['yes'] : $t['no'],
            $t['ckcc_renewal_due'] => self::date($lead['ckcc_renewal_due_date']),
            $t['assigned_agent']  => $lead['agent_name'],
        ], 2);

        // ---- Settlement position -------------------------------------------
        // Only printed when the branch actually stated one. A heading over four
        // dashes tells the agent nothing and invites them to assume a No.
        $hasPosition = $lead['ots_eligible'] !== null
            || $lead['krm_eligible'] !== null
            || $lead['ots_amount'] !== null
            || $lead['deposit_amount'] !== null;

        if ($hasPosition) {
            $pdf->heading($t['settlement_position']);
            $pdf->keyValueBlock([
                $t['ots_eligible']   => self::flag($lead['ots_eligible'], $t),
                $t['krm_eligible']   => self::flag($lead['krm_eligible'], $t),
                $t['ots_amount']     => self::money($lead['ots_amount']),
                $t['deposit_amount'] => self::money($lead['deposit_amount']),
            ], 2);
            $pdf->paragraph($t['settlement_note'], 8.0, '#b3261e');
        }

        // ---- Promises ------------------------------------------------------
        $promises = Promise::forLoanAccount($loanAccountId);
        $pdf->heading($t['promises_to_pay']);
        if ($promises === []) {
            $pdf->paragraph($t['none_recorded']);
        } else {
            $pdf->setColumns([
                ['label' => $t['promised_on'], 'width' => 1.1],
                ['label' => $t['due_date'], 'width' => 1.1],
                ['label' => $t['amount'], 'width' => 1.0, 'align' => 'right'],
                ['label' => $t['status'], 'width' => 1.0],
                ['label' => $t['taken_by'], 'width' => 1.6],
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
        $pdf->heading($t['visit_history']);
        // Stated whether or not there are rows: it is a property of the record, and
        // it tells whoever reads the sheet that what is printed is the whole story
        // and that nothing has been quietly tidied up.
        $pdf->paragraph($t['visit_history_note'], 8.0);
        if ($visits === []) {
            $pdf->paragraph($t['no_visits']);
        } else {
            $pdf->setColumns([
                ['label' => $t['date'], 'width' => 1.0],
                ['label' => $t['type'], 'width' => 1.0],
                ['label' => $t['agent'], 'width' => 1.6],
                ['label' => $t['village'], 'width' => 1.2],
                ['label' => $t['outcome'], 'width' => 1.6],
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

    /** The languages this sheet can be produced in, for validating a caller's request. */
    public static function languages(): array
    {
        return array_keys(self::STRINGS);
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

    /**
     * NULL means the branch did not say, which must not read as a No.
     *
     * @param array<string,string> $t
     */
    private static function flag(mixed $value, array $t): string
    {
        if ($value === null || $value === '') {
            return $t['not_stated'];
        }
        return ((int) $value) === 1 ? $t['yes'] : $t['no'];
    }
}
