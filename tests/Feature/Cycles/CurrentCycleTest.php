<?php

use App\Domain\Cycles\CurrentCycle;
use App\Enums\CycleStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;

/**
 * The scope is deliberately inert until a cycle is pinned, so domain services and
 * tests that work across cycles keep seeing everything. Pinning happens in the web
 * middleware, which is what confines a request to one cycle.
 */
it('resolves the active cycle', function () {
    $active = Cycle::factory()->create(['name' => 'Active one']);
    Cycle::factory()->create(['name' => 'Old one', 'status' => CycleStatus::Closed]);

    expect(app(CurrentCycle::class)->get()?->id)->toBe($active->id);
});

it('returns null when no cycle is active', function () {
    Cycle::factory()->create(['status' => CycleStatus::Closed]);

    expect(app(CurrentCycle::class)->get())->toBeNull();
});

it('prefers the most recently started cycle when several are active', function () {
    Cycle::factory()->create(['name' => 'Earlier', 'starts_on' => '2024-12-01']);
    $later = Cycle::factory()->create(['name' => 'Later', 'starts_on' => '2025-12-01']);

    expect(app(CurrentCycle::class)->get()?->id)->toBe($later->id);
});

it('throws rather than guessing when nothing is active', function () {
    expect(fn () => app(CurrentCycle::class)->getOrFail())
        ->toThrow(RuntimeException::class, 'No active cycle is configured.');
});

it('memoises the lookup', function () {
    $cycle = Cycle::factory()->create();
    $resolver = app(CurrentCycle::class);

    expect($resolver->get()?->id)->toBe($cycle->id);

    $cycle->delete();

    // Already resolved, so it does not hit the database again.
    expect($resolver->get()?->id)->toBe($cycle->id);
});

it('leaves queries unscoped until a cycle is pinned', function () {
    $first = Cycle::factory()->create(['name' => 'First', 'starts_on' => '2024-12-01']);
    $second = Cycle::factory()->create(['name' => 'Second', 'starts_on' => '2025-12-01']);

    Member::factory()->for($first)->create();
    Member::factory()->for($second)->create();

    expect(Member::count())->toBe(2);
});

it('confines queries to the pinned cycle', function () {
    $first = Cycle::factory()->create(['name' => 'First', 'starts_on' => '2024-12-01']);
    $second = Cycle::factory()->create(['name' => 'Second', 'starts_on' => '2025-12-01']);

    Member::factory()->for($first)->create();
    Member::factory()->count(2)->for($second)->create();

    app(CurrentCycle::class)->set($first);

    expect(Member::count())->toBe(1);
});

it('reads across cycles on request', function () {
    $first = Cycle::factory()->create(['name' => 'First', 'starts_on' => '2024-12-01']);
    $second = Cycle::factory()->create(['name' => 'Second', 'starts_on' => '2025-12-01']);

    Member::factory()->for($first)->create();
    Member::factory()->count(2)->for($second)->create();

    app(CurrentCycle::class)->set($first);

    expect(Member::acrossCycles()->count())->toBe(3);
});

it('reads one specific cycle regardless of what is pinned', function () {
    $first = Cycle::factory()->create(['name' => 'First', 'starts_on' => '2024-12-01']);
    $second = Cycle::factory()->create(['name' => 'Second', 'starts_on' => '2025-12-01']);

    Member::factory()->for($first)->create();
    Member::factory()->count(2)->for($second)->create();

    app(CurrentCycle::class)->set($first);

    expect(Member::forCycle($second)->count())->toBe(2);
});

it('scopes a web request to the active cycle', function () {
    $old = Cycle::factory()->create(['name' => 'Old', 'starts_on' => '2024-12-01', 'status' => CycleStatus::Closed]);
    $current = Cycle::factory()->create(['name' => 'Current', 'starts_on' => '2025-12-01']);

    $user = User::factory()->create();
    Member::factory()->for($old)->create(['user_id' => $user->id, 'member_number' => 1]);
    $currentMember = Member::factory()->for($current)->create(['user_id' => $user->id, 'member_number' => 1]);

    // The member record shared with the frontend must be this cycle's, not last cycle's.
    $this->actingAs($user)
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.member_id', $currentMember->id));
});
