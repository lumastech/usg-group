<?php

namespace App\Domain\Reporting;

use App\Domain\Declarations\DeclarationSheet;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The month's whole reporting pack, built in one pass.
 *
 * Four group sheets — savings, loans, the social fund and the month's declarations —
 * plus a personal statement for every member, rendered through the same services the
 * screens and the one-off downloads use. Nothing here computes a figure of its own.
 *
 * The pack is written to a disk rather than streamed, because it is what module 9
 * attaches to the monthly mail-out: the button and the command both leave the same
 * files in the same place, and the mailer only has to read a manifest.
 */
class MonthlyStatementPack
{
    public function __construct(
        protected SavingsMatrix $savings,
        protected LoanMatrix $loans,
        protected SocialFundOverview $fund,
        protected DeclarationSheet $declarations,
    ) {}

    /**
     * Builds the pack for a month, overwriting any earlier build of the same month.
     *
     * @return array{
     *     cycle_id: int,
     *     month_id: int,
     *     month_label: string,
     *     directory: string,
     *     disk: string,
     *     built_at: string,
     *     files: array<int, array{label: string, path: string, bytes: int}>,
     *     member_count: int,
     * }
     */
    public function build(Cycle $cycle, CycleMonth $month, string $disk = 'local'): array
    {
        $filesystem = Storage::disk($disk);
        $directory = $this->directoryFor($cycle, $month);
        $generatedAt = Carbon::now();

        $filesystem->deleteDirectory($directory);

        $files = [
            $this->write($filesystem, $directory, 'savings.pdf', 'Savings ledger',
                $this->savingsPdf($cycle, $month, $generatedAt)),
            $this->write($filesystem, $directory, 'loans.pdf', 'Loans ledger',
                $this->loansPdf($cycle, $month, $generatedAt)),
            $this->write($filesystem, $directory, 'social-fund.pdf', 'Social fund ledger',
                $this->fundPdf($cycle, $generatedAt)),
            $this->write($filesystem, $directory, 'declarations.pdf', 'Declarations',
                $this->declarationsPdf($cycle, $month, $generatedAt)),
        ];

        $members = $cycle->members()->get();

        foreach ($members as $member) {
            $files[] = $this->write(
                $filesystem,
                $directory,
                'members/'.$this->memberFilename($member),
                "Statement — {$member->full_name}",
                $this->memberStatementPdf($cycle, $member, $generatedAt),
            );
        }

        return [
            'cycle_id' => $cycle->id,
            'month_id' => $month->id,
            'month_label' => $month->label(),
            'directory' => $directory,
            'disk' => $disk,
            'built_at' => $generatedAt->toIso8601String(),
            'files' => $files,
            'member_count' => $members->count(),
        ];
    }

    /** Where a month's pack lives, e.g. statement-packs/3/2026-08. */
    public function directoryFor(Cycle $cycle, CycleMonth $month): string
    {
        return "statement-packs/{$cycle->id}/".$month->month->format('Y-m');
    }

    /**
     * @return array{label: string, path: string, bytes: int}
     */
    protected function write(Filesystem $filesystem, string $directory, string $name, string $label, string $contents): array
    {
        $path = "{$directory}/{$name}";

        $filesystem->put($path, $contents);

        return ['label' => $label, 'path' => $path, 'bytes' => strlen($contents)];
    }

    protected function savingsPdf(Cycle $cycle, CycleMonth $month, Carbon $generatedAt): string
    {
        return Pdf::loadView('pdf.savings-matrix', [
            'cycle' => $cycle,
            'matrix' => $this->savings->for($cycle, $month->sequence),
            'generatedAt' => $generatedAt,
            'money' => $this->money(),
        ])->setPaper('a3', 'landscape')->output();
    }

    protected function loansPdf(Cycle $cycle, CycleMonth $month, Carbon $generatedAt): string
    {
        return Pdf::loadView('pdf.loan-matrix', [
            'cycle' => $cycle,
            'matrix' => $this->loans->for($cycle, $month->sequence),
            'generatedAt' => $generatedAt,
            'money' => $this->money(),
        ])->setPaper('a3', 'landscape')->output();
    }

    protected function fundPdf(Cycle $cycle, Carbon $generatedAt): string
    {
        return Pdf::loadView('pdf.social-fund-ledger', [
            'cycle' => $cycle,
            'overview' => $this->fund->for($cycle),
            'entries' => SocialFundTransaction::query()->forCycle($cycle)
                ->with('member', 'recordedBy', 'secondApprover')
                ->orderBy('occurred_on')->orderBy('id')->get(),
            'generatedAt' => $generatedAt,
            'money' => $this->money(),
        ])->setPaper('a4', 'landscape')->output();
    }

    protected function declarationsPdf(Cycle $cycle, CycleMonth $month, Carbon $generatedAt): string
    {
        $data = $this->declarations->for($month);

        return Pdf::loadView('pdf.declarations', [
            'cycle' => $cycle,
            'month' => $month,
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'generatedAt' => $generatedAt,
            'money' => $this->money(),
        ])->setPaper('a4', 'landscape')->output();
    }

    protected function memberStatementPdf(Cycle $cycle, Member $member, Carbon $generatedAt): string
    {
        $own = $this->savings->forMember($cycle, $member);

        return Pdf::loadView('pdf.member-statement', [
            'cycle' => $cycle,
            'member' => $member,
            'history' => $own['months'],
            'totals' => $own['totals'],
            'generatedAt' => $generatedAt,
            'money' => $this->money(),
        ])->output();
    }

    protected function memberFilename(Member $member): string
    {
        return str_pad((string) $member->member_number, 3, '0', STR_PAD_LEFT)
            .'-'.Str::slug($member->full_name).'.pdf';
    }

    /** @return callable(int): string */
    protected function money(): callable
    {
        return fn (int $ngwee): string => Kwacha::format($ngwee);
    }
}
