<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Works out which spreadsheet column is which, so any bank's export can be
 * imported without anyone reformatting it into our template first.
 *
 * Every bank names its columns differently - "Loan A/C No.", "ACCOUNT_NUMBER",
 * "Loan Acct", "खाता संख्या" - orders them differently, and pads the file with
 * columns we have no use for. Demanding a fixed template pushed all of that work
 * onto the person doing the import, which is where the mistakes happen.
 *
 * Detection runs in two stages, in this order deliberately:
 *
 *   1. HEADER TEXT. A generous vocabulary per field, matched exactly, then by
 *      token subset, then by substring, then by edit distance. Candidates are
 *      scored and assigned GLOBALLY - the best (field, column) pair wins, rather
 *      than the first field in declaration order claiming a column it only
 *      loosely matches and starving a field that matched it exactly.
 *
 *   2. VALUES. For a handful of fields whose contents have an unmistakable shape,
 *      the sample rows are profiled: 12 digits is an Aadhaar number, 10 digits
 *      starting 6-9 is an Indian mobile, a column of mostly-unique alphanumerics
 *      with digits in them is an account number, mostly-letters-with-a-space is
 *      a person's name.
 *
 * MONEY AND DATES ARE NEVER INFERRED FROM VALUES, only from headers. A bank
 * export routinely carries outstanding, overdue, interest, sanction limit and
 * drawing power side by side - five columns of indistinguishable decimals. Any
 * guess about which is which has a four-in-five chance of putting the wrong
 * rupee figure in front of an agent, and a wrong balance is worse than a missing
 * one. The same goes for NPA date versus sanction date versus renewal date.
 *
 * Nothing here writes to the database. Detection produces a proposal with a
 * confidence per field, which the import screen shows for confirmation before a
 * single row is read - so a wrong guess costs a dropdown, not a bad import.
 */
final class ColumnDetector
{
    /** Header-text score at or above which a column is accepted. */
    private const HEADER_THRESHOLD = 55;

    /** Score that counts as "this cell is definitely a column heading". */
    private const HEADER_CONFIDENT = 80;

    /** How many data rows to profile when inferring from values. */
    private const SAMPLE_ROWS = 40;

    /** Fraction of a column's values that must fit a shape to accept it. */
    private const VALUE_THRESHOLD = 0.6;

