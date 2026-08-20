<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Members\MembershipRegistrar;
use App\Enums\NextOfKinRelationship;
use App\Exceptions\DomainRuleException;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the commitment sheet into the register.
 *
 * The sheet is a CSV of the columns the group signed: name, NRC, address and one
 * next of kin. Rows already in the cycle (matched on NRC, else on name) are left
 * alone rather than duplicated, so a partial import can simply be re-run.
 *
 * Everyone on the sheet is a founding member, so they are registered as joining on
 * the cycle's first day whenever the import is actually run — importing in month
 * five must not charge the sheet's signatories the late registration fee.
 */
class ImportMembers extends Command
{
    protected $signature = 'unity:import-members {file : Path to the commitment sheet CSV}
        {--dry-run : Report what would be imported without writing anything}';

    protected $description = 'Import members into the current cycle from a commitment sheet CSV';

    /** Accepted header spellings, mapped to the field each one feeds. */
    protected const HEADERS = [
        'full_name' => ['full_name', 'name', 'member', 'member_name'],
        'nrc_number' => ['nrc_number', 'nrc', 'nrc_no'],
        'physical_address' => ['physical_address', 'address', 'residential_address'],
        'phone' => ['phone', 'phone_number', 'mobile', 'cell'],
        'next_of_kin_name' => ['next_of_kin_name', 'nok_name', 'next_of_kin'],
        'next_of_kin_phone' => ['next_of_kin_phone', 'nok_phone', 'next_of_kin_number'],
        'next_of_kin_relationship' => ['next_of_kin_relationship', 'nok_relationship', 'relationship'],
    ];

    public function handle(CurrentCycle $currentCycle, MembershipRegistrar $registrar): int
    {
        $cycle = $currentCycle->get();

        if ($cycle === null) {
            $this->components->error('No active cycle. Seed or activate one before importing.');

            return self::FAILURE;
        }

        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $rows = $this->rows($path);

        if ($rows === []) {
            $this->components->error('The file has no data rows, or its header row was not recognised.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $imported = $skipped = $failed = 0;

        DB::beginTransaction();

        foreach ($rows as $line => $row) {
            if ($this->alreadyRegistered($row)) {
                $this->components->twoColumnDetail($row['full_name'], '<fg=gray>already registered</>');
                $skipped++;

                continue;
            }

            try {
                $member = $registrar->register($cycle, $this->attributes($row), $cycle->starts_on);
                $this->components->twoColumnDetail($row['full_name'], "<fg=green>member #{$member->member_number}</>");
                $imported++;
            } catch (DomainRuleException $exception) {
                $this->components->twoColumnDetail(
                    "{$row['full_name']} (line {$line})",
                    "<fg=red>{$exception->getMessage()}</>",
                );
                $failed++;
            }
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->components->info(sprintf(
            '%s%d imported, %d already registered, %d failed.',
            $dryRun ? 'Dry run — nothing was written. ' : '',
            $imported,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Read the CSV into normalised rows keyed by line number.
     *
     * @return array<int, array<string, string|null>>
     */
    protected function rows(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $header = null;
        $rows = [];
        $line = 0;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // fgetcsv yields [null] for a blank line rather than an empty array.
            if ($values === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->mapHeader($values);

                continue;
            }

            $row = $this->row($header, $values);

            if (blank($row['full_name'] ?? null)) {
                continue;
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        return $header === null || ! in_array('full_name', $header, true) ? [] : $rows;
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string> Column index to field name.
     */
    protected function mapHeader(array $values): array
    {
        $header = [];

        foreach ($values as $index => $value) {
            $normalised = Str::of((string) $value)->lower()->replaceMatches('/[^a-z]+/', '_')->trim('_')->toString();

            foreach (self::HEADERS as $field => $spellings) {
                if (in_array($normalised, $spellings, true)) {
                    $header[$index] = $field;

                    break;
                }
            }
        }

        return $header;
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string|null>  $values
     * @return array<string, string|null>
     */
    protected function row(array $header, array $values): array
    {
        $row = [];

        foreach ($header as $index => $field) {
            $value = trim((string) ($values[$index] ?? ''));
            $row[$field] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    protected function attributes(array $row): array
    {
        return [
            'full_name' => $row['full_name'],
            'nrc_number' => $row['nrc_number'] ?? null,
            'physical_address' => $row['physical_address'] ?? null,
            'phone' => $row['phone'] ?? null,
            'next_of_kin' => blank($row['next_of_kin_name'] ?? null) ? [] : [[
                'name' => $row['next_of_kin_name'],
                'phone' => $row['next_of_kin_phone'] ?? null,
                'relationship' => NextOfKinRelationship::fromLabel($row['next_of_kin_relationship'] ?? null),
                'relationship_label' => $row['next_of_kin_relationship'] ?? null,
            ]],
        ];
    }

    /** @param  array<string, string|null>  $row */
    protected function alreadyRegistered(array $row): bool
    {
        return Member::query()
            ->when(
                filled($row['nrc_number'] ?? null),
                fn ($query) => $query->where('nrc_number', $row['nrc_number']),
                fn ($query) => $query->where('full_name', $row['full_name']),
            )
            ->exists();
    }
}
