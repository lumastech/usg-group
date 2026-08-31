<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Wallets\WalletLedger;
use App\Domain\Wallets\WalletRegistry;
use App\Enums\MemberRole;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Enums\WalletKind;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ImmutableLedgerException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WalletUnavailableException;
use App\Models\Cycle;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle);

    $this->ledger = app(WalletLedger::class);
    $this->registry = app(WalletRegistry::class);

    $this->wallet = $this->registry->forMember($this->member, $this->cycle);
});

it('starts every wallet at zero', function () {
    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(0);
});

it('reads the balance as the signed sum of its entries', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $this->ledger->credit($this->wallet, Kwacha::of(250), WalletEntryType::TopUp, $this->member);
    $this->ledger->debit($this->wallet, Kwacha::of(300), WalletEntryType::Withdrawal, $this->member);

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(45_000);
});

it('stores a credit positive and a debit negative whatever sign it is handed', function () {
    $credit = $this->ledger->credit($this->wallet, Kwacha::of(-500), WalletEntryType::TopUp, $this->member);
    $debit = $this->ledger->debit($this->wallet, Kwacha::of(200), WalletEntryType::Withdrawal, $this->member);

    expect($credit->getRawOriginal('amount_ngwee'))->toBe(50_000)
        ->and($debit->getRawOriginal('amount_ngwee'))->toBe(-20_000);
});

it('refuses an entry that moves nothing', function () {
    $this->ledger->post($this->wallet, Kwacha::of(0), WalletEntryType::Adjustment, $this->member);
})->throws(DomainRuleException::class, 'non-zero');

it('refuses a debit that would take a member wallet below zero', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $this->ledger->debit($this->wallet, Kwacha::ofNgwee(50_001), WalletEntryType::Withdrawal, $this->member);
})->throws(InsufficientWalletBalanceException::class);

it('refuses to pay out of the group wallet more than the group holds', function () {
    $group = $this->registry->group($this->cycle);

    $this->ledger->debit($group, Kwacha::of(1), WalletEntryType::Payment, $this->treasurer);
})->throws(InsufficientWalletBalanceException::class);

it('lets a second debit see the first one, so a balance cannot be spent twice', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $this->ledger->debit($this->wallet, Kwacha::of(400), WalletEntryType::Withdrawal, $this->member);

    expect(fn () => $this->ledger->debit($this->wallet, Kwacha::of(400), WalletEntryType::Withdrawal, $this->member))
        ->toThrow(InsufficientWalletBalanceException::class);

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(10_000);
});

it('takes the row lock before reading the balance it is about to spend', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->ledger->debit($this->wallet, Kwacha::of(100), WalletEntryType::Withdrawal, $this->member);

    $lockIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "wallets"'));
    $sumIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'sum("amount_ngwee")'));

    expect($lockIndex)->not->toBeFalse()
        ->and($sumIndex)->not->toBeFalse()
        ->and($lockIndex)->toBeLessThan($sumIndex);
});

it('cannot edit an entry once it is written', function () {
    $entry = $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $entry->note = 'changed my mind';
    $entry->save();
})->throws(ImmutableLedgerException::class);

it('cannot delete an entry', function () {
    $entry = $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $entry->delete();
})->throws(ImmutableLedgerException::class);

it('undoes a credit with a reversing entry rather than an edit', function () {
    $credit = $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $reversal = $this->ledger->reverse($credit, $this->treasurer, 'Top-up credited in error');

    expect($reversal->type)->toBe(WalletEntryType::Reversal)
        ->and($reversal->getRawOriginal('amount_ngwee'))->toBe(-50_000)
        ->and($reversal->reverses_wallet_entry_id)->toBe($credit->id)
        ->and(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(0)
        ->and(WalletEntry::query()->acrossCycles()->count())->toBe(2);
});

it('makes a member whole when a withdrawal is reversed', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $withdrawal = $this->ledger->debit($this->wallet, Kwacha::of(500), WalletEntryType::Withdrawal, $this->member);

    $this->ledger->reverse($withdrawal, $this->treasurer, 'Transfer refused by the provider');

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(50_000);
});