    /**
     * Every field the importer can fill, in the order they are shown and written
     * to the generated template. This is the single source of truth: the service,
     * the import screen's guide and the template all read it, so adding a field
     * here is the only edit needed.
     *
     * type drives value-based inference and nothing else:
     *   account  identifier, mostly unique
     *   name     person's name
     *   mobile   Indian mobile number
     *   aadhaar  12-digit UID
     *   amount   rupee figure   - header match only, never inferred
     *   date     calendar date  - header match only, never inferred
     *   text     free text      - header match only
     *
     * @return array<string,array{label:string,required:bool,type:string}>
     */
    public static function fields(): array
    {
        return [
            'branch'                => ['label' => 'Branch',                'required' => false, 'type' => 'text',    'example' => 'Rampur Rural'],
            'bc_code'               => ['label' => 'BC Code',               'required' => false, 'type' => 'text',    'example' => 'BC-001'],
            'loan_account_number'   => ['label' => 'Loan Account Number',   'required' => true,  'type' => 'account', 'example' => 'LN0000000001'],
            'cif_number'            => ['label' => 'CIF Number',            'required' => false, 'type' => 'account', 'example' => 'CIF778899'],
            'customer_name'         => ['label' => 'Customer Name',         'required' => true,  'type' => 'name',    'example' => 'Ramesh Kumar'],
            'father_husband_name'   => ['label' => 'Father/Husband Name',   'required' => false, 'type' => 'name',    'example' => 'Shyam Lal'],
            'mobile'                => ['label' => 'Mobile',                'required' => false, 'type' => 'mobile',  'example' => '9876543210'],
            'aadhaar'               => ['label' => 'Aadhaar',               'required' => false, 'type' => 'aadhaar', 'example' => '234567890123'],
            'village'               => ['label' => 'Village',               'required' => false, 'type' => 'text',    'example' => 'Kotri'],
            'address'               => ['label' => 'Address',               'required' => false, 'type' => 'text',    'example' => 'House 12, Kotri'],
            'loan_type'             => ['label' => 'Loan Type',             'required' => false, 'type' => 'text',    'example' => 'Crop Loan'],
            'outstanding_amount'    => ['label' => 'Outstanding Amount',    'required' => false, 'type' => 'amount',  'example' => '250000.00'],
            'overdue_amount'        => ['label' => 'Overdue Amount',        'required' => false, 'type' => 'amount',  'example' => '24500.00'],
            'interest_overdue'      => ['label' => 'Interest Overdue',      'required' => false, 'type' => 'amount',  'example' => '3400.00'],
            'sanction_limit'        => ['label' => 'Sanction Limit',        'required' => false, 'type' => 'amount',  'example' => '300000.00'],
            'drawing_power'         => ['label' => 'Drawing Power',         'required' => false, 'type' => 'amount',  'example' => '280000.00'],
            'npa_date'              => ['label' => 'NPA Date',              'required' => false, 'type' => 'date',    'example' => '15/10/2024'],
            'sanction_date'         => ['label' => 'Sanction Date',         'required' => false, 'type' => 'date',    'example' => '01/04/2023'],
            'ckcc_renewal_due_date' => ['label' => 'CKCC Renewal Due Date', 'required' => false, 'type' => 'date',    'example' => '31/03/2025'],
            'ots_eligible'          => ['label' => 'OTS Eligible (Yes/No)', 'required' => false, 'type' => 'boolean', 'example' => 'Yes'],
            'krm_eligible'          => ['label' => 'KRM Eligible (Yes/No)', 'required' => false, 'type' => 'boolean', 'example' => 'Yes'],
            'ots_amount'            => ['label' => 'OTS Amount',            'required' => false, 'type' => 'amount',  'example' => '56250.00'],
            'deposit_amount'        => ['label' => 'Deposit Amount',        'required' => false, 'type' => 'amount',  'example' => '5625.00'],
            'closure_amount'        => ['label' => 'Closure Amount',        'required' => false, 'type' => 'amount',  'example' => '161500.00'],

            // The rest of what a core banking NPA / recovery statement carries. An
            // agent at a door is asked "how much, since when, what did I last pay,
            // what is held against it" - and every one of those answers used to sit
            // in a spreadsheet column the importer dropped on the floor.
            'asset_classification'  => ['label' => 'Asset Classification',  'required' => false, 'type' => 'text',    'example' => 'Doubtful 2'],
            'interest_rate'         => ['label' => 'Interest Rate (%)',     'required' => false, 'type' => 'amount',  'example' => '7.00'],
            'installment_amount'    => ['label' => 'Instalment / EMI',      'required' => false, 'type' => 'amount',  'example' => '12500.00'],
            'last_payment_date'     => ['label' => 'Last Payment Date',     'required' => false, 'type' => 'date',    'example' => '12/08/2024'],
            'last_payment_amount'   => ['label' => 'Last Payment Amount',   'required' => false, 'type' => 'amount',  'example' => '5000.00'],
            'days_past_due'         => ['label' => 'Days Past Due (DPD)',   'required' => false, 'type' => 'amount',  'example' => '412'],
            'security_value'        => ['label' => 'Security Value',        'required' => false, 'type' => 'amount',  'example' => '450000.00'],
            'guarantor_name'        => ['label' => 'Guarantor Name',        'required' => false, 'type' => 'name',    'example' => 'Mohan Lal'],
            'maturity_date'         => ['label' => 'Maturity Date',         'required' => false, 'type' => 'date',    'example' => '31/03/2027'],
            'purpose'               => ['label' => 'Purpose / Activity',    'required' => false, 'type' => 'text',    'example' => 'Wheat cultivation'],

            'remarks'               => ['label' => 'Remarks',               'required' => false, 'type' => 'text',    'example' => 'First default'],
        ];
    }

