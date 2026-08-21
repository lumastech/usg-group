<?php

namespace App\Domain\Import;

use App\Domain\Loans\LoanLedger;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\DeclarationStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\SavingsTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Enums\TransactionSource;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

/**
 * Brings the year the group kept by hand into the ledgers.
 *
 * The workbook is history, not a live source: everything it holds is posted once, as
 * an import-flagged entry, and from then on the ledgers are the truth. That is why the
 * plan is built first and shown before anything is written — a dry run is the normal
 * way to use this, and the confirmation is a second, separate act.
 *
 * Idempotency is by natural key (member + month + kind), read off the ledgers
 * themselves rather than from an import log. Running the import twice therefore finds
 * every entry already there and posts nothing, and a workbook that has grown three new
 * rows since the last run posts exactly those three.
 *
 * Interest is deliberately NOT imported. It is a pooled pro-rata split the application
 * computes from the savings it now holds, so importing the workbook's interest column
 * would post the same money twice — once as history and once when the month closes. The
 * column is still read, and the reconciliation reports it against what the app derives.
 */
class WorkbookImporter
{
    public const KIND_SAVINGS = 'savings';

    public const KIND_LOAN_DISBURSEMENT = 'loan_disbursement';

    public const KIND_LOAN_REPAYMENT = 'loan_repayment';

    public const KIND_SOCIAL_FUND = 'social_fund';

    public const KIND_DECLARATION = 'declaration';

    public function __construct(
        protected WorkbookReader $reader,
        protected SavingsLedger $savings,
        protected SocialFundLedger $fund,
        protected LoanLedger $loans,
    ) {}

    /**
     * Reads the workbook and works out what is missing from the ledgers.
     *
     * Nothing is written. Warnings name every row that could not be resolved — an
     * unknown member, a month outside the cycle — because a silent skip in a financial
     * import is how a member ends up short at share-out.
     *
     * @return array{
     *     entries: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     workbook_totals: array<string, int>,
     *     summary: array<string, array{planned: int, already_posted: int, amount_ngwee: int}>,
     * }
     */
    public function plan(Cycle $cycle, string $path): array
    {
        $sheets = $this->reader->sheets($path);
        $members = $this->membersByKey($cycle);
        $months = $cycle->months()->get();

        $warnings = [];
        $entries = [];
        $workbookTotals = [];

        foreach ($this->parsers() as $kind => $parser) {
            [$parsed, $sheetWarnings, $total] = $this->{$parser}($sheets, $members, $months, $cycle);

            $entries = [...$entries, ...$parsed];
            $warnings = [...$warnings, ...$sheetWarnings];
            $workbookTotals[$kind] = $total;
        }

        return [
            'entries' => array_map(fn (PlannedEntry $entry): array => $entry->toArray(), $entries),
            'warnings' => $warnings,
            'workbook_totals' => $workbookTotals,
            'summary' => $this->summarise($entries),
        ];
    }

    /**
     * Posts everything the plan found missing.
     *
     * Each entry is posted through the module that owns it — savings through
     * SavingsLedger, the fund through SocialFundLedger, loans through LoanLedger — so
     * an imported figure is subject to the same immutability and freeze rules as one
     * captured at the table. A member whose ledgers are already frozen is reported
     * rather than forced.
     *
     * @return array{
     *     posted: int,
     *     skipped: int,
     *     failed: array<int, array{key: string, member: string, reason: string}>,
     *     posted_ngwee: array<string, int>,
     * }
     */
    public function import(Cycle $cycle, string $path, Member $actor): array
    {
        $plan = $this->plan($cycle, $path);

        $posted = 0;
        $skipped = 0;
        $failed = [];
        $postedNgwee = [];

        foreach ($plan['entries'] as $row) {
            if ($row['already_posted']) {
                $skipped++;

                continue;
            }

            try {
                $this->post($cycle, $row, $actor);
            } catch (Throwable $exception) {
                $failed[] = [
                    'key' => $row['key'],
                    'member' => $row['member_name'],
                    'reason' => $exception->getMessage(),
                ];

                continue;
            }

            $posted++;
            $postedNgwee[$row['kind']] = ($postedNgwee[$row['kind']] ?? 0) + $row['amount_ngwee'];
        }

        return [
            'posted' => $posted,
            'skipped' => $skipped,
            'failed' => $failed,
            'posted_ngwee' => $postedNgwee,
        ];
    }

