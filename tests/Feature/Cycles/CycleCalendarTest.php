<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleCalendar;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Enums\CycleMonthStatus;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Exceptions\DeclarationWindowClosedException;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The committee moving a month's windows.
 *
 * The calendar is what every guard in the portal reads, so re-dating a month is how a
 * declaration period is reopened for the whole group — and why a month that has already
 * been traded is closed to it.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-20 09:00'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);
    $this->calendar = app(CycleCalendar::class);
});

/** An administrator: holds every permission, and deliberately has no member row. */
function administrator(): User
{
    $user = User::factory()->create();
    $user->assignRole(MemberRole::Admin->value);

    return $user;
}

it('moves a month onto new dates', function () {
    $month = $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-05 08:00'),
        Carbon::parse('2026-01-09 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
        Carbon::parse('2026-01-12'),
    );

    expect($month->declarations_close_at->toDateTimeString())->toBe('2026-01-09 23:59:00')
        ->and($month->trading_starts_on->toDateString())->toBe('2026-01-10')
        ->and($month->disbursement_on->toDateString())->toBe('2026-01-12');
});

it('reopens a closed declaration period for the whole group', function () {
    $member = Member::factory()->for($this->cycle)->create();

    expect(fn () => app(DeclarationService::class)->submit(
        $member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $member,
    ))->toThrow(DeclarationWindowClosedException::class);

    $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-01-22 23:59'),
        Carbon::parse('2026-01-23'),
        Carbon::parse('2026-01-26'),
    );

    $declaration = app(DeclarationService::class)->submit(
        $member,
        $this->january->refresh(),
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $member,
    );

    expect($declaration->is_late)->toBeFalse();
});

it('keeps a month inside its own calendar month', function () {
    expect(fn () => $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-02-02 23:59'),
        Carbon::parse('2026-02-03'),
        Carbon::parse('2026-02-05'),
    ))->toThrow(DomainRuleException::class, 'must fall inside January 2026');
});

it('will not let declarations still be open when the trading table opens', function () {
    expect(fn () => $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-01-12 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
    ))->toThrow(DomainRuleException::class, 'close before the trading day opens');
});

it('will not disburse on a day the table is not sitting', function () {
    expect(fn () => $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-01-03 23:59'),
        Carbon::parse('2026-01-04'),
        Carbon::parse('2026-01-07'),
        Carbon::parse('2026-01-20'),
    ))->toThrow(DomainRuleException::class, 'one of the trading days');
});

it('will not re-date a month that has been traded and closed', function () {
    $this->january->forceFill(['status' => CycleMonthStatus::Closed])->save();

    expect($this->calendar->isReschedulable($this->january))->toBeFalse()
        ->and(fn () => $this->calendar->reschedule(
            $this->january,
            Carbon::parse('2026-01-01 08:00'),
            Carbon::parse('2026-01-09 23:59'),
            Carbon::parse('2026-01-10'),
            Carbon::parse('2026-01-14'),
        ))->toThrow(DomainRuleException::class, 'traded and closed');
});

it('will not re-date a cycle that is no longer running', function () {
    $this->cycle->forceFill(['status' => CycleStatus::Closing])->save();

    expect(fn () => $this->calendar->reschedule(
        $this->january->refresh(),
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-01-09 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
    ))->toThrow(DomainRuleException::class, 'calendar is closed to changes');
});

it('puts a month back on the constitution\'s dates', function () {
    $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-05 08:00'),
        Carbon::parse('2026-01-09 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
    );

    $month = $this->calendar->resetToConstitution($this->january->refresh());

    expect($month->declarations_open_at->toDateTimeString())->toBe('2026-01-01 08:00:00')
        ->and($month->declarations_close_at->toDateTimeString())->toBe('2026-01-03 23:59:59')
        ->and($month->trading_starts_on->toDateString())->toBe('2026-01-04')
        // The 7th of January 2026 is a Wednesday, so the weekend policy leaves it alone.
        ->and($month->disbursement_on->toDateString())->toBe('2026-01-07');
});