    /** @return list<string> */
    public static function required(): array
    {
        $required = [];
        foreach (self::fields() as $field => $meta) {
            if ($meta['required']) {
                $required[] = $field;
            }
        }
        return $required;
    }

    /**
     * Accepted spellings per field, written as human phrases rather than
     * pre-squashed strings so they stay readable and can be matched by token as
     * well as by exact text.
     *
     * Devanagari entries are here because normalisation is Unicode-aware; a
     * co-operative bank's branch file often has Hindi headings.
     *
     * @return array<string,list<string>>
     */
    public static function aliases(): array
    {
        return [
            'branch' => [
                'branch', 'branch name', 'branch code', 'br code', 'br', 'branch cd',
                'branch id', 'brn', 'brn code', 'base branch', 'sol id', 'solid',
                'शाखा', 'शाखा नाम', 'शाखा कोड',
            ],
            'bc_code' => [
                'bc code', 'bc', 'dc code', 'bc dc code', 'bc id', 'agent code',
                'bc name', 'bc agent', 'business correspondent', 'csp code', 'csp',
            ],
            'loan_account_number' => [
                'loan account number', 'loan account no', 'loan ac no', 'loan a/c no',
                'account number', 'account no', 'ac no', 'a/c no', 'acct no', 'acct number',
                'loan ac', 'loan no', 'acc no', 'loan account', 'loan id', 'account id',
                'agreement no', 'agreement number', 'loan acct no', 'accountnumber',
                'खाता संख्या', 'खाता क्रमांक', 'ऋण खाता', 'ऋण खाता संख्या',
            ],
            'cif_number' => [
                'cif number', 'cif no', 'cif', 'cif id', 'customer id', 'customer no',
                'customer number', 'cust id', 'cust no', 'ucic', 'ucic id',
            ],
            'customer_name' => [
                'customer name', 'name', 'borrower name', 'customer', 'borrower',
                'account name', 'name of borrower', 'name of customer', 'applicant name',
                'party name', 'a/c holder name', 'account holder name', 'holder name',
                'acct name', 'ac name', 'a/c name', 'cust name', 'customer full name',
                'नाम', 'ग्राहक नाम', 'ऋणी का नाम', 'खातेदार का नाम',
            ],
            'father_husband_name' => [
                'father husband name', 'father name', 'husband name', 'father/husband',
                'fathers name', 'guardian name', 'father or husband name',
                'father s/o', 'so wo', 's/o w/o', 'care of', 'co name',
                'पिता का नाम', 'पति का नाम', 'पिता पति का नाम',
            ],
            'mobile' => [
                'mobile', 'mobile no', 'mobile number', 'phone', 'phone no', 'contact',
                'contact no', 'contact number', 'cell no', 'cell', 'mob no', 'mob',
                'primary mobile', 'registered mobile', 'msisdn',
                'मोबाइल', 'मोबाइल नंबर', 'दूरभाष',
            ],
            'aadhaar' => [
                'aadhaar', 'aadhar', 'aadhaar no', 'aadhar no', 'aadhaar number',
                'aadhar number', 'uid', 'uid no', 'uidai', 'aadhaar id',
                'आधार', 'आधार संख्या', 'आधार नंबर',
            ],
            'village' => [
                'village', 'village name', 'place', 'city', 'town', 'gram', 'gram panchayat',
                'locality', 'area',
                'ग्राम', 'गाँव', 'गांव', 'शहर',
            ],
            'address' => [
                'address', 'full address', 'residential address', 'address line',
                'permanent address', 'communication address', 'addr',
                'पता', 'निवास स्थान',
            ],
            'loan_type' => [
                'loan type', 'product type', 'product', 'scheme name', 'scheme',
                'facility type', 'product name', 'loan scheme', 'account type',
                'facility', 'loan product', 'ऋण प्रकार', 'योजना',
            ],
            'outstanding_amount' => [
                'outstanding amount', 'outstanding', 'principal outstanding', 'balance',
                'outstanding balance', 'os amount', 'os', 'total outstanding',
                'ledger balance', 'book balance', 'closing balance', 'principal balance',
                'loan outstanding', 'total dues',
                // A core banking export's own facility-code shorthand for the combined
                // Cash Credit / Overdraft / Demand Loan outstanding figure - not an
                // English phrase, so no amount of paraphrasing "outstanding" would ever
                // have matched it. Seen verbatim on a real bank's statement.
                'cc od dl', 'cc/od/dl', 'ccoddl', 'cc od dl os', 'cc od dl outstanding',
                'बकाया', 'बकाया राशि', 'शेष राशि',
            ],
            'overdue_amount' => [
                'overdue amount', 'overdue', 'od amount', 'arrears', 'overdue amt',
                'due amount', 'amount overdue', 'total overdue', 'installment overdue',
                'emi overdue', 'past due', 'overdue principal',
                'अतिदेय', 'अतिदेय राशि',
            ],
            'interest_overdue' => [
                'interest overdue', 'overdue interest', 'interest due', 'unpaid interest',
                'interest arrears', 'int overdue', 'int due', 'accrued interest',
                'ब्याज बकाया',
            ],
            'sanction_limit' => [
                'sanction limit', 'sanctioned limit', 'limit', 'sanction amount',
                'sanctioned amount', 'loan amount', 'credit limit', 'kcc limit',
                'sanc amt', 'sanc amount', 'sanctioned amt', 'sanc limit', 'limit amount',
                'स्वीकृत सीमा', 'ऋण राशि',
            ],
            'drawing_power' => [
                'drawing power', 'dp', 'dp amount', 'drawing limit', 'available dp',
            ],
            'npa_date' => [
                'npa date', 'npa dt', 'date of npa', 'npa since', 'npa classification date',
                'npa as on', 'date of npa classification', 'npa date of classification',
                'एनपीए दिनांक',
            ],
            'sanction_date' => [
                'sanction date', 'sanctioned date', 'date of sanction', 'disbursement date',
                'disbursal date', 'loan date', 'opening date', 'account opening date',
                'स्वीकृति दिनांक',
            ],
            'ckcc_renewal_due_date' => [
                'ckcc renewal due date', 'renewal due date', 'renewal date', 'due date of renewal',
                'next renewal date', 'kcc renewal date', 'review due date', 'expiry date',
                'नवीनीकरण तिथि',
            ],
            // The branch's own settlement decision, carried in the file.
            // "OTS" alone means the eligibility flag; "OTS Amount" is the figure.
            // Both are exact matches, so neither can steal the other's column.
            'ots_eligible' => [
                'ots eligible', 'ots eligible yes no', 'eligible for ots', 'ots eligibility',
                'ots', 'ots y n', 'ots applicable', 'eligible ots', 'ots flag', 'ots status',
                'ओटीएस पात्र',
            ],
            'krm_eligible' => [
                'krm eligible', 'krm eligible yes no', 'eligible for krm', 'krm eligibility',
                'krm', 'krm y n', 'krm applicable', 'eligible krm', 'krm ots eligible', 'krm flag',
            ],
            'ots_amount' => [
                'ots amount', 'ots settlement amount', 'total ots settlement amount',
                'settlement amount', 'total ots amount', 'proposed ots amount', 'ots value',
                'ots figure', 'ots settlement', 'compromise amount',
                'ओटीएस राशि',
            ],
            'deposit_amount' => [
                'deposit amount', 'initial deposit', 'initial deposit amount', 'deposit',
                'required initial deposit', 'required deposit amount', 'upfront deposit',
                'down payment', 'advance deposit', 'first installment',
                'जमा राशि',
            ],
            'closure_amount' => [
                'closure amount', 'closing amount', 'account closure amount',
                'total closure amount', 'amount to close', 'closure figure',
                'full closure amount', 'payoff amount', 'foreclosure amount',
                'total payable', 'total payable amount', 'amount payable to close',
                'बंद करने की राशि', 'कुल देय राशि',
            ],
            'asset_classification' => [
                'asset classification', 'asset class', 'classification', 'npa category',
                'npa class', 'npa classification', 'category', 'asset category',
                'iracp classification', 'irac status', 'irac', 'asset status',
                'account classification', 'npa grade', 'dpd bucket', 'bucket',
                'sma category', 'sma status',
                'परिसंपत्ति वर्गीकरण', 'एनपीए श्रेणी',
            ],
            'interest_rate' => [
                'interest rate', 'rate of interest', 'roi', 'int rate',
                'applicable rate', 'current roi', 'interest per annum', 'rate pa',
                'ब्याज दर',
            ],
            'installment_amount' => [
                'instalment emi', 'instalment', 'installment', 'installment amount',
                'instalment amount', 'emi', 'emi amount', 'monthly instalment',
                'monthly installment', 'repayment amount', 'instalment due',
                'किस्त', 'मासिक किस्त',
            ],
            'last_payment_date' => [
                'last payment date', 'last paid date', 'date of last payment',
                'last credit date', 'last repayment date', 'last deposit date',
                'last transaction date', 'last recovery date', 'lpd',
                'अंतिम भुगतान तिथि',
            ],
            'last_payment_amount' => [
                'last payment amount', 'last paid amount', 'amount of last payment',
                'last credit amount', 'last repayment amount', 'last recovery amount',
                'अंतिम भुगतान राशि',
            ],
            'days_past_due' => [
                'days past due', 'dpd', 'dpd days', 'days overdue', 'no of days overdue',
                'number of days overdue', 'overdue days', 'age of overdue', 'ageing days',
                'ageing', 'aging', 'days in default',
                'अतिदेय दिन',
            ],
            'security_value' => [
                'security value', 'value of security', 'collateral value',
                'value of collateral', 'security amount', 'realisable value',
                'realizable value', 'market value of security', 'mortgage value',
                'प्रतिभूति मूल्य',
            ],
            'guarantor_name' => [
                'guarantor name', 'guarantor', 'name of guarantor', 'surety name',
                'surety', 'co obligant', 'co applicant name', 'co borrower name',
                'जमानतदार', 'गारंटर',
            ],
            'maturity_date' => [
                'maturity date', 'date of maturity', 'due date of loan',
                'loan maturity date', 'repayment due date', 'final due date',
                'loan closure date', 'loan end date',
                'परिपक्वता तिथि',
            ],
            'purpose' => [
                'purpose', 'purpose of loan', 'loan purpose', 'activity', 'crop',
                'crop name', 'activity type', 'end use', 'purpose activity',
                'उद्देश्य', 'फसल',
            ],
            'remarks' => [
                'remarks', 'remark', 'comments', 'comment', 'notes', 'note', 'observation',
                'narration', 'description', 'टिप्पणी',
            ],
        ];
    }

