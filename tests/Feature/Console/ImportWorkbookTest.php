<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Import\WorkbookImporter;
use App\Enums\MemberRole;
use App\Enums\SavingsTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\SocialFundTransaction;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Carbon::setTestNow('2026-03-01');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer, [
        'member_number' => 1,
        'full_name' => 'Bertha Chileshe',
    ]);

    $this->member = memberWithRole($this->cycle, MemberRole::Member, [
        'member_number' => 2,
        'full_name' => 'Mwansa Phiri',
    ]);

    /*
     * A fixture in the shape the group's own workbook has: two header rows, months
     * across the top and a sub-column under each. Built here rather than committed as
     * a binary, so the layout the importer expects is readable in the test.
     */
    $this->workbook = function (array $overrides = []): string {
        $sheets = [
            'SAVINGS' => [
                ['#', 'Member', 'Dec-25', '', 'Jan-26', ''],
                ['', '', 'Savings', 'Interest', 'Savings', 'Interest'],
                [1, 'Bertha Chileshe', 500, 30, 1000, 20],
                [2, 'Mwansa Phiri', 500, 30, 500, 20],
                ['', 'TOTAL', 1000, 60, 1500, 40],
            ],
            'LOANS' => [
                ['#', 'Member', 'Dec-25', ''],
                ['', '', 'Loan', 'Repayment'],
                [1, 'Bertha Chileshe', 2000, 0],
                [2, 'Mwansa Phiri', 0, 0],
            ],
            'SOCIAL FUND' => [
                ['#', 'Member', 'Contribution'],
                [1, 'Bertha Chileshe', 250],
                [2, 'Mwansa Phiri', 250],
            ],
            'Declarations' => [
                ['#', 'Member', 'Dec-25', '', ''],
                ['', '', 'Savings', 'Repayment', 'Loan'],
                [1, 'Bertha Chileshe', 500, 0, 2000],
                [2, 'Mwansa Phiri', 500, 0, 0],
            ],
            ...$overrides,
        ];

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1');
        }

        $path = tempnam(sys_get_temp_dir(), 'unity').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    };

    $this->importer = app(WorkbookImporter::class);
});

it('plans every figure the ledgers do not already hold', function () {
    $plan = $this->importer->plan($this->cycle, ($this->workbook)());

    expect($plan['warnings'])->toBeEmpty()
        ->and($plan['summary'][WorkbookImporter::KIND_SAVINGS]['planned'])->toBe(4)
        ->and($plan['summary'][WorkbookImporter::KIND_SAVINGS]['amount_ngwee'])->toBe(250_000)
        ->and($plan['summary'][WorkbookImporter::KIND_LOAN_DISBURSEMENT]['planned'])->toBe(1)
        ->and($plan['summary'][WorkbookImporter::KIND_SOCIAL_FUND]['planned'])->toBe(2)
        ->and($plan['summary'][WorkbookImporter::KIND_DECLARATION]['planned'])->toBe(2);

    /* Planning writes nothing — the dry run is the normal way to use this. */
    expect(SavingsTransaction::count())->toBe(0);
});

it('does not warn about the workbook own totals row', function () {
    $plan = $this->importer->plan($this->cycle, ($this->workbook)());

    expect($plan['warnings'])->toBeEmpty();
});

it('names a row that matches no member in this cycle', function () {
    $plan = $this->importer->plan($this->cycle, ($this->workbook)([
        'SOCIAL FUND' => [
            ['#', 'Member', 'Contribution'],
            [9, 'Someone Elses Name', 250],
        ],
    ]));

    expect($plan['warnings'])->toHaveCount(1)
        ->and($plan['warnings'][0])->toContain('Someone Elses Name');
});

it('posts the workbook into the ledgers as import-flagged history', function () {
    $result = $this->importer->import($this->cycle, ($this->workbook)(), $this->treasurer);

    expect($result['failed'])->toBeEmpty()
        ->and($result['posted'])->toBe(9);

    expect(SavingsTransaction::query()
        ->where('type', SavingsTransactionType::ImportOpening->value)
        ->sum('amount_ngwee'))->toBe(250_000);

    expect(SocialFundTransaction::query()->acrossCycles()
        ->where('type', SocialFundTransactionType::Contribution->value)
        ->sum('amount_ngwee'))->toBe(50_000);

    expect(Loan::query()->acrossCycles()->count())->toBe(1)
        ->and(LoanTransaction::query()->sum('amount_ngwee'))->toBe(200_000)
        ->and(Declaration::query()->acrossCycles()->count())->toBe(2);

    /* The declaration's other two columns travel with it. */
    $declaration = Declaration::query()->acrossCycles()
        ->where('member_id', $this->treasurer->id)
        ->first();

    expect($declaration->loan_requested_amount_ngwee->getMinorAmount()->toInt())->toBe(200_000);
});

/*
 * The point of the natural key. A workbook is imported, corrected, and imported again;
 * the second run must post the correction and nothing else.
 */
it('is idempotent — importing the same workbook twice posts nothing the second time', function () {
    $path = ($this->workbook)();

    $first = $this->importer->import($this->cycle, $path, $this->treasurer);
    $second = $this->importer->import($this->cycle, $path, $this->treasurer);

    expect($first['posted'])->toBe(9)
        ->and($second['posted'])->toBe(0)
        ->and($second['skipped'])->toBe(9)
        ->and(SavingsTransaction::count())->toBe(4)
        ->and(Loan::query()->acrossCycles()->count())->toBe(1)
        ->and(Declaration::query()->acrossCycles()->count())->toBe(2);
});

it('posts only the rows a corrected workbook has added', function () {
    $this->importer->import($this->cycle, ($this->workbook)(), $this->treasurer);

    /* February was left blank the first time and has since been filled in. */
    $corrected = ($this->workbook)([
        'SAVINGS' => [
            ['#', 'Member', 'Dec-25', '', 'Jan-26', '', 'Feb-26', ''],
            ['', '', 'Savings', 'Interest', 'Savings', 'Interest', 'Savings', 'Interest'],
            [1, 'Bertha Chileshe', 500, 30, 1000, 20, 500, 10],
            [2, 'Mwansa Phiri', 500, 30, 500, 20, 0, 0],
        ],
    ]);

    $result = $this->importer->import($this->cycle, $corrected, $this->treasurer);

    expect($result['posted'])->toBe(1)
        ->and(SavingsTransaction::sum('amount_ngwee'))->toBe(300_000);
});

it('runs the command as a dry run without writing', function () {
    $this->artisan('unity:import-workbook', [
        'file' => ($this->workbook)(),
        '--cycle' => $this->cycle->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(SavingsTransaction::count())->toBe(0);
});

it('reconciles the workbook against the ledgers when the command has run', function () {
    $this->artisan('unity:import-workbook', [
        'file' => ($this->workbook)(),
        '--cycle' => $this->cycle->id,
        '--actor' => $this->treasurer->id,
    ])->assertSuccessful();

    expect(SavingsTransaction::sum('amount_ngwee'))->toBe(250_000)
        ->and(Member::query()->acrossCycles()->count())->toBe(2);
});