it('refuses to reverse the same entry twice', function () {
    $credit = $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $this->ledger->reverse($credit, $this->treasurer);

    $this->ledger->reverse($credit, $this->treasurer);
})->throws(DomainRuleException::class, 'already been reversed');

it('refuses to reverse a reversal', function () {
    $credit = $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $reversal = $this->ledger->reverse($credit, $this->treasurer);

    $this->ledger->reverse($reversal, $this->treasurer);
})->throws(DomainRuleException::class, 'cannot itself be reversed');

it('moves nothing on a frozen wallet, in either direction', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $this->registry->freeze($this->wallet);

    expect(fn () => $this->ledger->credit($this->wallet, Kwacha::of(100), WalletEntryType::TopUp, $this->member))
        ->toThrow(WalletUnavailableException::class)
        ->and(fn () => $this->ledger->debit($this->wallet, Kwacha::of(100), WalletEntryType::Withdrawal, $this->member))
        ->toThrow(WalletUnavailableException::class);
});

it('lets a closed wallet be drained but not filled', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);
    $this->registry->close($this->wallet);

    expect(fn () => $this->ledger->credit($this->wallet, Kwacha::of(100), WalletEntryType::TopUp, $this->member))
        ->toThrow(WalletUnavailableException::class);

    $this->ledger->debit($this->wallet, Kwacha::of(500), WalletEntryType::Withdrawal, $this->member);

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(0);
});

it('still reads a closed cycle\'s balance while another cycle is pinned', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    app(CurrentCycle::class)->set(Cycle::factory()->create(['name' => '2026–2027']));

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(50_000)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});

it('writes an activity log entry naming who moved the money', function () {
    $this->ledger->credit(
        $this->wallet,
        Kwacha::of(500),
        WalletEntryType::TopUp,
        $this->treasurer,
        TransactionSource::Cash,
    );

    $activity = DB::table('activity_log')->where('log_name', 'money')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Top-up of K500.00')
        ->and(json_decode($activity->properties, true)['actor_member_id'])->toBe($this->treasurer->id);
});

it('opens exactly one wallet per member per cycle', function () {
    $again = $this->registry->forMember($this->member, $this->cycle);

    expect($again->id)->toBe($this->wallet->id)
        ->and($this->wallet->kind)->toBe(WalletKind::Member);
});

it('opens exactly one group wallet per cycle', function () {
    $first = $this->registry->group($this->cycle);
    $second = $this->registry->group($this->cycle);

    expect($second->id)->toBe($first->id)
        ->and($first->kind)->toBe(WalletKind::Group)
        ->and($first->member_id)->toBeNull();
});

it('carries a leftover balance into the next cycle as a matched pair', function () {
    $this->ledger->credit($this->wallet, Kwacha::of(500), WalletEntryType::TopUp, $this->member);

    $next = Cycle::factory()->create(['name' => '2026–2027']);
    $carried = $this->registry->carryForward($this->member, $this->cycle, $next, $this->treasurer);

    expect(Kwacha::toNgwee($this->ledger->balance($this->wallet)))->toBe(0)
        ->and(Kwacha::toNgwee($this->ledger->balance($carried)))->toBe(50_000)
        ->and((int) WalletEntry::query()->acrossCycles()->sum('amount_ngwee'))->toBe(50_000)
        ->and(WalletEntry::query()->acrossCycles()->where('type', WalletEntryType::CarryForward->value)->count())
        ->toBe(2);
});

it('carries nothing forward for a wallet that is already empty', function () {
    $next = Cycle::factory()->create(['name' => '2026–2027']);

    expect($this->registry->carryForward($this->member, $this->cycle, $next, $this->treasurer))->toBeNull();
});
