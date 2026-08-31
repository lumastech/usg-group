---
paths:
  - 'app/Domain/Wallets/**'
---

# Wallets

## The wallet must NOT ask LedgerFreeze
Every ledger that writes against a member calls `LedgerFreeze::assertOpen` first (see .ai/rules/domain.md). `WalletLedger` deliberately does not, and `WithdrawalService::assertWithdrawable` does not either.

A member whose ledgers are frozen has been paid out — they are precisely the person who needs to withdraw what they were paid. The protection belongs on the ledgers the wallet feeds (SavingsLedger, LoanLedger, SocialFundLedger), which ask the gate themselves, so a frozen member's savings still cannot move even though their wallet can.

This is the one documented exception to the "any new ledger writing against a member must ask the same gate" rule. Do not "fix" it. Covered by the test "lets a member whose ledgers are frozen withdraw what they were paid".

## A wallet holds only money not yet committed to a ledger
The moment money becomes savings it LEAVES the wallet. Savings are locked until share-out by the constitution and a wallet balance is not — if they were the same balance, the wallet would be a savings account the member could drain and the group would be running a deposit business it never agreed to run.

A wallet balance is exactly one of three things: money topped up and not yet paid to the group, money the group has paid the member and they have not yet withdrawn, or money a failed withdrawal put back. `SUM(member wallet balances)` is a LIABILITY — it never appears in the social fund balance or the savings pool.

Balances are never stored. `Wallet::balanceNgwee()` and `WalletLedger::balanceNgwee()` sum the signed `wallet_entries.amount_ngwee`, and both read `acrossCycles()` on purpose: a member who does not rejoin still withdraws from the closed cycle's wallet, and a pinned current cycle must not make that balance read as zero.

## A top-up is always acceptable; the rules live on the transfer
`CollectionInitiator::topUp()` consults NO domain rule, deliberately. There is no rule under which the group will not take money into a member's own wallet, so a top-up can only succeed or fail and a failure leaves nothing half-done — no settled payment parked at `NeedsAttention` for somebody to choose between a refund, a reclassification and a hold.

Every rule that used to be checked before a provider request went out now sits on the wallet-to-wallet transfer instead — `WalletPayments` — where a refusal costs nothing because the money is still the member's. The rules themselves are UNCHANGED: a K750 contribution is refused by the same `SavingsLedger` that refuses cash at the table. If a savings test has to be weakened to make a wallet transfer pass, the transfer is wrong.

Never add a domain check to the top-up path. That reintroduces the exact failure the wallet layer exists to remove.

## Withdrawals debit on initiation and never auto-reverse a timeout
`WithdrawalService::request()` debits the wallet BEFORE the provider is called. Debiting on confirmation would let a member start four withdrawals against one balance and have all four succeed, and nothing would catch it until the money was gone.

Two outcomes, deliberately distinct, mirroring `PaymentGatewayException::$outcomeUnknown`:
- a refusal the provider actually sent → `refund()` writes Reversal credits, the member is whole and free to retry;
- a timeout (`outcomeUnknown`) → the debit STANDS. Money may have left the group's account and nobody can tell from here; it is escalated to a person, never reversed by a machine.

`reverseFailed()` is swept from `unity:poll-payments` rather than hooked to the status change, because the refusal can arrive by webhook, poll or browser callback and a member owed their own money must not depend on which. It is idempotent and deliberately excludes `NeedsAttention`.

The member bears the fee (committee decision, `config/wallets.php`). The real fee is only known after the money has gone, so an estimate is debited up front as a `Fee` entry and squared up by `settleFee()` when the transfer confirms — which is why `wallet_entries` is unique on (payment_intent_id, type), not on payment_intent_id alone.

## Invariant 1 is the strongest audit control; it must alarm, not report
`sum(all wallet balances) == provider balance + cash tin − withdrawals in flight`.

`unity:reconcile-wallets` checks it daily at 02:45 and EXITS NON-ZERO on a mismatch. It is the only check in the system that catches a fraud requiring no ledger tampering at all: a wallet credited with no money behind it shows up here the next morning and nowhere else. Never soften it to a report.

Withdrawals in flight sit on the provider's side of the sum because of debit-on-initiation. Cash is on its own side, which is why `TransactionSource::Cash` is a named source rather than being left to look like a gateway payment.

The second check — group wallet against the ledgers — is REPORTED, never alarmed. The group wallet opens at a recorded float (`WalletRegistry::recordOpeningFloat`) rather than being derived from the ledgers' whole history, so it drifts by construction until a cycle has run start to finish on wallets.
