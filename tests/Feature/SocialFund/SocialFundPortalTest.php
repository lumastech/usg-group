<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\FuneralRelationship;
use App\Enums\GrantClaimStatus;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\DiasporaApportionmentItem;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    $this->password = 'password';

    /* Enough in the fund to pay a grant out of. */
    Member::factory()->count(10)->for($this->cycle)->create()
        ->each(fn (Member $payer) => app(SocialFundContributions::class)
            ->record($payer, Kwacha::of(250), $this->treasurer));
});

it('shows the fund dashboard to the committee', function () {
    $this->actingAs($this->treasurer->user)
        ->get('/app/fund')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/fund/Index')
            ->where('overview.balance_ngwee', 250_000)
            ->has('unpaid')
            ->where('abilities.record', true));
});

it('keeps the fund out of an ordinary member\'s reach', function (string $url) {
    $this->actingAs($this->member->user)->get($url)->assertForbidden();
})->with(['/app/fund', '/app/fund/ledger', '/app/fund/claims', '/app/fund/apportionment']);

it('records a contribution through the portal', function () {
    $payer = Member::factory()->for($this->cycle)->create();

    $this->actingAs($this->treasurer->user)
        ->post('/app/fund/contributions', [
            'member_id' => $payer->id,
            'amount_ngwee' => 25_000,
        ])
        ->assertRedirect();

    expect(app(SocialFundContributions::class)->hasPaid($payer))->toBeTrue();
});

it('rejects a contribution that is not exactly two hundred and fifty kwacha', function () {
    $payer = Member::factory()->for($this->cycle)->create();

    $this->actingAs($this->treasurer->user)
        ->post('/app/fund/contributions', [
            'member_id' => $payer->id,
            'amount_ngwee' => 20_000,
        ])
        ->assertSessionHasErrors('amount_ngwee');

    expect(app(SocialFundContributions::class)->hasPaid($payer))->toBeFalse();
});

it('refuses to record a contribution without the recording permission', function () {
    $this->actingAs($this->member->user)
        ->post('/app/fund/contributions', [
            'member_id' => $this->member->id,
            'amount_ngwee' => 25_000,
        ])
        ->assertForbidden();
});

it('refuses an outflow whose second credentials do not check out', function () {
    $balance = Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle));

    $this->actingAs($this->chair->user)
        ->post('/app/fund/entries', [
            'type' => 'gathering_expense',
            'amount_ngwee' => -10_000,
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => 'not-the-password',
        ])
        ->assertSessionHasErrors('approver_password');

    expect(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe($balance);
});

it('posts a gathering expense behind two signatures', function () {
    $this->actingAs($this->chair->user)
        ->post('/app/fund/entries', [
            'type' => 'gathering_expense',
            'amount_ngwee' => -10_000,
            'note' => 'End of year gathering',
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => $this->password,
        ])
        ->assertRedirect();

    expect(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe(240_000);
});

it('walks a funeral claim from submitted to paid', function () {
    $this->actingAs($this->treasurer->user)
        ->post('/app/fund/claims/funeral', [
            'member_id' => $this->member->id,
            'deceased_name' => 'Agnes Phiri',
            'relationship' => FuneralRelationship::Parent->value,
            'claim_date' => '2026-08-01',
        ])
        ->assertRedirect();

    $claim = FuneralGrantClaim::firstOrFail();

    expect(Kwacha::toNgwee($claim->amount_ngwee))->toBe(100_000);

    $this->actingAs($this->chair->user)
        ->post("/app/fund/claims/funeral/{$claim->id}/approve", [
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => $this->password,
        ])
        ->assertRedirect();

    expect($claim->fresh()->status)->toBe(GrantClaimStatus::Approved);

    $this->actingAs($this->chair->user)
        ->post("/app/fund/claims/funeral/{$claim->id}/pay", [
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => $this->password,
        ])
        ->assertRedirect();

    expect($claim->fresh()->status)->toBe(GrantClaimStatus::Paid)
        ->and(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe(150_000);
});

it('will not accept a funeral claim for a sibling', function () {
    $this->actingAs($this->treasurer->user)
        ->post('/app/fund/claims/funeral', [
            'member_id' => $this->member->id,
            'deceased_name' => 'Joseph Phiri',
            'relationship' => 'sibling',
            'claim_date' => '2026-08-01',
        ])
        ->assertSessionHasErrors('relationship');

    expect(FuneralGrantClaim::count())->toBe(0);
});

it('lets a member raise and track their own claim', function () {
    $this->actingAs($this->member->user)
        ->post('/my/fund/claims/baby', [
            'member_id' => $this->member->id,
            'child_name' => 'Chanda',
            'born_on' => '2026-07-01',
            'claim_date' => '2026-08-01',
        ])
        ->assertRedirect();

    expect(UnityBabyClaim::where('member_id', $this->member->id)->count())->toBe(1);

    $this->actingAs($this->member->user)
        ->get('/my/fund')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('my/Fund')
            ->where('contribution.paid', false)
            ->has('babyClaims', 1));
});

it('refuses a member claiming on somebody else\'s behalf', function () {
    $this->actingAs($this->member->user)
        ->post('/my/fund/claims/funeral', [
            'member_id' => $this->chair->id,
            'deceased_name' => 'Agnes Phiri',
            'relationship' => FuneralRelationship::Parent->value,
            'claim_date' => '2026-08-01',
        ])
        ->assertSessionHasErrors('member_id');

    expect(FuneralGrantClaim::count())->toBe(0);
});

it('previews and confirms a diaspora split, then debits on each transfer', function () {
    Member::factory()->count(2)->for($this->cycle)->create(['is_diaspora' => true]);

    $this->actingAs($this->treasurer->user)
        ->postJson('/app/fund/apportionment/preview', ['total_ngwee' => 100_001])
        ->assertOk()
        ->assertJsonPath('share_ngwee', 50_000)
        ->assertJsonPath('remainder_ngwee', 1);

    $this->actingAs($this->chair->user)
        ->post('/app/fund/apportionment', [
            'total_ngwee' => 100_001,
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => $this->password,
        ])
        ->assertRedirect();

    /* Declaring the split moves no money. */
    expect(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe(250_000);

    $item = DiasporaApportionmentItem::firstOrFail();

    $this->actingAs($this->treasurer->user)
        ->post("/app/fund/apportionment/items/{$item->id}/confirm", ['reference' => 'MTN-1'])
        ->assertRedirect();

    expect(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe(200_000);
});
