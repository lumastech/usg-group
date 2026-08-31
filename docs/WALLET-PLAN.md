# Member wallets — implementation plan

A ledger of member balances between the group and the payment provider, so that every
payment inside the system becomes a transfer between two wallets and the gateway is
reduced to two jobs: putting money into a wallet, and taking money out of one.

Nothing here changes what the ledgers mean. Savings are still savings, the Social Fund
is still the group's money, and every rule already written — K500 increments, the
September cap, the trading-day posting order, the two-signature payout, immutable
entries — applies unchanged. The wallet sits *between* the provider and those ledgers,
and it is the only thing the provider is allowed to touch.

---

## 0. The problem this solves

Every integration failure the system has hit shares one shape: **money moved at the
provider, and the ledger then refused to record it.** The refusal is correct — a K750
contribution in a K500-increment month must be refused — but by then the money has
left the member's wallet and somebody has to decide about a refund, a reclassification
or a hold. `PaymentPoster` names this outcome `NeedsAttention`, and it is deliberately
never retried automatically, because none of those answers are a machine's to pick.

The failure classes in the code today, all of them the same shape:

| Where | What happens now |
| --- | --- |
| `PaymentPoster::post()` | any ledger refusal parks settled money at `NeedsAttention` with the exception's message |
| `PaymentPoster::markOnTradingSheet()` | no open trading session → `PaymentDeferredException`, money settled and waiting |
| same | session open but the member never declared → `NeedsAttention`; the sheet is built from declarations and inventing a row is not ours to do |
| `CollectionInitiator` | every domain rule re-checked *before* the request, because checking afterwards is too late — ~200 lines whose only job is to avoid this |
| `PaymentIntent::effectiveDate()` | a payment made at 23:50 on the 7th and processed on the 8th must allocate on the 7th, or the member is charged a penalty for our queue depth |
| `Declaration::standingPayment()`, `assertFundContributionCollectable()` | a second prompt against a live one takes the money twice, so every collection needs its own "is one already standing" guard |

With wallets, **a top-up is always acceptable.** There is no rule under which the group
will not take money into a member's own wallet, so the whole class disappears: the
provider can only ever succeed or fail, and a failure leaves nothing half-done.

The rules do not go away — they move to the wallet-to-wallet transfer, where a refusal
costs nothing because no money has moved and the member is still holding it.

**What this does not fix.** The card widget stalling at Lenco's hosted page is an
account/origin problem and stays exactly as it is — it just moves to the top-up screen.
Provider outages, fees, and the mobile-money prompt that nobody approves are all
unchanged. This plan removes a class of *reconciliation* failure, not a class of
*provider* failure, and it adds new obligations of its own (§9).

---

## 1. The one architectural rule

> **A wallet holds only money that is not yet committed to a ledger.**

The moment money becomes savings, it leaves the wallet. Savings are locked until
share-out by the constitution; a wallet balance is not. If the two were the same
balance, the wallet would be a savings account the member could drain, and the group
would be running a deposit business it never agreed to run.

So a wallet balance is one of exactly three things:

1. money topped up and not yet paid to the group,
2. money the group has paid the member — a payout, a grant, a loan disbursement — and
   not yet withdrawn,
3. money returned by a failed withdrawal.

Everything else lives where it lives today. `SavingsLedger`, `SocialFundLedger`,
`LoanLedger` and the payout tables remain the book of record for *what the money is
for*; the wallet records only *where the money is standing*.

---

## 2. Money map, before and after

| Movement | Today | With wallets |
| --- | --- | --- |
| Member pays savings | Lenco collection → trading sheet → conclude | top-up (once) → **internal transfer** → trading sheet → conclude |
| Member pays declaration | Lenco collection for the exact approved figure | **internal transfer** |
| Joining fee, social fund K250 | Lenco collection per purpose | **internal transfer** |
| Loan repayment | Lenco collection → `LoanRepaymentService` | **internal transfer** |
| Cash at the trading table | treasurer records into the ledger directly | treasurer records a **cash top-up**, then the same internal transfer |
| Loan disbursement | Lenco transfer, posts on confirmation | **internal transfer** group → member; member withdraws when they want it |
| Funeral / unity baby grant | Lenco transfer, two signatures | **internal transfer** group → member, two signatures unchanged |
| Diaspora apportionment | Lenco transfer per item | **internal transfer** per item |
| Payout / share-out | Lenco transfer to a payout destination | **internal transfer** group → member, then member withdraws |
| Member takes money out | — (never possible) | **withdrawal**: Lenco transfer, or cash from a treasurer |