    /**
     * Squashes a heading to its comparable form: lower case, letters and digits
     * only. "Loan A/C No." and "loan_ac_no" both become "loanacno".
     *
     * Unicode-aware on purpose - the old version stripped anything outside
     * [a-z0-9], which reduced every Devanagari heading to an empty string and
     * made Hindi files impossible to map.
     */
    public static function normalise(string $heading): string
    {
        $lower = mb_strtolower(trim($heading), 'UTF-8');
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);
    }

    /**
     * Splits a heading into comparable words.
     *
     * @return list<string>
     */
    public static function tokens(string $heading): array
    {
        $lower = mb_strtolower(trim($heading), 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : array_values($parts);
    }

    /**
     * How strongly one heading matches one field, 0..100.
     *
     * The ladder is ordered by how much evidence each rung actually carries, so a
     * loose match can never outrank a precise one:
     *   100  the heading IS one of the accepted spellings
     *    88  every word of an alias appears in the heading ("no. of account")
     *  60-85 an alias of real length appears inside the heading, scaled by how
     *        much of the heading it accounts for
     *  55-85 close enough by edit distance to be a typo or a local abbreviation
     */
    public static function scoreHeading(string $heading, string $field): int
    {
        $normalised = self::normalise($heading);
        if ($normalised === '') {
            return 0;
        }

        $aliases = self::aliases()[$field] ?? [];
        $headingTokens = self::tokens($heading);
        $best = 0;

        foreach ($aliases as $alias) {
            $normalisedAlias = self::normalise($alias);
            if ($normalisedAlias === '') {
                continue;
            }

            if ($normalised === $normalisedAlias) {
                return 100;
            }

            $aliasTokens = self::tokens($alias);
            if (count($aliasTokens) > 1 && $aliasTokens === array_intersect($aliasTokens, $headingTokens)) {
                $best = max($best, 88);
                continue;
            }

            $aliasLength = mb_strlen($normalisedAlias);
            if ($aliasLength >= 5 && str_contains($normalised, $normalisedAlias)) {
                $coverage = $aliasLength / max(1, mb_strlen($normalised));
                $best = max($best, (int) round(60 + 25 * min(1.0, $coverage)));
                continue;
            }

            // Typos and local abbreviations. Only worth trying on comparable
            // lengths, otherwise "os" is "close" to half the vocabulary.
            if ($aliasLength >= 4 && abs($aliasLength - mb_strlen($normalised)) <= 3) {
                $distance = levenshtein($normalised, $normalisedAlias);
                $ratio = 1 - ($distance / max($aliasLength, mb_strlen($normalised)));

                // Two edits on a word of six or more letters is the ordinary
                // typing mistake - "Brnach" is a transposition, which costs two
                // edits and so scores only 0.67 on ratio alone.
                if ($ratio >= 0.85 || ($aliasLength >= 6 && $distance <= 2)) {
                    $best = max($best, (int) round(55 + 30 * $ratio));
                }
            }
        }

        return min(100, $best);
    }

    /**
     * How much a row looks like a row of column headings.
     *
     * Used to find the header row and to choose between worksheets. Counting
     * recognised headings is the whole idea: a title row, a "Branch: BR001 / As
     * on: 31.03.2024" subtitle row and a row of data all fail it, and those are
     * exactly the rows that a "first row with two or more filled cells" rule
     * mistook for the header.
     *
     * @param list<string> $row
     */
    public static function headerScore(array $row): int
    {
        $fields = array_keys(self::fields());
        $recognised = 0;
        $dataLooking = 0;
        $filled = 0;

        foreach ($row as $cell) {
            $text = trim($cell);
            if ($text === '') {
                continue;
            }
            $filled++;

            // A column heading is a label, not a sentence. Without this guard the
            // title row "NPA STATEMENT AS ON 31.03.2024" scores as a recognised
            // heading, because it happens to contain every word of the "npa as on"
            // spelling of the NPA date column.
            if (count(self::tokens($text)) > 6) {
                continue;
            }

            $bestForCell = 0;
            foreach ($fields as $field) {
                $bestForCell = max($bestForCell, self::scoreHeading($text, $field));
                if ($bestForCell === 100) {
                    break;
                }
            }

            if ($bestForCell >= self::HEADER_CONFIDENT) {
                $recognised++;
                continue;
            }

            // Headings are words. A number, a date or a rupee figure in this row
            // means we are looking at data, not at headings.
            if (is_numeric(str_replace([',', ' ', '\u{20B9}'], '', $text))
                || preg_match('#^\d{1,4}[-/.]\d{1,2}[-/.]\d{1,4}$#', $text) === 1
            ) {
                $dataLooking++;
            }
        }

        if ($filled === 0) {
            return 0;
        }

        return max(0, $recognised * 10 - $dataLooking * 6);
    }

    /**
     * Proposes a column for each field.
     *
     * @param list<string>       $headings
     * @param list<list<string>> $rows       data rows, for value-based inference
     * @param array<string,int>  $overrides  field => column index, forced by the user
     *
     * @return array{
     *   map:array<string,int>,
     *   confidence:array<string,int>,
     *   source:array<string,string>,
     *   unmapped:array<int,string>,
     *   missing_required:list<string>
     * }
     */
    public static function detect(array $headings, array $rows = [], array $overrides = []): array
    {
        $fields = self::fields();

        // ---------------------------------------------------------------
        // Stage 1: header text, assigned globally by best score.
        // ---------------------------------------------------------------
        $candidates = [];
        foreach (array_keys($fields) as $field) {
            foreach ($headings as $index => $heading) {
                $score = self::scoreHeading($heading, $field);
                if ($score >= self::HEADER_THRESHOLD) {
                    $candidates[] = ['field' => $field, 'index' => $index, 'score' => $score];
                }
            }
        }

        // Highest score first; ties resolved by the earlier column, which keeps
        // the outcome stable rather than dependent on iteration order.
        usort($candidates, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index'];
        });

        $map = [];
        $confidence = [];
        $source = [];
        $claimed = [];

        foreach ($candidates as $candidate) {
            if (isset($map[$candidate['field']]) || isset($claimed[$candidate['index']])) {
                continue;
            }
            $map[$candidate['field']] = $candidate['index'];
            $confidence[$candidate['field']] = $candidate['score'];
            $source[$candidate['field']] = 'header';
            $claimed[$candidate['index']] = true;
        }

        // ---------------------------------------------------------------
        // Stage 2: values, for shapes that cannot be mistaken.
        // ---------------------------------------------------------------
        $samples = self::columnSamples($headings, $rows);

        // Most specific shape first: an Aadhaar column would also satisfy the
        // looser "account number" profile, so it has to be claimed before it.
        foreach (['aadhaar', 'mobile', 'loan_account_number', 'customer_name'] as $field) {
            if (isset($map[$field])) {
                continue;
            }

            $bestIndex = null;
            $bestRatio = 0.0;
            foreach ($samples as $index => $values) {
                if (isset($claimed[$index]) || $values === []) {
                    continue;
                }
                $ratio = self::profileMatch($fields[$field]['type'], $values);
                if ($ratio >= self::VALUE_THRESHOLD && $ratio > $bestRatio) {
                    $bestRatio = $ratio;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex !== null) {
                $map[$field] = $bestIndex;
                // Deliberately capped below a header match: this is a shape, not
                // a name, and the screen should show it as the weaker evidence it
                // is so the operator actually looks at it.
                $confidence[$field] = (int) round(40 + 35 * $bestRatio);
                $source[$field] = 'values';
                $claimed[$bestIndex] = true;
            }
        }

        // ---------------------------------------------------------------
        // Stage 3: whatever the operator says, wins.
        // ---------------------------------------------------------------
        foreach ($overrides as $field => $index) {
            if (!isset($fields[$field])) {
                continue;
            }
            // -1 means "ignore this field", which is how an operator rejects a
            // wrong guess without having to pick something else.
            if ($index < 0) {
                unset($map[$field], $confidence[$field], $source[$field]);
                continue;
            }
            if (!isset($headings[$index])) {
                continue;
            }
            foreach ($map as $otherField => $otherIndex) {
                if ($otherIndex === $index && $otherField !== $field) {
                    unset($map[$otherField], $confidence[$otherField], $source[$otherField]);
                }
            }
            $map[$field] = $index;
            $confidence[$field] = 100;
            $source[$field] = 'chosen';
        }

        $used = array_values($map);
        $unmapped = [];
        foreach ($headings as $index => $heading) {
            if (trim($heading) !== '' && !in_array($index, $used, true)) {
                $unmapped[$index] = trim($heading);
            }
        }

        return [
            'map'              => $map,
            'confidence'       => $confidence,
            'source'           => $source,
            'unmapped'         => $unmapped,
            'missing_required' => array_values(array_diff(self::required(), array_keys($map))),
        ];
    }

    /**
     * Non-empty sample values per column.
     *
     * @param list<string>       $headings
     * @param list<list<string>> $rows
     *
     * @return array<int,list<string>>
     */
    public static function columnSamples(array $headings, array $rows): array
    {
        $samples = [];
        $seen = 0;

        foreach ($rows as $row) {
            foreach (array_keys($headings) as $index) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value !== '') {
                    $samples[$index][] = $value;
                }
            }
            if (++$seen >= self::SAMPLE_ROWS) {
                break;
            }
        }

        return $samples;
    }

    /**
     * What fraction of a column's values fit a shape, 0..1.
     *
     * @param list<string> $values
     */
    public static function profileMatch(string $type, array $values): float
    {
        $total = count($values);
        if ($total === 0) {
            return 0.0;
        }

        $hits = 0;
        foreach ($values as $value) {
            $hits += match ($type) {
                'aadhaar' => self::looksLikeAadhaar($value) ? 1 : 0,
                'mobile'  => self::looksLikeMobile($value) ? 1 : 0,
                'account' => self::looksLikeAccount($value) ? 1 : 0,
                'name'    => self::looksLikeName($value) ? 1 : 0,
                default   => 0,
            };
        }

        $ratio = $hits / $total;

        // An identifier column is also nearly unique. Without this, a "Loan Type"
        // column repeating "KCC" 400 times could pass the account-number shape.
        if ($type === 'account' && $ratio > 0.0) {
            $distinct = count(array_unique($values)) / $total;
            if ($distinct < 0.85) {
                return 0.0;
            }
        }

        return $ratio;
    }

    private static function digitsOnly(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private static function looksLikeAadhaar(string $value): bool
    {
        $digits = self::digitsOnly($value);
        // Aadhaar never starts with 0 or 1.
        return strlen($digits) === 12 && $digits[0] >= '2';
    }

    private static function looksLikeMobile(string $value): bool
    {
        $digits = self::digitsOnly($value);
        $length = strlen($digits);

        if ($length === 10) {
            return $digits[0] >= '6';
        }
        // 91XXXXXXXXXX / 091XXXXXXXXXX as exported by some core systems.
        if (($length === 12 || $length === 13) && str_starts_with(ltrim($digits, '0'), '91')) {
            $local = substr(ltrim($digits, '0'), 2);
            return strlen($local) === 10 && $local[0] >= '6';
        }

        return false;
    }

    private static function looksLikeAccount(string $value): bool
    {
        $length = mb_strlen($value);
        if ($length < 6 || $length > 30) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9\/\-]+$/', $value) !== 1) {
            return false;
        }
        // At least four digits: enough to be an identifier rather than a word.
        return strlen(self::digitsOnly($value)) >= 4
            && !self::looksLikeAadhaar($value)
            && !self::looksLikeMobile($value);
    }

    private static function looksLikeName(string $value): bool
    {
        $length = mb_strlen($value);
        if ($length < 3 || $length > 80) {
            return false;
        }
        if (self::digitsOnly($value) !== '') {
            return false;
        }
        // Letters, spaces and the punctuation that appears in Indian names.
        return preg_match('/^[\p{L}\p{M}\s.\'()\-]+$/u', $value) === 1;
    }
}
