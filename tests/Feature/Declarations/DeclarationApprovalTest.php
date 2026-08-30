<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Enums\DeclarationStatus;
use App\Enums\MemberRole;
use App\Exceptions\DeclarationLockedException;
use App\Exceptions\DeclarationNotApprovedException;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The three steps between a member's promise and their money: the member declares,
 * the committee asks, and only then may either side start the payment.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);
    $this->service = app(DeclarationService::class);

    $this->member = Member::factory()->for($this->cycle)->create();

    $this->declaration = $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
    );
});

/** Signs in as a committee office, with a member record of its own. */
function askingAs(MemberRole $role): Member
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $member = Member::factory()->for(test()->cycle)->create(['user_id' => $user->id]);

    test()->actingAs($user);

    return $member;
}

it('opens a declaration as a request nobody has asked for yet', function () {
    expect($this->declaration->status)->toBe(DeclarationStatus::Submitted)
        ->and($this->declaration->isApproved())->toBeFalse()
        ->and($this->declaration->status->isEditable())->toBeTrue();
});

it('marks an approved declaration pending payment and stamps who asked', function () {
    $treasurer = Member::factory()->for($this->cycle)->create();

    $approved = $this->service->approve($this->declaration, $treasurer);

    expect($approved->status)->toBe(DeclarationStatus::Approved)
        ->and($approved->status->label())->toBe('Pending payment')
        ->and($approved->isApproved())->toBeTrue()
        ->and($approved->approved_by_member_id)->toBe($treasurer->id)
        ->and($approved->status->isEditable())->toBeFalse();
});

it('will not let the member change a declaration that has been approved', function () {
    $this->service->approve($this->declaration, Member::factory()->for($this->cycle)->create());

    expect(fn () => $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(1000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
    ))->toThrow(DeclarationLockedException::class, 'waiting to be paid');

    expect($this->declaration->refresh()->getRawOriginal('saving_amount_ngwee'))->toBe(50_000);
});

it('hands a declaration back to the member when it is reopened', function () {
    $this->service->approve($this->declaration, Member::factory()->for($this->cycle)->create());

    $reopened = $this->service->reopen($this->declaration);

    expect($reopened->status)->toBe(DeclarationStatus::Submitted)
        ->and($reopened->isApproved())->toBeFalse()
        ->and($reopened->approved_by_member_id)->toBeNull();

    $this->service->submit(
        $this->member,
        $this->january,
        Kwacha::of(1000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
    );

    expect($this->declaration->refresh()->getRawOriginal('saving_amount_ngwee'))->toBe(100_000);
});

it('keeps the approval when the month is locked for trading', function () {
    $this->service->approve($this->declaration, Member::factory()->for($this->cycle)->create());
    $this->service->lockMonth($this->january);

    expect($this->declaration->refresh())
        ->status->toBe(DeclarationStatus::Locked)
        ->isApproved()->toBeTrue();
});

it('locks a declaration nobody approved without making it payable', function () {
    $this->service->lockMonth($this->january);

    expect($this->declaration->refresh())
        ->status->toBe(DeclarationStatus::Locked)
        ->isApproved()->toBeFalse();

    expect(fn () => $this->service->assertPayable($this->member, $this->january))
        ->toThrow(DeclarationNotApprovedException::class, 'has not been approved yet');
});

it('lets the committee approve a declaration that is already locked', function () {
    $this->service->lockMonth($this->january);

    $approved = $this->service->approve($this->declaration->refresh(), Member::factory()->for($this->cycle)->create());

    expect($approved->status)->toBe(DeclarationStatus::Locked)
        ->and($approved->isApproved())->toBeTrue()
        ->and($this->service->assertPayable($this->member, $this->january)->id)->toBe($approved->id);
});

it('refuses to approve the same declaration twice', function () {
    $second = Member::factory()->for($this->cycle)->create();
    $this->service->approve($this->declaration, $second);

    expect(fn () => $this->service->approve($this->declaration->refresh(), $second))
        ->toThrow(DomainRuleException::class, 'already been approved');
});

it('lets an office holding the ask approve from the sheet', function (MemberRole $role) {
    askingAs($role);

    $this->post(route('app.declarations.approve', $this->declaration))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->declaration->refresh()->status)->toBe(DeclarationStatus::Approved);
})->with([MemberRole::Admin, MemberRole::Treasurer, MemberRole::Chairperson]);

it('keeps a plain member from approving a declaration', function () {
    askingAs(MemberRole::Member);

    $this->post(route('app.declarations.approve', $this->declaration))->assertForbidden();

    expect($this->declaration->refresh()->isApproved())->toBeFalse();
});

it('reopens a declaration from the sheet', function () {
    askingAs(MemberRole::Treasurer);
    $this->service->approve($this->declaration, Member::factory()->for($this->cycle)->create());

    $this->delete(route('app.declarations.reopen', $this->declaration))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->declaration->refresh()->status)->toBe(DeclarationStatus::Submitted);
});

it('shows the committee sheet which declarations are still waiting to be asked for', function () {
    askingAs(MemberRole::Treasurer);

    $this->get(route('app.declarations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/declarations/Index')
            ->where('abilities.approve', true)
            ->where('sheet.rows', fn ($rows): bool => collect($rows)
                ->contains(fn (array $row): bool => $row['member_id'] === $this->member->id
                    && $row['declared'] === true
                    && $row['approved'] === false)));
});

it('tells the member their declaration is approved and waiting for payment', function () {
    $user = User::factory()->create();
    $user->assignRole(MemberRole::Member->value);
    $this->member->forceFill(['user_id' => $user->id])->save();

    $this->service->approve($this->declaration, Member::factory()->for($this->cycle)->create());

    $this->actingAs($user)
        ->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Declarations')
            ->where('declaration.approved', true)
            ->where('declaration.status_label', 'Pending payment')
            ->where('declaration.abilities.update', false));
});
