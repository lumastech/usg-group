<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Exceptions\DeclarationWindowClosedException;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->window = app(DeclarationWindow::class);
    $this->service = app(DeclarationService::class);
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->treasurer = Member::factory()->for($this->cycle)->create();
});

it('opens the window at eight on the first and not a minute before', function (string $at, bool $open) {
    expect($this->window->isOpen($this->january, Carbon::parse($at)))->toBe($open);
})->with([
    ['2026-01-01 07:59:59', false],
    ['2026-01-01 08:00:00', true],
    ['2026-01-02 12:00:00', true],
    ['2026-01-03 23:59:59', true],
    ['2026-01-04 00:00:00', false],
]);

it('refuses a member declaring one second before the window opens', function () {
    $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-01-01 07:59:59'),
    );
})->throws(DeclarationWindowClosedException::class, 'open on 1 January 2026 at 08:00');

it('accepts a member declaring the second the window opens', function () {
    $declaration = $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-01-01 08:00:00'),
    );

    expect($declaration->is_late)->toBeFalse();
});

it('accepts a member declaring at the last second of the third', function () {
    $declaration = $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-01-03 23:59:59'),
    );

    expect($declaration->is_late)->toBeFalse();
});

it('refuses a member declaring once the third has passed', function () {
    $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-01-04 00:00:00'),
    );
})->throws(DeclarationWindowClosedException::class, 'closed on 3 January 2026');

it('lets the treasurer capture a late declaration and stamps it late', function () {
    $declaration = $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->treasurer,
        onBehalf: true,
        at: Carbon::parse('2026-01-05 09:00'),
    );

    expect($declaration->is_late)->toBeTrue()
        ->and($declaration->recorded_by_member_id)->toBe($this->treasurer->id);
});

it('does not let even the treasurer capture before the window has opened', function () {
    $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->treasurer,
        onBehalf: true,
        at: Carbon::parse('2025-12-31 23:00'),
    );
})->throws(DeclarationWindowClosedException::class);

it('names the state the month is in', function (string $at, string $state) {
    expect($this->window->state($this->january, Carbon::parse($at)))->toBe($state);
})->with([
    ['2026-01-01 07:00', DeclarationWindow::BEFORE],
    ['2026-01-02 10:00', DeclarationWindow::DECLARATIONS],
    ['2026-01-03 23:59', DeclarationWindow::DECLARATIONS],
    ['2026-01-04 09:00', DeclarationWindow::TRADING],
    ['2026-01-08 09:00', DeclarationWindow::CLOSED],
]);

it('counts down to the close while the window is open', function () {
    $remaining = $this->window->secondsRemaining($this->january, Carbon::parse('2026-01-03 23:59:00'));

    expect($remaining)->toBe(59);
});
