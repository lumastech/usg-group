<?php

use App\Domain\Governance\AmendmentWindow;
use App\Domain\Governance\MotionRecorder;
use App\Enums\MotionType;
use App\Exceptions\AmendmentWindowClosedException;
use App\Models\Amendment;
use App\Models\Cycle;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Motion;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['starts_on' => '2025-12-01']);
    $this->window = app(AmendmentWindow::class);
    $this->motions = app(MotionRecorder::class);
});

it('opens six months after the cycle starts when nothing has been amended', function () {
    expect($this->window->opensOn($this->cycle)->toDateString())->toBe('2026-06-01')
        ->and($this->window->isOpen($this->cycle, Carbon::parse('2026-05-31')))->toBeFalse()
        ->and($this->window->isOpen($this->cycle, Carbon::parse('2026-06-01')))->toBeTrue();
});

it('counts down the days while the window is shut', function () {
    expect($this->window->daysUntilOpen($this->cycle, Carbon::parse('2026-05-22')))->toBe(10)
        ->and($this->window->daysUntilOpen($this->cycle, Carbon::parse('2026-06-01')))->toBe(0)
        ->and($this->window->daysUntilOpen($this->cycle, Carbon::parse('2026-08-01')))->toBe(0);
});

it('restarts the six months from the last amendment the group carried', function () {
    $motion = Motion::factory()
        ->for($this->cycle)
        ->type(MotionType::Amendment)
        ->passed('2026-06-10 11:00')
        ->create(['proposed_by_member_id' => Member::factory()->for($this->cycle)]);

    Amendment::factory()->for($this->cycle)->create(['motion_id' => $motion->id]);

    expect($this->window->opensOn($this->cycle)->toDateString())->toBe('2026-12-10')
        ->and($this->window->payload($this->cycle, Carbon::parse('2026-08-20')))
        ->toMatchArray(['is_open' => false, 'last_amended_on' => '2026-06-10']);
});

it('does not restart the clock for an amendment that failed', function () {
    Motion::factory()
        ->for($this->cycle)
        ->type(MotionType::Amendment)
        ->create([
            'proposed_by_member_id' => Member::factory()->for($this->cycle),
            'votes_for' => 5,
            'eligible_count' => 20,
            'votes_needed' => 12,
            'passed' => false,
            'decided_at' => '2026-07-01 11:00',
        ]);

    expect($this->window->opensOn($this->cycle)->toDateString())->toBe('2026-06-01')
        ->and($this->window->isOpen($this->cycle, Carbon::parse('2026-07-02')))->toBeTrue();
});

it('does not overflow the month when six months lands short', function () {
    $cycle = Cycle::factory()->create(['name' => '2024–2025', 'starts_on' => '2025-08-31']);

    /* Six months from 31 August is the end of February, not 3 March. */
    expect($this->window->opensOn($cycle)->toDateString())->toBe('2026-02-28');
});

it('blocks a fresh proposal until the window reopens, naming the date', function () {
    $meeting = Meeting::factory()->for($this->cycle)->create();
    $proposer = Member::factory()->for($this->cycle)->create();

    $motion = Motion::factory()
        ->for($this->cycle)
        ->type(MotionType::Amendment)
        ->passed('2026-06-10 11:00')
        ->create(['proposed_by_member_id' => $proposer->id]);

    Amendment::factory()->for($this->cycle)->create(['motion_id' => $motion->id]);

    expect(fn () => $this->motions->propose(
        MotionType::Amendment,
        'Another change',
        $proposer,
        $meeting,
        amendment: [
            'section_reference' => 'Section 9',
            'current_text' => 'old',
            'proposed_text' => 'new',
            'effective_date' => '2026-09-01',
        ],
        at: Carbon::parse('2026-08-20'),
    ))->toThrow(AmendmentWindowClosedException::class, '10 Dec 2026');
});
