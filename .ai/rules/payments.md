---
paths:
  - 'app/Domain/Payments/**'
---

# Payments

## PaymentPoster is the only door from the gateway to the ledgers
A webhook, a poll and the browser callback may only ever mark a `payment_intent` as settled. Posting happens in `PaymentPoster`, from a queued job, and it claims the intent with a conditional UPDATE (`whereIn status [successful, settled]`) — a webhook and a poll race each other on every payment, and two workers both seeing "Successful" is not a rare case.

PaymentPoster calls the existing domain services (SavingsLedger, LoanRepaymentService, SocialFundLedger, LoanDisbursementQueue, GrantClaimService), so an online K750 contribution is refused by the same rule that refuses cash. A ledger refusal parks the intent at `NeedsAttention` with the exception's own message; it is never retried automatically — the answer is a refund, a reclassification or a hold, and none of those are a machine's to pick.

Anything date-sensitive uses `PaymentIntent::effectiveDate()` (the provider's `completedAt`), never `now()`. A repayment made at 23:50 on the 7th and processed on the 8th must allocate on the 7th or the member is charged a late penalty for our queue depth.

Nothing outside `app/Domain/Payments/Lenco/` may name Lenco, read a provider status string, or see a decimal amount. `NullPaymentGateway` is the default binding and moves nothing.

## Savings paid online go on the trading sheet, not straight into the ledger
`PaymentPurpose::postsOnSettlement()` returns false for SavingsContribution alone. A settled savings payment calls `TradingSessionService::markReceived()` on the member's row (accumulating on top of whatever is already there) and the money posts when `TradingConcluder::conclude()` runs — see .ai/rules/trading.md, where the posting order is the constitution's and a gateway payment must not jump it.

Three outcomes, deliberately distinct:
- No open session for the month → `PaymentDeferredException`, intent stays `Settled`, and the next `unity:poll-payments` run after a session opens picks it up. Not an error.
- Session open but the member has no row (they never declared) → `NeedsAttention`. The sheet is built from declarations; inventing a row is not ours to do. `CollectionInitiator::savings()` refuses this at the form, before any money moves.
- Everything else posts immediately.

Because savings produce no ledger row at post time, `posted_transaction_id` stays null for them — the status transition to `Posted` is the idempotency guard there, not the unique index.

## Money out posts on confirmation; PayoutExecutor is never threaded through a network call
A loan disbursement posts only when the provider confirms the transfer succeeded — `TransferInitiator::disburseLoan()` leaves the loan `Approved` and `PaymentPoster` calls `LoanDisbursementQueue::disburse()` on success, so a failed transfer leaves no trace of a loan the member never received and needs no reversing entry.

`PayoutExecutor` is NOT modified and must not be. It stays the single irreversible act (two signatures, the freeze, the record); paying is a separate retryable step that stamps `payouts.paid_at`. A failed transfer leaves the payout standing — the member's position IS settled — and it is retried or paid in cash.

Fee bearing is settled: `LENCO_COLLECTION_BEARER=customer` on money in, so a K500 contribution reaches the savings ledger as exactly K500. Transfer fees are the group's, recorded on `payment_intents.fee_ngwee`, and never netted off a payout — share-out is paid to the exact ngwee (see .ai/rules/payouts.md).

`ShareOutPaymentRunner` checks the provider balance against the WHOLE schedule before the first transfer. Half a room paid with no record of who is next is the worst outcome available.