it('logs a re-dating to the audit trail', function () {
    $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-01 08:00'),
        Carbon::parse('2026-01-09 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
    );

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => CycleMonth::class,
        'subject_id' => $this->january->id,
        'event' => 'updated',
    ]);
});

it('keeps the calendar to the offices that may re-date a month', function () {
    $member = User::factory()->create();
    $member->assignRole(MemberRole::Member->value);

    $this->actingAs($member)
        ->get(route('app.settings.calendar'))
        ->assertForbidden();

    $this->actingAs($member)
        ->put(route('app.settings.calendar.update', $this->january), [
            'declarations_open_at' => '2026-01-01T08:00',
            'declarations_close_at' => '2026-01-09T23:59',
            'trading_starts_on' => '2026-01-10',
            'trading_concludes_on' => '2026-01-14',
            'disbursement_on' => '2026-01-14',
        ])
        ->assertForbidden();
});

it('lets the treasury re-date a month without handing it the cycle', function () {
    $treasurer = User::factory()->create();
    $treasurer->assignRole(MemberRole::Treasurer->value);

    $this->actingAs($treasurer)
        ->get(route('app.settings.calendar'))
        ->assertOk();

    $this->actingAs($treasurer)
        ->put(route('app.settings.calendar.update', $this->january), [
            'declarations_open_at' => '2026-01-01T08:00',
            'declarations_close_at' => '2026-01-22T23:59',
            'trading_starts_on' => '2026-01-23',
            'trading_concludes_on' => '2026-01-26',
            'disbursement_on' => '2026-01-26',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->january->refresh()->declarations_close_at->toDateTimeString())
        ->toBe('2026-01-22 23:59:00')
        ->and($treasurer->can(Permission::CyclesManage->value))->toBeFalse();
});

it('lets the chair re-date a month', function () {
    $chair = User::factory()->create();
    $chair->assignRole(MemberRole::Chairperson->value);

    $this->actingAs($chair)
        ->get(route('app.settings.calendar'))
        ->assertOk();
});

it('shows the administrator every month with the state it is in today', function () {
    $this->actingAs(administrator())
        ->get(route('app.settings.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/settings/Calendar')
            ->where('cycle.name', '2025–2026')
            ->has('months', 12)
            ->where('months.1.label', 'January 2026')
            ->where('months.1.window', 'closed')
            ->where('months.1.editable', true)
        );
});

it('re-dates a month from the settings screen', function () {
    $this->actingAs(administrator())
        ->put(route('app.settings.calendar.update', $this->january), [
            'declarations_open_at' => '2026-01-01T08:00',
            'declarations_close_at' => '2026-01-22T23:59',
            'trading_starts_on' => '2026-01-23',
            'trading_concludes_on' => '2026-01-26',
            'disbursement_on' => '2026-01-26',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->january->refresh()->declarations_close_at->toDateTimeString())
        ->toBe('2026-01-22 23:59:00');
});

it('hands a refused calendar back to the form', function () {
    $this->actingAs(administrator())
        ->from(route('app.settings.calendar'))
        ->put(route('app.settings.calendar.update', $this->january), [
            'declarations_open_at' => '2026-01-01T08:00',
            'declarations_close_at' => '2026-02-02T23:59',
            'trading_starts_on' => '2026-02-03',
            'trading_concludes_on' => '2026-02-05',
            'disbursement_on' => '2026-02-05',
        ])
        ->assertRedirect(route('app.settings.calendar'))
        ->assertSessionHasErrors('declarations_close_at');

    expect($this->january->refresh()->declarations_close_at->toDateString())->toBe('2026-01-03');
});

it('restores the constitution from the settings screen', function () {
    $this->calendar->reschedule(
        $this->january,
        Carbon::parse('2026-01-05 08:00'),
        Carbon::parse('2026-01-09 23:59'),
        Carbon::parse('2026-01-10'),
        Carbon::parse('2026-01-14'),
    );

    $this->actingAs(administrator())
        ->post(route('app.settings.calendar.reset', $this->january))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->january->refresh()->trading_starts_on->toDateString())->toBe('2026-01-04');
});
