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

## A declaration is paid in one prompt, for the whole approved amount
`CollectionInitiator::declaration()` collects an approved declaration as a single `PaymentPurpose::DeclarationSettlement` push for `Declaration::expectedInNgwee()` — savings plus repayment, to the ngwee. Not less (a part payment leaves a variance for the table to chase), not more, and not twice: `Declaration::standingPayment()` refuses a second prompt against anything that is not Failed or Abandoned, so the intent's `payable` is the declaration itself.

A loan the member asked for is never netted off. The sheet's `expected_in` is savings + repayment and the disbursement is a separate outflow, so netting would under-collect.

DeclarationSettlement does not post on settlement: it goes through `PaymentPoster::markOnTradingSheet()` alongside SavingsContribution, and `TradingSessionService::markReceived()` splits the one figure back into savings and repayment against the declaration — savings first — exactly as cash counted at the table is split.

## A collection nobody answered dies on one clock: PaymentIntent::hasStalled()
An unapproved handset prompt never comes back as a refusal, it just goes quiet, so something must declare it dead — otherwise `Declaration::standingPayment()` blocks the member from ever paying.

`PaymentIntent::hasStalled()` is the single rule: a collection past `payments.collections.poll.give_up_after_minutes` (60), measured from `initiated_at ?? created_at`. `PaymentIntentService::abandonStalled()` acts on it, and all three callers share it — `unity:poll-payments`, `My\PaymentController::verify()` (so "Check the payment" releases a dead prompt), and `CollectionInitiator::assertDeclarationCollectable()` (so "Try again" gets through). Never true of a transfer: money may have left the group's account, and that is escalated to NeedsAttention for a person, not abandoned by a clock.

Two traps this closed:
- `apply()` must stamp `initiated_at` once (`$intent->initiated_at ?? $result->initiatedAt`). A provider that echoes a fresh timestamp on a status query would push the clock forward on every poll and the prompt would never be allowed to die.
- A mobile-money Draft with `initiated_at` null never reached the provider, so it is released after the poll interval rather than the full hour. A card Draft is NOT this — the member may be inside the hosted widget — and waits out the whole window. The short grace exists so a double-tapped button cannot abandon an attempt whose call is still in flight and charge the member twice.

Member screens must surface `has_stalled` and offer another attempt; telling someone to approve a prompt their phone no longer has is what left them stuck.

## A timeout is not a refusal: PaymentGatewayException::$outcomeUnknown
Every Lenco call goes through `LencoClient::attempt()`, which converts `Illuminate\Http\Client\ConnectionException` into a `PaymentGatewayException` with `outcomeUnknown: true`. Nothing else in the app catches ConnectionException, so without that conversion a cURL 28 on `/collections/mobile-money` escapes every handler as a 500 while the prompt is ringing on the member's handset.

`PaymentIntentService::send()` reads the flag: a refusal the provider actually sent closes the intent as `Failed` (nothing moved, the member is free to try again); a request that timed out is left `Pending` with `initiated_at` stamped, for the poller to resolve against the provider. Failed would be a lie there — `Declaration::standingPayment()` ignores Failed, so a second prompt would go out against a live one and take the money twice.

Screens must say so too: `outcomeUnknown` gets an 'info' flash telling the member to approve the prompt if it arrived and then check the payment, never an error that invites a retry.

A payment in flight may go straight to `Settled` without ever being seen at `Successful` — the provider reports the charge and its settlement in one answer, so `PaymentStatus` allows Draft/Pending/AwaitingAuthorization → Settled. Refusing that stranded the payment in flight forever.
