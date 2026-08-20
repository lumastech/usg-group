<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\SavingsTransactionType;
use App\Models\Cycle;
use App\Models\MemberDebt;
use App\Models\NextOfKin;
use App\Models\Payout;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $this->shareOut = function (): void {
        $this->cycle->forceFill(['status' => CycleStatus::ShareOut])->save();
    };

    /* The second signature is typed into the dialog, so the request carries credentials. */
    $this->signature = fn (): array => [
        'approver_email' => $this->chair->user->email,
        'approver_password' => 'password',
    ];
});

it('lists who is waiting to be closed out, departures first', function () {
    $leaver = memberWithRole($this->cycle, MemberRole::Member, ['status' => MemberStatus::LeftEarly]);

    $this->actingAs($this->treasurer->user)
        ->get('/app/closures')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/closures/Index')
            ->where('cycle.is_sharing_out', false)
            ->where('pending.0.member_id', $leaver->id)
            ->where('pending.0.case', 'left_early')
            ->has('settled', 0));
});

it('shows one member\'s statement with the abilities the server computed', function () {
    ($this->shareOut)();

    $this->actingAs($this->treasurer->user)
        ->get("/app/closures/{$this->member->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/closures/Show')
            ->where('payoutCase.value', 'active_share_out')
            ->where('breakdown.net_payable_ngwee', 500_000)
            ->where('abilities.execute', true)
            ->has('breakdown.lines', 7));
});

it('does not offer the execute button before the cycle reaches share-out', function () {
    $this->actingAs($this->treasurer->user)
        ->get("/app/closures/{$this->member->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.execute', false));
});

it('keeps closures out of an ordinary member\'s reach', function () {
    $this->actingAs($this->member->user)->get('/app/closures')->assertForbidden();
});

it('refuses to execute a closure without the payouts.execute permission', function () {
    ($this->shareOut)();

    $this->actingAs($this->chair->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)())
        ->assertForbidden();

    expect(Payout::count())->toBe(0);
});

it('executes a payout from the wizard and hands back a voucher', function () {
    ($this->shareOut)();

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)())
        ->assertRedirect()
        ->assertSessionHas('success');

    $payout = Payout::firstOrFail();

    expect(Kwacha::format($payout->amount_ngwee))->toBe('K5,000.00')
        ->and($payout->second_approver_member_id)->toBe($this->chair->id);

    $this->actingAs($this->treasurer->user)
        ->get("/app/payouts/{$payout->id}/voucher")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('rejects a second signature that is really the same person', function () {
    ($this->shareOut)();

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", [
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => 'password',
        ])
        ->assertSessionHasErrors('approver_email');

    expect(Payout::count())->toBe(0);
});

it('rejects a second signature from someone without the approving permission', function () {
    ($this->shareOut)();

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", [
            'approver_email' => $this->member->user->email,
            'approver_password' => 'password',
        ])
        ->assertSessionHasErrors('approver_email');
});

it('will not let a departure be posted at all before share-out', function () {
    $this->member->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)())
        ->assertForbidden();

    expect(Payout::count())->toBe(0);
});

it('surfaces a domain refusal on the dialog rather than throwing', function () {
    /* A death may be settled early, so this passes the policy and is refused by the
       domain instead — for want of the written reason the override costs. */
    $this->member->forceFill([
        'status' => MemberStatus::Deceased,
        'date_of_death' => Carbon::parse('2026-01-20'),
    ])->save();

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)())
        ->assertSessionHasErrors('approver_email');

    expect(Payout::count())->toBe(0);

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)() + [
            'early_settlement_note' => 'The family is burying her on Saturday.',
        ])
        ->assertRedirect();

    expect(Payout::count())->toBe(1);
});

it('records a debt through the wizard when the closure comes out negative', function () {
    $this->member->forceFill(['status' => MemberStatus::LeftEarly])->save();
    ($this->shareOut)();

    /* Wipe the savings out so the position is under water without a loan in play. */
    app(SavingsLedger::class)->record(
        $this->member->refresh(),
        $this->december,
        Kwacha::of(-6_000),
        $this->treasurer,
        SavingsTransactionType::Adjustment,
    );

    $this->actingAs($this->treasurer->user)
        ->post("/app/closures/{$this->member->id}", ($this->signature)())
        ->assertRedirect();

    expect(Payout::count())->toBe(0)
        ->and(Kwacha::format(MemberDebt::firstOrFail()->amount_owed_ngwee))->toBe('K1,000.00');
});

it('shows a deceased member\'s nominated next of kin for the arrangement step', function () {
    $kin = NextOfKin::factory()->create(['member_id' => $this->member->id]);

    $this->member->forceFill([
        'status' => MemberStatus::Deceased,
        'date_of_death' => Carbon::parse('2026-01-20'),
    ])->save();

    $this->actingAs($this->treasurer->user)
        ->get("/app/closures/{$this->member->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nextOfKin.0.id', $kin->id)
            ->where('abilities.settleEarly', true));
});
