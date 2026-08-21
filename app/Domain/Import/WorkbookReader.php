<?php

namespace App\Domain\Import;

use App\Exceptions\DomainRuleException;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Turns the group's spreadsheet into plain rows the importer can reason about.
 *
 * The workbook was kept by hand for a year, so nothing about it is guaranteed: sheets
 * get renamed, a blank row creeps in above the headings, a month column is merged
 * across two sub-columns. Everything here is therefore tolerant by design — sheets are
 * matched loosely by name, the header row is found rather than assumed, and month
 * columns are recognised from whatever the heading says rather than by position.
 *
 * It reads and nothing else. Deciding what a row means is WorkbookImporter's job.
 */
class WorkbookReader
{
    /**
     * Every sheet in the file, keyed by its title.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function sheets(string $path): array
    {
        if (! is_file($path)) {
            throw DomainRuleException::make("There is no workbook at {$path}.");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $sheets = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            /** @var Worksheet $sheet */
            $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false, false);
        }

        $spreadsheet->disconnectWorksheets();

        return $sheets;
    }

    /**
     * One sheet, matched on a loose name.
     *
     * "SOCIAL FUND", "Social Fund" and "social-fund" are the same sheet as far as the
     * import is concerned; a year of hand-editing guarantees at least one of them.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @return array<int, array<int, mixed>>|null
     */
    public function find(array $sheets, string $wanted): ?array
    {
        $needle = $this->normalise($wanted);

        foreach ($sheets as $title => $rows) {
            if ($this->normalise($title) === $needle) {
                return $rows;
            }
        }

        foreach ($sheets as $title => $rows) {
            if (str_contains($this->normalise($title), $needle)) {
                return $rows;
            }
        }

        return null;
    }

    /**
     * The index of the row that carries the column headings.
     *
     * Found by looking for the cell that names the member column, because that is the
     * one heading every sheet in this workbook shares.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function headerRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            foreach ($row as $cell) {
                $value = $this->normalise((string) $cell);

                if ($value === 'member' || $value === 'name' || $value === 'membername' || $value === 'fullname') {
                    return $index;
                }
            }
        }

        return null;
    }

    /** Strips case, spacing and punctuation so headings compare on their words alone. */
    public function normalise(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    /**
     * A cell read as ngwee.
     *
     * The workbook is kept in Kwacha and the application stores ngwee, so everything
     * crossing this boundary is multiplied by 100 exactly once — here. Blanks, dashes
     * and the stray "-" a treasurer types for nil all read as zero.
     */
    public function ngwee(mixed $cell): int
    {
        if ($cell === null || $cell === '' || $cell === '-') {
            return 0;
        }

        if (is_numeric($cell)) {
            return (int) round(((float) $cell) * 100);
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $cell) ?? '';

        return $cleaned === '' || $cleaned === '-' ? 0 : (int) round(((float) $cleaned) * 100);
    }
}
