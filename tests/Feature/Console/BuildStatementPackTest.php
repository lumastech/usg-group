<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Reporting\MonthlyStatementPack;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2025-12-10');
    Storage::fake('local');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    app(CurrentCycle::class)->set($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(1_000), $this->treasurer);
});

it('renders the four group sheets and a statement for every member', function () {
    $manifest = app(MonthlyStatementPack::class)->build($this->cycle, $this->december);

    expect($manifest['member_count'])->toBe(2)
        ->and($manifest['files'])->toHaveCount(6)
        ->and($manifest['month_label'])->toBe('December 2025');

    foreach ($manifest['files'] as $file) {
        Storage::disk('local')->assertExists($file['path']);
        expect($file['bytes'])->toBeGreaterThan(0);
    }

    Storage::disk('local')->assertExists("{$manifest['directory']}/savings.pdf");
    Storage::disk('local')->assertExists("{$manifest['directory']}/loans.pdf");
    Storage::disk('local')->assertExists("{$manifest['directory']}/social-fund.pdf");
    Storage::disk('local')->assertExists("{$manifest['directory']}/declarations.pdf");
});

it('replaces the last build of a month rather than piling up beside it', function () {
    $pack = app(MonthlyStatementPack::class);

    $manifest = $pack->build($this->cycle, $this->december);
    $stale = "{$manifest['directory']}/members/999-someone-who-left.pdf";

    Storage::disk('local')->put($stale, 'old');

    $pack->build($this->cycle, $this->december);

    Storage::disk('local')->assertMissing($stale);
    Storage::disk('local')->assertExists("{$manifest['directory']}/savings.pdf");
});

it('builds the pack from the command', function () {
    $this->artisan('unity:statement-pack', [
        '--cycle' => $this->cycle->id,
        '--month' => 1,
    ])->assertSuccessful();

    Storage::disk('local')->assertExists("statement-packs/{$this->cycle->id}/2025-12/savings.pdf");
});

it('refuses a month the cycle does not have', function () {
    $this->artisan('unity:statement-pack', [
        '--cycle' => $this->cycle->id,
        '--month' => 99,
    ])->assertFailed();
});

it('builds the pack from the reports hub button', function () {
    $this->actingAs($this->treasurer->user)
        ->post(route('app.reports.statement-pack'), ['month' => 1])
        ->assertRedirect()
        ->assertSessionHas('success');

    Storage::disk('local')->assertExists("statement-packs/{$this->cycle->id}/2025-12/savings.pdf");
});