    /** @return array<string, string> kind => parser method */
    protected function parsers(): array
    {
        return [
            self::KIND_SAVINGS => 'parseSavings',
            self::KIND_LOAN_DISBURSEMENT => 'parseLoans',
            self::KIND_SOCIAL_FUND => 'parseSocialFund',
            self::KIND_DECLARATION => 'parseDeclarations',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function post(Cycle $cycle, array $row, Member $actor): void
    {
        $member = Member::query()->acrossCycles()->findOrFail($row['member_id']);
        $month = $row['cycle_month_id'] === null
            ? null
            : CycleMonth::query()->acrossCycles()->find($row['cycle_month_id']);

        match ($row['kind']) {
            self::KIND_SAVINGS => $this->savings->record(
                $member,
                $month,
                Kwacha::ofNgwee($row['amount_ngwee']),
                $actor,
                SavingsTransactionType::ImportOpening,
                source: TransactionSource::Import,
                occurredOn: $month->month,
            ),
            self::KIND_SOCIAL_FUND => $this->fund->post(
                $cycle,
                SocialFundTransactionType::Contribution,
                Kwacha::ofNgwee($row['amount_ngwee']),
                $month?->month ?? $cycle->starts_on,
                $member,
                $actor,
                note: 'Imported from the group workbook',
            ),
            self::KIND_LOAN_DISBURSEMENT => $this->postDisbursement($cycle, $member, $month, $row, $actor),
            self::KIND_LOAN_REPAYMENT => $this->postRepayment($member, $month, $row, $actor),
            self::KIND_DECLARATION => $this->postDeclaration($cycle, $member, $month, $row, $actor),
            default => null,
        };
    }

    /** A loan the group made before the app existed, recorded as one already disbursed. */
    protected function postDisbursement(Cycle $cycle, Member $member, CycleMonth $month, array $row, Member $actor): void
    {
        $loan = Loan::create([
            'cycle_id' => $cycle->id,
            'member_id' => $member->id,
            'principal_ngwee' => Kwacha::ofNgwee($row['amount_ngwee']),
            'tenor_months' => 1,
            'status' => LoanStatus::Disbursed,
            'requested_at' => $month->month,
            'disbursed_at' => $month->month,
            'disbursement_cycle_month_id' => $month->id,
            'current_balance_ngwee' => Kwacha::zero(),
        ]);

        $this->loans->post(
            $loan,
            LoanTransactionType::Disbursement,
            Kwacha::ofNgwee($row['amount_ngwee']),
            $month->month,
            $month,
            $actor,
            notes: 'Imported from the group workbook',
        );
    }

    /**
     * A repayment recorded against the member's oldest loan that still has a balance.
     *
     * The workbook records what was paid in a month, not which loan it was paid
     * against, so oldest-first is the convention — the same order the trading table
     * clears them in.
     */
    protected function postRepayment(Member $member, CycleMonth $month, array $row, Member $actor): void
    {
        $loan = Loan::query()
            ->acrossCycles()
            ->where('member_id', $member->id)
            ->whereIn('status', LoanStatus::outstanding())
            ->orderBy('id')
            ->first();

        if ($loan === null) {
            throw new \RuntimeException(
                "{$member->full_name} has a repayment in {$month->label()} but no loan to post it against."
            );
        }

        $this->loans->post(
            $loan,
            LoanTransactionType::Repayment,
            Kwacha::ofNgwee($row['amount_ngwee']),
            $month->month,
            $month,
            $actor,
            portions: ['principal' => $row['amount_ngwee']],
            notes: 'Imported from the group workbook',
        );
    }

    /** A declaration is a promise the month already kept, so it is imported settled. */
    protected function postDeclaration(Cycle $cycle, Member $member, CycleMonth $month, array $row, Member $actor): void
    {
        $repayment = $row['extra']['repayment_ngwee'] ?? 0;
        $requested = $row['extra']['loan_requested_ngwee'] ?? 0;

        Declaration::create([
            'cycle_id' => $cycle->id,
            'cycle_month_id' => $month->id,
            'member_id' => $member->id,
            'saving_amount_ngwee' => Kwacha::ofNgwee($row['amount_ngwee']),
            'loan_repayment_amount_ngwee' => Kwacha::ofNgwee($repayment),
            'loan_requested_amount_ngwee' => Kwacha::ofNgwee($requested),
            'total_expected_payment_ngwee' => Kwacha::ofNgwee($row['amount_ngwee'] + $repayment),
            'submitted_at' => $month->declarations_close_at,
            'is_late' => false,
            'status' => DeclarationStatus::Submitted,
            'recorded_by_member_id' => $actor->id,
            'note' => 'Imported from the group workbook',
        ]);
    }

    /**
     * The SAVINGS sheet: a Savings and an Interest column under every month.
     *
     * Only the savings half is planned. The interest half is totalled and handed back
     * for the reconciliation, which is where the workbook's figure is set beside the
     * one the application derives.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @param  array<string, Member>  $members
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @return array{0: array<int, PlannedEntry>, 1: array<int, string>, 2: int}
     */
    protected function parseSavings(array $sheets, array $members, EloquentCollection $months, Cycle $cycle): array
    {
        return $this->parseMonthGrid(
            $sheets, $members, $months, 'SAVINGS', self::KIND_SAVINGS,
            ['savings', 'saving', 'sav'],
            fn (int $memberId, ?int $monthId): bool => SavingsTransaction::query()
                ->where('member_id', $memberId)
                ->where('cycle_month_id', $monthId)
                ->where('type', SavingsTransactionType::ImportOpening->value)
                ->exists(),
        );
    }

    /**
     * The LOANS sheet: what was borrowed and what was repaid in each month.
     *
     * @return array{0: array<int, PlannedEntry>, 1: array<int, string>, 2: int}
     */
    protected function parseLoans(array $sheets, array $members, EloquentCollection $months, Cycle $cycle): array
    {
        [$borrowed, $borrowWarnings, $borrowTotal] = $this->parseMonthGrid(
            $sheets, $members, $months, 'LOANS', self::KIND_LOAN_DISBURSEMENT,
            ['loan', 'borrowed', 'disbursed', 'principal'],
            fn (int $memberId, ?int $monthId): bool => $this->loanEntryExists($memberId, $monthId, LoanTransactionType::Disbursement),
        );

        [$repaid, $repayWarnings, $repayTotal] = $this->parseMonthGrid(
            $sheets, $members, $months, 'LOANS', self::KIND_LOAN_REPAYMENT,
            ['repayment', 'repaid', 'paid'],
            fn (int $memberId, ?int $monthId): bool => $this->loanEntryExists($memberId, $monthId, LoanTransactionType::Repayment),
        );

        /* Disbursements must land before the repayments that clear them. */
        return [[...$borrowed, ...$repaid], [...$borrowWarnings, ...$repayWarnings], $borrowTotal + $repayTotal];
    }

    /**
     * The SOCIAL FUND sheet: the one-off K250 contribution, per member.
     *
     * @return array{0: array<int, PlannedEntry>, 1: array<int, string>, 2: int}
     */
    protected function parseSocialFund(array $sheets, array $members, EloquentCollection $months, Cycle $cycle): array
    {
        $rows = $this->reader->find($sheets, 'SOCIAL FUND');

        if ($rows === null) {
            return [[], ['The workbook has no SOCIAL FUND sheet; the fund was not imported.'], 0];
        }

        $header = $this->reader->headerRow($rows);

        if ($header === null) {
            return [[], ['The SOCIAL FUND sheet has no member column; the fund was not imported.'], 0];
        }

        $amountColumn = $this->columnMatching($rows[$header], ['contribution', 'amount', 'paid', 'socialfund']);

        if ($amountColumn === null) {
            return [[], ['The SOCIAL FUND sheet has no contribution column; the fund was not imported.'], 0];
        }

        $month = $months->first();
        $entries = [];
        $warnings = [];
        $total = 0;

        foreach (array_slice($rows, $header + 1, preserve_keys: true) as $row) {
            $member = $this->resolveMember($row, $members);

            if ($member === null) {
                $this->noteUnresolved($row, $warnings, 'SOCIAL FUND');

                continue;
            }

            $amount = $this->reader->ngwee($row[$amountColumn] ?? null);
            $total += $amount;

            if ($amount <= 0) {
                continue;
            }

            $entries[] = new PlannedEntry(
                self::KIND_SOCIAL_FUND,
                $member->id,
                $member->full_name,
                $member->member_number,
                $month?->id,
                $month?->label() ?? $cycle->name,
                $amount,
                SocialFundTransaction::query()
                    ->acrossCycles()
                    ->where('member_id', $member->id)
                    ->where('type', SocialFundTransactionType::Contribution->value)
                    ->exists(),
            );
        }

        return [$entries, $warnings, $total];
    }

    /**
     * The Declarations sheet: what each member promised for each month.
     *
     * @return array{0: array<int, PlannedEntry>, 1: array<int, string>, 2: int}
     */
    protected function parseDeclarations(array $sheets, array $members, EloquentCollection $months, Cycle $cycle): array
    {
        return $this->parseMonthGrid(
            $sheets, $members, $months, 'Declarations', self::KIND_DECLARATION,
            ['savings', 'saving', 'declared'],
            fn (int $memberId, ?int $monthId): bool => Declaration::query()
                ->acrossCycles()
                ->where('member_id', $memberId)
                ->where('cycle_month_id', $monthId)
                ->exists(),
        );
    }

    /**
     * The shape every sheet in this workbook shares: members down the side, months
     * across the top, and one or more named sub-columns under each month.
     *
     * The sub-column is found by name rather than by offset, because the workbook is
     * not consistent about how many columns a month occupies — SAVINGS has two, the
     * Declarations sheet has three, and a month with nothing in it sometimes has one.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @param  array<string, Member>  $members
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, string>  $wantedSubColumns
     * @param  callable(int, ?int): bool  $alreadyPosted
     * @return array{0: array<int, PlannedEntry>, 1: array<int, string>, 2: int}
     */
    protected function parseMonthGrid(
        array $sheets,
        array $members,
        EloquentCollection $months,
        string $sheetName,
        string $kind,
        array $wantedSubColumns,
        callable $alreadyPosted,
    ): array {
        $rows = $this->reader->find($sheets, $sheetName);

        if ($rows === null) {
            return [[], ["The workbook has no {$sheetName} sheet; nothing was imported from it."], 0];
        }

        $header = $this->reader->headerRow($rows);

        if ($header === null) {
            return [[], ["The {$sheetName} sheet has no member column; nothing was imported from it."], 0];
        }

        $columns = $this->monthColumns($rows, $header, $months, $wantedSubColumns);

        if ($columns === []) {
            return [[], ["No month columns were recognised on the {$sheetName} sheet."], 0];
        }

        $entries = [];
        $warnings = [];
        $total = 0;
        $firstDataRow = $header + $this->subHeaderDepth($rows, $header) + 1;

        foreach (array_slice($rows, $firstDataRow, preserve_keys: true) as $row) {
            $member = $this->resolveMember($row, $members);

            if ($member === null) {
                $this->noteUnresolved($row, $warnings, $sheetName);

                continue;
            }

            foreach ($columns as $monthId => $column) {
                $amount = $this->reader->ngwee($row[$column['index']] ?? null);
                $total += $amount;

                if ($amount <= 0) {
                    continue;
                }

                $entries[] = new PlannedEntry(
                    $kind,
                    $member->id,
                    $member->full_name,
                    $member->member_number,
                    $monthId,
                    $column['label'],
                    $amount,
                    $alreadyPosted($member->id, $monthId),
                    extra: $this->declarationExtras($kind, $rows, $header, $row, $column),
                );
            }
        }

        return [$entries, $warnings, $total];
    }

    /**
     * The repayment and loan-request figures that travel beside a declared saving.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>  $row
     * @param  array{index: int, label: string, span: array<int, int>}  $column
     * @return array<string, int>
     */
    protected function declarationExtras(string $kind, array $rows, int $header, array $row, array $column): array
    {
        if ($kind !== self::KIND_DECLARATION) {
            return [];
        }

        $sub = $rows[$header + 1] ?? [];

        $find = function (array $wanted) use ($sub, $column, $row): int {
            foreach ($column['span'] as $index) {
                if (in_array($this->reader->normalise((string) ($sub[$index] ?? '')), $wanted, true)) {
                    return $this->reader->ngwee($row[$index] ?? null);
                }
            }

            return 0;
        };

        return [
            'repayment_ngwee' => $find(['repayment', 'repaid', 'loanrepayment']),
            'loan_requested_ngwee' => $find(['loan', 'loanrequest', 'loanrequested', 'request']),
        ];
    }

    /**
     * Maps each cycle month to the column that holds the figure we want.
     *
     * A month heading may sit above a block of sub-columns; the block runs until the
     * next month heading, and the wanted sub-column is picked out of it by name. When
     * a month has no sub-headings at all, its own column is the figure.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, string>  $wanted
     * @return array<int, array{index: int, label: string, span: array<int, int>}>
     */
    protected function monthColumns(array $rows, int $header, EloquentCollection $months, array $wanted): array
    {
        $headerRow = $rows[$header] ?? [];
        $subRow = $rows[$header + 1] ?? [];
        $width = max(count($headerRow), count($subRow));

        /* Where each month heading starts, in column order. */
        $starts = [];

        for ($index = 0; $index < $width; $index++) {
            $month = $this->matchMonth((string) ($headerRow[$index] ?? ''), $months);

            if ($month !== null) {
                $starts[] = ['index' => $index, 'month' => $month];
            }
        }

        $columns = [];

        foreach ($starts as $position => $start) {
            $end = $starts[$position + 1]['index'] ?? $width;
            $span = range($start['index'], max($start['index'], $end - 1));

            $chosen = $start['index'];

            foreach ($span as $index) {
                if (in_array($this->reader->normalise((string) ($subRow[$index] ?? '')), $wanted, true)) {
                    $chosen = $index;

                    break;
                }
            }

            $columns[$start['month']->id] = [
                'index' => $chosen,
                'label' => $start['month']->label(),
                'span' => $span,
            ];
        }

        return $columns;
    }

    /**
     * The first column whose heading matches one of the given words.
     *
     * @param  array<int, mixed>  $headerRow
     * @param  array<int, string>  $wanted
     */
    protected function columnMatching(array $headerRow, array $wanted): ?int
    {
        foreach ($headerRow as $index => $cell) {
            $heading = $this->reader->normalise((string) $cell);

            foreach ($wanted as $needle) {
                if ($heading !== '' && str_contains($heading, $needle)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /** Whether the row under the headings is sub-headings rather than the first member. */
    protected function subHeaderDepth(array $rows, int $header): int
    {
        $sub = $rows[$header + 1] ?? [];

        foreach ($sub as $cell) {
            $value = $this->reader->normalise((string) $cell);

            if (in_array($value, ['savings', 'saving', 'sav', 'interest', 'int', 'loan', 'repayment', 'repaid', 'borrowed'], true)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * A month heading read back to a cycle month.
     *
     * The workbook writes them every way a person writes a month — "Dec", "Dec-25",
     * "December 2025" — so the match is on the month's own name, tightened with the
     * year when the heading carries one.
     *
     * @param  EloquentCollection<int, CycleMonth>  $months
     */
    protected function matchMonth(string $heading, EloquentCollection $months): ?CycleMonth
    {
        $normalised = $this->reader->normalise($heading);

        if ($normalised === '') {
            return null;
        }

        foreach ($months as $month) {
            $short = strtolower($month->month->format('M'));
            $long = strtolower($month->month->format('F'));
            $year = $month->month->format('Y');
            $shortYear = $month->month->format('y');

            if (! str_starts_with($normalised, $short) && ! str_starts_with($normalised, $long)) {
                continue;
            }

            $digits = preg_replace('/[^0-9]/', '', $normalised) ?? '';

            if ($digits === '' || $digits === $year || $digits === $shortYear) {
                return $month;
            }
        }

        return null;
    }

    /**
     * Members keyed both by number and by normalised name, so a row identifies itself
     * however the treasurer wrote it.
     *
     * @return array<string, Member>
     */
    protected function membersByKey(Cycle $cycle): array
    {
        $keyed = [];

        foreach ($cycle->members()->get() as $member) {
            $keyed['#'.$member->member_number] = $member;
            $keyed[$this->reader->normalise($member->full_name)] = $member;
        }

        return $keyed;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, Member>  $members
     */
    protected function resolveMember(array $row, array $members): ?Member
    {
        foreach ($row as $cell) {
            if ($cell === null || $cell === '') {
                continue;
            }

            $name = $this->reader->normalise((string) $cell);

            if ($name !== '' && isset($members[$name])) {
                return $members[$name];
            }

            if (is_numeric($cell) && isset($members['#'.(int) $cell])) {
                return $members['#'.(int) $cell];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<int, string>  $warnings
     */
    protected function noteUnresolved(array $row, array &$warnings, string $sheet): void
    {
        $label = trim(implode(' ', array_map(
            fn ($cell): string => (string) $cell,
            array_slice(array_filter($row, fn ($cell): bool => $cell !== null && $cell !== ''), 0, 2),
        )));

        if ($label === '' || $this->looksLikeTotalsRow($label)) {
            return;
        }

        $warnings[] = "{$sheet}: no member in this cycle matches \"{$label}\".";
    }

    /** The workbook's own totals rows are not members and must not be warned about. */
    protected function looksLikeTotalsRow(string $label): bool
    {
        $normalised = $this->reader->normalise($label);

        return str_contains($normalised, 'total') || str_contains($normalised, 'grand');
    }

    protected function loanEntryExists(int $memberId, ?int $monthId, LoanTransactionType $type): bool
    {
        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.member_id', $memberId)
            ->where('loan_transactions.cycle_month_id', $monthId)
            ->where('loan_transactions.type', $type->value)
            ->exists();
    }

    /**
     * @param  array<int, PlannedEntry>  $entries
     * @return array<string, array{planned: int, already_posted: int, amount_ngwee: int}>
     */
    protected function summarise(array $entries): array
    {
        $summary = [];

        foreach ($entries as $entry) {
            $summary[$entry->kind] ??= ['planned' => 0, 'already_posted' => 0, 'amount_ngwee' => 0];

            if ($entry->alreadyPosted) {
                $summary[$entry->kind]['already_posted']++;

                continue;
            }

            $summary[$entry->kind]['planned']++;
            $summary[$entry->kind]['amount_ngwee'] += $entry->amountNgwee;
        }

        return $summary;
    }
}