Two provider rails remain. Everything else becomes a database transaction.

The prize is in the third column of rows 1–5: **one internal payment path**, exercised
identically whether the money arrived by card, by mobile money, or as a banknote on the
table. The rail is now only how the wallet got funded.

---

## 3. Data model

### `wallets`

| Column | Notes |
| --- | --- |
| `id`, `cycle_id` | scoped like everything else |
| `member_id` | null for the group wallet |
| `kind` | `WalletKind::Member \| Group` |
| `status` | `Open \| Frozen \| Closed` |
| `opened_at`, `closed_at` | |

One member wallet per member per cycle, created with the member. Exactly one group
wallet per cycle.

**Why cycle-scoped, when a balance can outlive a cycle.** Every other table in the
system carries `cycle_id` and a global scope, and a wallet that broke that convention
would be the one table nobody remembers to filter. The cycle genuinely does end with
everybody paid out, so the normal case is a wallet that closes at zero. A non-zero
balance at rollover is carried by an explicit paired `CarryForward` entry into the next
cycle's wallet (§9), and a member who does not rejoin can still withdraw from the closed
cycle's wallet — withdrawal reads `acrossCycles()` on purpose.

### `wallet_entries` — the ledger

| Column | Notes |
| --- | --- |
| `id`, `cycle_id`, `wallet_id` | |
| `amount_ngwee` | **signed** BIGINT: credits positive, debits negative. Balance is a plain `SUM`; nothing caches it |
| `type` | `TopUp \| Payment \| Receipt \| Withdrawal \| Fee \| Reversal \| CarryForward \| Adjustment` |
| `transfer_id` | the pair this entry belongs to, null for external legs |
| `payment_intent_id` | **unique**, nullable — one provider payment can credit a wallet once and only once |
| `counterparty_wallet_id` | the other side, for the statement |
| `posted_ledger_type`, `posted_ledger_id` | morph to the `SavingsTransaction` / `SocialFundTransaction` / `LoanTransaction` the payment produced |
| `occurred_on`, `note`, `recorded_by_member_id` | |

Immutable, like every other ledger in this system: `ImmutableLedgerException` in
`booted()`, corrections are `Reversal` entries referencing the entry they undo. This is
the same guard `SavingsTransaction` and `SocialFundTransaction` already carry.

### `wallet_transfers` — the pair

| Column | Notes |
| --- | --- |
| `id`, `cycle_id` | |
| `from_wallet_id`, `to_wallet_id` | |
| `amount_ngwee` | positive |
| `purpose` | `WalletTransferPurpose` — savings, declaration settlement, joining fee, social fund, loan repayment, loan disbursement, payout, share-out, funeral grant, unity baby grant, diaspora apportionment |
| `payable_type`, `payable_id` | morph to Declaration, Loan, Payout, claim… |
| `approved_by_member_id`, `second_approver_member_id` | for the transfers that need two |
| `created_by_member_id`, `occurred_at` | |

A transfer writes exactly two entries, in one `DB::transaction`, and never one without
the other. That pairing is the double-entry guarantee and the reason the invariant in
§8 can hold at all.

### Invariants

1. `SUM(wallet_entries.amount_ngwee)` across every wallet in every cycle equals the
   group's real cash — the provider account balance plus the cash tin — net of
   withdrawals in flight.
2. No member wallet balance is ever negative.
3. The group wallet balance equals what the group holds and has not yet paid out. It
   may not go negative either: paying out money the group does not hold is the failure
   this whole design exists to make impossible.
4. `SUM(member wallet balances)` is a liability, not group funds. It never appears in
   the Social Fund balance or the savings pool.

Invariant 1 is checked by `unity:reconcile-wallets` (§8) and is the single strongest
audit control the system will have.

---

## 4. Domain services

New, in `app/Domain/Wallets/`:

| Service | Responsibility |
| --- | --- |
| `WalletLedger` | the single door. `credit()`, `debit()`, `balance()`, `statement()`. Enforces non-negative balances and immutability here, never at the call sites — exactly as `SocialFundLedger::post()` does today |
| `WalletTransfer` | one internal transfer: locks both wallets, checks the balance, writes the pair, calls the domain service that records what the money was *for* |
| `TopUpService` | credits a wallet from a settled provider collection, or from cash counted by a treasurer |
| `WithdrawalService` | debits a wallet and hands the transfer to the provider; reverses on definite failure |
| `WalletReconciler` | invariant 1, per day and on demand |

Changes to what exists:

| Service | Change |
| --- | --- |
| `PaymentPoster` | the collection branch collapses to one line: a settled collection credits a wallet. `markOnTradingSheet`, `postJoiningFee`, `postRepayment`, `postFundContribution` and `PaymentDeferredException` all go |
| `CollectionInitiator` | reduces to one method: start a top-up. Every `assert*Collectable` guard moves to `WalletTransfer` |
| `TransferInitiator` | reduces to withdrawals. Loan disbursement, payout, grant and apportionment transfers become internal |
| `TradingSessionService::markReceived()` | called by `WalletTransfer` rather than by the poster. The deferred and no-row branches disappear: the transfer is refused synchronously, before anything moves |
| `PayoutExecutor` | **unchanged**, per the standing rule. It still performs the single irreversible act; what changes is that the money it settles lands in a wallet instead of waiting for a transfer |
| `GrantClaimService`, `LoanDisbursementQueue`, `DiasporaApportionment` | pay into a member wallet instead of initiating a provider transfer. Two-signature rules unchanged |
| `ShareOutPaymentRunner` | becomes a *withdrawal* batch: the payouts are already in wallets, so the balance check before the first transfer is now against known liabilities rather than a schedule |

`TradingConcluder::conclude()` is untouched. The posting order inside it is the
constitution's, and a wallet payment marks a row exactly as cash does — it takes its
turn and posts when the month is concluded.

---

## 5. The two rails

### In — top-up

1. Member (or a treasurer on their behalf) starts a top-up for any amount ≥ the
   provider minimum. **No domain rule is consulted.**
2. `PaymentPurpose::WalletTopUp`, mobile money or the hosted widget, exactly as today.
3. Settlement — webhook, poll, or verify — credits the wallet through `TopUpService`,
   idempotent by the unique `payment_intent_id` on the entry.
4. A failed or abandoned top-up credits nothing and needs no decision from anybody.

Cash at the table is the same step without a provider: the treasurer records a
`TopUp` entry with `TransactionSource::Cash`, activity-logged, which is exactly the
authority a treasurer already has when recording a cash contribution today.

### Out — withdrawal

1. Member requests a withdrawal to a `PayoutDestination`. All four destination controls
   apply unchanged — provider name resolution, name-match score, the 48-hour cooling-off
   second signature, and notification to the contacts held *before* the change. This is
   still the highest-value attack in the system and nothing here relaxes it.
2. The wallet is **debited on initiation**, not on confirmation. A member cannot spend
   the same balance twice while a transfer is in flight, and immutable-ledger convention
   says the undo is a reversing entry, not a deletion.
3. Definite failure → `Reversal` credit, member whole again, free to retry.
4. `outcomeUnknown` (a timeout) → `NeedsAttention`, never an automatic reversal. Money
   may have left the group's account; that is escalated to a person, exactly as
   `TransferInitiator` treats it today.
5. Cash out is a treasurer-recorded `Withdrawal` debit with a second signature above a
   threshold the committee sets (§7).

---

## 6. Concurrency, idempotency, solvency

| Risk | Control |
| --- | --- |
| Double-spend from one wallet | `SELECT … FOR UPDATE` on both wallet rows inside the transfer's transaction; the balance is read *inside* the lock, never before it |
| A replayed webhook crediting twice | unique index on `wallet_entries.payment_intent_id` |
| A transfer writing one leg | both entries in one `DB::transaction`, and `WalletLedger` is the only writer |
| Crediting money that never settled | a wallet is credited only from `Settled`, through `PaymentPoster`'s existing conditional-UPDATE claim |
| Paying out money the group does not hold | group wallet may not go negative; `WithdrawalService` also checks the provider balance before the first transfer of a batch |
| Two concurrent withdrawals draining a wallet | same row lock; the second sees the first's debit |

The one place the current design is *safer* than the new one: today, money cannot be
paid out of a member's wallet because there is no such thing. Wallets introduce an
internal balance that must always be backed by real cash, and invariant 1 is the only
thing standing between the group and a float that quietly does not exist. It must be
checked daily and alarmed on, not computed on request and forgotten.

---

## 7. Decisions the committee must make first

These are policy, not engineering, and Phase 1 should not start until they are answered.
**Minuted 2026-08-31; the answers live in `config/wallets.php` so the committee can move
them without a deploy.**

1. **Who bears the withdrawal fee?** Today transfer fees are the group's and are never
   netted off a payout, because share-out is paid to the exact ngwee. With wallets the
   payout is exact *into the wallet*, and the fee only arises when the member chooses to
   take it out. Recommendation: the member bears it, as a separate `Fee` debit — a
   member who withdraws four times should not be paid for by one who withdraws once.
   The alternative is the group absorbing it as an expense.
