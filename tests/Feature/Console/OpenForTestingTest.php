<?php

use App\Console\Commands\OpenForTesting;
use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Members\MembershipRegistrar;
use App\Enums\CycleMonthStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    /* The 22nd: outside the declaration window, outside the trading days, and past
       the month registration closes after — every gate the command has to move. */
    Carbon::setTestNow('2026-08-22 10:00:00');

    Storage::fake('local');

    $this->cycle = Cycle::factory()->create([
        'starts_on' => Carbon::parse('2025-12-01'),
        'ends_on' => Carbon::parse('2026-11-30'),
        'registration_closes_after_month' => 3,
    ]);

    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->month = $this->cycle->months()->where('sequence', 9)->firstOrFail();
});

it('opens the declaration window on a day that is normally shut', function () {
    expect(app(DeclarationWindow::class)->isOpen($this->month))->toBeFalse();

    $this->artisan('unity:open-for-testing')->assertSuccessful();

    $month = $this->month->fresh();

    expect(app(DeclarationWindow::class)->isOpen($month))->toBeTrue()
        ->and($month->status)->toBe(CycleMonthStatus::DeclarationsOpen)
        /* Trading must still sit behind the declarations, or the month reads as both
           at once and the banner and the trading console disagree. */
        ->and(app(DeclarationWindow::class)->isTrading($month))->toBeFalse();
});

it('opens the trading window with the declarations shut behind it', function () {
    $this->artisan('unity:open-for-testing', ['--phase' => 'trading'])->assertSuccessful();

    $month = $this->month->fresh();
    $window = app(DeclarationWindow::class);

    expect($window->isTrading($month))->toBeTrue()
        ->and($window->isOpen($month))->toBeFalse()
        ->and($month->status)->toBe(CycleMonthStatus::Trading);
});

it('opens membership registration for the rest of the cycle', function () {
    $registrar = app(MembershipRegistrar::class);

    expect($this->cycle->registrationOpenForMonth(9))->toBeFalse();

    $this->artisan('unity:open-for-testing')->assertSuccessful();

    expect($this->cycle->fresh()->registrationOpenForMonth(9))->toBeTrue()
        ->and($registrar->monthSequenceFor($this->cycle->fresh(), Carbon::today()))->toBe(9);
});

it('puts every date it moved back exactly as it found it', function () {
    $before = $this->month->only([
        'declarations_open_at', 'declarations_close_at',
        'trading_starts_on', 'trading_concludes_on', 'disbursement_on',
    ]);

    $this->artisan('unity:open-for-testing')->assertSuccessful();
    $this->artisan('unity:open-for-testing', ['--close' => true])->assertSuccessful();

    $after = $this->month->fresh()->only(array_keys($before));

    expect($after)->toEqual($before)
        ->and($this->month->fresh()->status)->toBe(CycleMonthStatus::Pending)
        ->and($this->cycle->fresh()->registration_closes_after_month)->toBe(3)
        ->and(Storage::disk('local')->exists(OpenForTesting::SNAPSHOT))->toBeFalse();
});

it('keeps the first snapshot when opened twice, so closing still restores the original', function () {
    $original = $this->month->declarations_close_at;

    $this->artisan('unity:open-for-testing')->assertSuccessful();
    $this->artisan('unity:open-for-testing', ['--phase' => 'trading'])->assertSuccessful();
    $this->artisan('unity:open-for-testing', ['--close' => true])->assertSuccessful();

    expect($this->month->fresh()->declarations_close_at->toDateTimeString())
        ->toBe($original->toDateTimeString());
});

it('refuses to close when it never opened anything', function () {
    $this->artisan('unity:open-for-testing', ['--close' => true])->assertFailed();
});

it('rejects a phase it does not know', function () {
    $this->artisan('unity:open-for-testing', ['--phase' => 'shareout'])->assertFailed();

    expect($this->cycle->fresh()->registration_closes_after_month)->toBe(3);
});

it('opens a month named by sequence rather than todays', function () {
    $this->artisan('unity:open-for-testing', ['--month' => 11])->assertSuccessful();

    expect(CycleMonth::query()->where('cycle_id', $this->cycle->id)->where('sequence', 11)->firstOrFail()->status)
        ->toBe(CycleMonthStatus::DeclarationsOpen)
        ->and($this->month->fresh()->status)->toBe(CycleMonthStatus::Pending);
});
