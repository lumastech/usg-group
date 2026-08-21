<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Payout;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2025-12-01');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['status' => CycleStatus::Active]);
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    app(CurrentCycle::class)->set($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->admin = memberWithRole($this->cycle, MemberRole::Admin);
    $this->plain = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record($this->plain, $this->december, Kwacha::of(1_000), $this->treasurer);
});

it('shows the share-out sheet to the committee', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.shareout.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/shareout/Index')
            ->where('cycle.is_sharing_out', false)
            ->has('sheet.rows', 4)
            ->where('abilities.runBatch', false)
        );
});

it('keeps the share-out sheet away from an ordinary member', function () {
    $this->actingAs($this->plain->user)
        ->get(route('app.shareout.index'))
        ->assertForbidden();
});

it('offers the batch runner only once share-out is open', function () {
    $this->cycle->forceFill(['status' => CycleStatus::ShareOut])->save();

    $this->actingAs($this->treasurer->user)
        ->get(route('app.shareout.index'))
        ->assertInertia(fn ($page) => $page->where('abilities.runBatch', true));

    /* The chair approves closures but never hands the money over. */
    $this->actingAs($this->chair->user)
        ->get(route('app.shareout.index'))
        ->assertInertia(fn ($page) => $page->where('abilities.runBatch', false));
});

it('renders the pre-flight checklist with each check green or red', function () {
    $this->actingAs($this->admin->user)
        ->get(route('app.shareout.preflight'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/shareout/Preflight')
            ->has('preflight.items', 4)
            ->where('abilities.beginClosing', true)
            ->where('abilities.openShareOut', false)
        );
});

it('lets only cycles.manage move the cycle on', function () {
    $this->actingAs($this->treasurer->user)
        ->post(route('app.shareout.close'))
        ->assertForbidden();

    $this->actingAs($this->admin->user)
        ->post(route('app.shareout.close'))
        ->assertRedirect();

    expect($this->cycle->refresh()->status)->toBe(CycleStatus::Closing);
});

it('refuses to open share-out over a dirty checklist without a written reason', function () {
    $this->cycle->forceFill(['status' => CycleStatus::Closing])->save();

    /* Nobody has paid their social fund contribution, so a check is outstanding. */
    $this->actingAs($this->admin->user)
        ->post(route('app.shareout.open'))
        ->assertSessionHasErrors('override_note');

    expect($this->cycle->refresh()->status)->toBe(CycleStatus::Closing);
});

it('downloads the share-out sheet in both formats', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.shareout.export', 'xlsx'))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($this->treasurer->user)
        ->get(route('app.shareout.export', 'pdf'))
        ->assertOk();
});

it('renders the master payout schedule', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.shareout.schedule'))
        ->assertOk();

    expect(Payout::query()->acrossCycles()->count())->toBe(0);
});

it('shows the risk page to anyone who may read the loan register', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.risk'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/Risk')
            ->where('projection.horizon_months', 3)
        );

    $this->actingAs($this->plain->user)
        ->get(route('app.risk'))
        ->assertForbidden();
});

/*
 * The hub lists only what the signed-in user could actually download, driven by the
 * same permission the export route enforces.
 */
it('filters the reports hub by permission', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/Reports')
            ->where('reports', fn ($reports) => collect($reports)->pluck('key')->all() === [
                'savings', 'loans', 'fund', 'declarations',
            ])
        );

    $this->actingAs($this->chair->user)
        ->get(route('app.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports', fn ($reports) => in_array('shareout', collect($reports)->pluck('key')->all(), true))
        );

    $this->actingAs($this->plain->user)
        ->get(route('app.reports.index'))
        ->assertForbidden();
});

it('holds the workbook import to cycles.manage', function () {
    $this->actingAs($this->treasurer->user)
        ->get(route('app.import.index'))
        ->assertForbidden();

    $this->actingAs($this->admin->user)
        ->get(route('app.import.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/Import')->where('upload', null));
});