2. **May a member withdraw freely, or only after share-out?** The wallet holds only
   uncommitted money (§1), so free withdrawal is defensible. But a member who tops up
   K500 and withdraws it the next day has used the group as a money-transfer service,
   at the group's cost in fees.
3. **What may a treasurer pay out in cash, alone?** A threshold above which a second
   signature is required, mirroring the fund's negative-entry rule.
4. **Minimum withdrawal**, so the fee is never a large fraction of the amount.
5. **Dormant balances at cycle end** — carried forward, or paid out by force at
   share-out?

### Answers

| # | Decision | Where it lives |
| --- | --- | --- |
| 1 | **The member bears the withdrawal fee**, as a separate `Fee` debit beside the withdrawal. Share-out is still paid to the exact ngwee *into* the wallet | `wallets.withdrawals.fee_bearer` |
| 2 | **A member may withdraw freely, at any time.** The wallet holds only uncommitted money (§1) and it is the member's | `wallets.withdrawals.allowed_from` |
| 3 | **Every cash payment out of a wallet carries two signatures**, whatever the amount — stricter than the fund's threshold rule, because a cash-out leaves no provider record at all | `wallets.withdrawals.cash_requires_second_signature` |
| 4 | **Minimum withdrawal K50.** Not put to the committee; set at a level where a provider fee is a small fraction of the amount, and trivially changed | `wallets.withdrawals.min_ngwee` |
| 5 | **Balances carry forward** into the next cycle by a paired `CarryForward` entry (§9). A member who does not rejoin still withdraws from the closed cycle's wallet | `wallets.rollover.carry_forward` |

---

## 8. Reconciliation

`unity:reconcile-wallets` (daily, beside the existing reconciliation run):

```
sum(all wallet balances)  ==  provider balance  +  cash tin  −  withdrawals in flight
```

A mismatch is an alarm, not a report. It is also the first check in the system that
catches a fraud requiring no ledger tampering at all: a wallet credited without money
behind it shows up here the next morning, and nowhere else.

A second, weaker check ties the wallet layer to the ledgers:

```
group wallet balance  ==  savings pool + social fund + undisbursed loans + unpaid payouts
```

Both belong on the committee dashboard, and `PaymentReconciliation` already has the
shape to hold them.

---

## 9. What this breaks, and the edges

| Edge | Answer |
| --- | --- |
| **Members with no login** | Most of the group. A treasurer operates the wallet on their behalf at the table: cash top-up, internal transfer, cash withdrawal. The wallet is not a smartphone feature |
| **Ledger freeze** | `LedgerFreeze` stops a settled member's ledgers moving. The wallet must **not** ask that gate — a frozen member is precisely the one who needs to withdraw their payout. The ledgers the wallet feeds already ask it, which is where the protection belongs. This needs recording as a rule, because the existing rule says any new ledger writing against a member must ask |
| **Cycle rollover** | A non-zero balance moves by a paired `CarryForward` entry: debit the old wallet, credit the new. Never a silent copy — the balance must be derivable from entries in both cycles |
| **A member who leaves mid-cycle** | Wallet stays open and withdrawable; `MemberStatus` gates contributions, not their own money |
| **Existing in-flight payments at cutover** | Drain on the old path. No historical rewrite, no backfill: every wallet opens at zero and the ledgers keep their history |
| **Reporting** | Every member-facing screen gains a balance, and `/my/payments` becomes a wallet statement. `docs/CONVENTIONS.md` needs the wallet added to the money map |

---

## 10. Phasing

Each phase ships on its own and leaves the system working.

