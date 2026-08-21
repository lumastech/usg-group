<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Import\ImportReconciliation;
use App\Domain\Import\WorkbookImporter;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings the year the group kept in a spreadsheet into the ledgers.
 *
 * The dry run is the normal way to use this and prints exactly what the real run would
 * post, entry by entry. The real run is idempotent on the natural key (member, month,
 * kind), so it can be run again after the treasurer fills in a missing row and it will
 * post that row and nothing else.
 *
 * It always finishes on a reconciliation: the workbook's own totals set against what
 * the ledgers now hold, with every discrepancy named.
 */
class ImportWorkbook extends Command
{
    protected $signature = 'unity:import-workbook
        {file : Path to the group workbook (.xlsx)}
        {--cycle= : Cycle id to import into, defaulting to the current cycle}
        {--actor= : Member id to record the entries against, defaulting to the first committee member}
        {--dry-run : Show what would be posted without writing anything}';

    protected $description = 'Import the group workbook as historical transactions';

    public function handle(
        CurrentCycle $currentCycle,
        WorkbookImporter $importer,
        ImportReconciliation $reconciliation,
    ): int {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to import into. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $currentCycle->set($cycle);
        $path = (string) $this->argument('file');

        try {
            $plan = $importer->plan($cycle, $path);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportPlan($plan);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Dry run — nothing was written. Run again without --dry-run to post.');

            $this->reportReconciliation($reconciliation->for($cycle, $plan['workbook_totals']));

            return self::SUCCESS;
        }

        $actor = $this->resolveActor($cycle);

        if (! $actor instanceof Member) {
            $this->components->error('No member to record the import against. Pass --actor.');

            return self::FAILURE;
        }

        $result = $importer->import($cycle, $path, $actor);

        $this->newLine();
        $this->components->twoColumnDetail('Posted', (string) $result['posted']);
        $this->components->twoColumnDetail('Already present', (string) $result['skipped']);
        $this->components->twoColumnDetail('Failed', (string) count($result['failed']));

        if ($result['failed'] !== []) {
            $this->newLine();
            $this->table(
                ['Entry', 'Member', 'Why it could not be posted'],
                array_map(fn (array $row): array => [$row['key'], $row['member'], $row['reason']], $result['failed']),
            );
        }

        $report = $reconciliation->for($cycle, $plan['workbook_totals']);
        $this->reportReconciliation($report);

        return $report['balanced'] && $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function reportPlan(array $plan): void
    {
        $this->components->info('What the workbook holds');

        foreach ($plan['summary'] as $kind => $counts) {
            $this->components->twoColumnDetail(
                str($kind)->replace('_', ' ')->ucfirst()->toString(),
                "{$counts['planned']} to post · {$counts['already_posted']} already there · "
                .Kwacha::format($counts['amount_ngwee']),
            );
        }

        if ($plan['warnings'] !== []) {
            $this->newLine();
            $this->components->warn(count($plan['warnings']).' row(s) could not be resolved:');

            foreach ($plan['warnings'] as $warning) {
                $this->line("  · {$warning}");
            }
        }

        $pending = array_values(array_filter(
            $plan['entries'],
            fn (array $entry): bool => ! $entry['already_posted'],
        ));

        if ($pending !== [] && $this->option('dry-run')) {
            $this->newLine();
            $this->table(
                ['Kind', '#', 'Member', 'Month', 'Amount'],
                array_map(fn (array $entry): array => [
                    $entry['kind'],
                    $entry['member_number'],
                    $entry['member_name'],
                    $entry['month_label'],
                    Kwacha::format($entry['amount_ngwee']),
                ], $pending),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function reportReconciliation(array $report): void
    {
        $this->newLine();
        $this->components->info('Reconciliation — workbook against the ledgers');

        $this->table(
            ['Line', 'Workbook', 'Ledgers', 'Difference', 'Status'],
            array_map(fn (array $line): array => [
                $line['label'],
                Kwacha::format($line['workbook_ngwee']),
                Kwacha::format($line['ledger_ngwee']),
                Kwacha::format($line['difference_ngwee']),
                $line['advisory'] ? 'advisory' : ($line['balanced'] ? 'balanced' : 'DISCREPANCY'),
            ], $report['lines']),
        );

        if (! $report['balanced']) {
            $this->components->error(
                $report['discrepancy_count'].' line(s) do not tie out. The workbook and the ledgers disagree.'
            );

            return;
        }

        $this->components->info('The workbook and the ledgers agree.');
    }

    /** Imports are recorded against a real committee member, never against nobody. */
    protected function resolveActor(Cycle $cycle): ?Member
    {
        if ($this->option('actor') !== null) {
            return Member::query()->acrossCycles()->find($this->option('actor'))?->load('user');
        }

        return $cycle->members()
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->first(fn (Member $member): bool => $member->isCommitteeMember());
    }
}
