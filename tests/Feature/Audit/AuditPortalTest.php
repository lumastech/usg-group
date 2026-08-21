<?php

use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Member;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
});

it('is open to the chairperson', function () {
    $this->actingAs($this->chair->user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/Audit'));
});

it('is closed to the treasurer, whose own entries make up most of the log', function () {
    $this->actingAs($this->treasurer->user)->get('/app/audit')->assertForbidden();
});

it('is closed to a member', function () {
    $member = memberWithRole($this->cycle);

    $this->actingAs($member->user)->get('/app/audit')->assertForbidden();
});

it('lists what happened, newest first', function () {
    $subject = Member::factory()->for($this->cycle)->create(['full_name' => 'Grace Banda']);

    activity()->causedBy($this->treasurer->user)->performedOn($subject)->log('Older thing');
    activity()->causedBy($this->chair->user)->performedOn($subject)->log('Newer thing');

    $this->actingAs($this->chair->user)
        ->get('/app/audit')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activities.data.0.description', 'Newer thing')
            ->where('activities.data.0.causer.name', $this->chair->user->name)
            ->where('activities.data.1.description', 'Older thing'));
});

it('filters by who caused the entry', function () {
    $subject = Member::factory()->for($this->cycle)->create();

    activity()->causedBy($this->treasurer->user)->performedOn($subject)->log('Treasurer did this');
    activity()->causedBy($this->chair->user)->performedOn($subject)->log('Chair did this');

    $this->actingAs($this->chair->user)
        ->get('/app/audit?causer='.$this->treasurer->user->id)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activities.meta.total', 1)
            ->where('activities.data.0.description', 'Treasurer did this'));
});

it('filters by the kind of record and by date', function () {
    $subject = Member::factory()->for($this->cycle)->create();

    // Creating the records above logs activity of its own; this test is about
    // what the filters do, so it starts from an empty trail.
    Activity::query()->delete();

    $this->travelTo(Carbon::parse('2026-03-01 09:00'));
    activity()->causedBy($this->chair->user)->performedOn($subject)->log('In March');

    $this->travelTo(Carbon::parse('2026-05-01 09:00'));
    activity()->causedBy($this->chair->user)->performedOn($this->cycle)->log('In May');

    $this->actingAs($this->chair->user)
        ->get('/app/audit?subject_type='.urlencode(Member::class))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activities.meta.total', 1)
            ->where('activities.data.0.description', 'In March'));

    $this->actingAs($this->chair->user)
        ->get('/app/audit?from=2026-04-01')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activities.meta.total', 1)
            ->where('activities.data.0.description', 'In May'));
});

it('offers only the causers, record types and areas that appear in the log', function () {
    $subject = Member::factory()->for($this->cycle)->create();

    // Creating the records above logs activity of its own; this test is about
    // what the filters do, so it starts from an empty trail.
    Activity::query()->delete();

    activity('members')->causedBy($this->chair->user)->performedOn($subject)->log('Something');

    $this->actingAs($this->chair->user)
        ->get('/app/audit')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('causers', 1)
            ->where('causers.0.value', $this->chair->user->id)
            ->has('subjectTypes', 1)
            ->where('subjectTypes.0.value', Member::class)
            ->where('logs.0.value', 'members'));
});