| Phase | Scope | Done when | Status |
| --- | --- | --- | --- |
| **0** | The five decisions in §7, minuted | recorded in this document | **done** — §7 |
| **1** | `wallets`, `wallet_entries`, `wallet_transfers`, `WalletLedger`, enums, factories. No rail touched; every balance is zero | ledger unit tests: immutability, signed sum, non-negative, reversal, concurrent debit under lock | **done** — `WalletLedgerTest` |
| **2** | Top-up rail: `PaymentPurpose::WalletTopUp`, `TopUpService`, cash top-up by a treasurer, member top-up screen. Collections still post the old way for now | a settled top-up credits exactly once under a replayed webhook; a failed one credits nothing | **done** — `TopUpTest`, `/my/wallet` |
| **3** | Internal payments: `WalletTransfer` + savings, declaration, joining fee, social fund, repayment. Every `assert*Collectable` guard moves here. Old per-purpose collections retired | the existing domain tests pass against wallet transfers instead of collections; `PaymentDeferredException` deleted | **built** — `WalletTransferService`, `WalletPayments`, `WalletPaymentsTest`. Retirement and the exception's deletion are held for Phase 6 |
| **4** | Money out, internally: disbursements, grants, apportionment, payouts and share-out credit member wallets. Gateway drops out of all of them | `ShareOutBatchRunner` settles into wallets; two-signature rules unchanged and still tested | **built** — `WalletDisbursements`, `WalletShareOutRunner::credit()`. Old `TransferInitiator` path still stands beside it |
| **5** | Withdrawal rail: request, destination controls, debit-on-initiation, reversal, cash-out, share-out withdrawal batch | a failed transfer leaves the member whole; an unknown outcome escalates and never auto-reverses | **done** — `WithdrawalService`, `WalletShareOutRunner::withdrawAll()`, `WithdrawalTest` |
| **6** | `unity:reconcile-wallets`, dashboard invariants, dead code removed (`CollectionInitiator` guards, purpose-specific posting, `PaymentPoster` collection branches) | the daily invariant runs green for a week before the old code goes | **half** — the command, the schedule, the dashboard tile and the committee screen are in. The deletions are not, by the rule in the right-hand column |

### What is deliberately still standing

Phases 3, 4 and 5 are built **beside** the old rails, not on top of them.
`CollectionInitiator`'s per-purpose methods, `TransferInitiator`, `ShareOutPaymentRunner`
and `PaymentPoster`'s purpose-specific branches all still work exactly as they did, and
`PaymentDeferredException` still exists because they still raise it. That is Phase 6's
own condition: *the daily invariant runs green for a week before the old code goes.*
Deleting it on the same day the wallet layer was written would be removing the fallback
before anything had proved the replacement.

Two things to know while both rails are live. Nothing routes to the wallet path
automatically — a screen or a caller has to choose `WalletPayments` over
`CollectionInitiator` — so the old behaviour is unchanged until each call site is moved.
And a member could in principle pay the same thing twice, once down each rail; the
trading sheet accumulates it as it would two cash payments, so it is a variance for the
table rather than a corruption, but it is a reason to move call sites promptly rather
than leaving both open for a whole cycle.

### One amendment to §9

§9 says every wallet opens at zero and there is no backfill. That is right for member
wallets and wrong for the group's: the group's account already holds money, so a group
wallet at zero would make invariant 3 false on day one and the first loan disbursement
would be refused for money the group demonstrably has.

`WalletRegistry::recordOpeningFloat()` records that once, as an `Adjustment` credit with
`TransactionSource::Import` — the same idea as `SavingsTransactionType::ImportOpening`,
an opening balance rather than a rewritten history. It refuses to run twice. Nothing
about member wallets changes: they open at zero and stay there until somebody puts money
in, because nothing is owed to anybody yet.

### On invariant 2

Invariant 1 is exact and is alarmed on. The second check in §8 — group wallet against
`savings pool + social fund + undisbursed loans + unpaid payouts` — is computed and shown
but **not** alarmed on, because the group wallet opens at a recorded float rather than
being derived from the ledgers' whole history. It will drift by construction until a
cycle has run start to finish on wallets, and an alarm that is always ringing is not an
alarm. It should be promoted once a full cycle's figures are available to calibrate it.

Phases 3 and 4 are the ones that touch the constitution's rules. Neither changes a rule;
both move where it is enforced, and the existing tests are the proof — if a savings test
has to be weakened to make a wallet transfer pass, the transfer is wrong.

---

## 11. Honest risks

- **The float becomes real money the group owes.** Today the ledgers describe money
  already committed. A wallet balance is a promise to pay on demand, and invariant 1 is
  the only thing keeping it honest.
- **Character of the arrangement.** A group holding withdrawable balances for members
  looks more like deposit-taking than a savings club does. §1 is the mitigation — the
  wallet is a staging account, never a savings account — and it should be written into
  the constitution rather than only into this codebase.
- **More moving parts, not fewer.** Two new tables, five services, a daily invariant.
  The bet is that one well-guarded internal path is cheaper than seven purpose-specific
  paths through a network boundary. That bet is only won if Phase 6 actually deletes the
  old code.
- **This does not fix the widget.** Resolve the Lenco card/origin problem regardless;
  top-ups depend on the same rail.
