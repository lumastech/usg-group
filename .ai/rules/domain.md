---
paths:
  - 'app/Domain/**'
---

# Domain

## Interest is a pooled pro-rata split, not per-member accrual
Borrowers pay 5%/month reducing balance into a pool. That whole pool is then redistributed to EVERY member in proportion to their cumulative savings — savers earn from the group's lending whether or not they borrowed. This is the literal formula in the group's workbook (SAVINGS!F6). A member who borrows heavily shows a net LOSS (interest earned minus interest paid).

Exception: the opening month of a cycle (December) has no lending history, so each member earns a flat 5% on their own savings instead. Driven by CycleMonth::$interest_allocation_method.

Allocation uses largest-remainder rounding in whole ngwee (InterestPoolAllocator::largestRemainder) so shares always sum to the pool exactly. Never switch this to round() per member — it leaks or invents ngwee.

## Executing a payout freezes a member's ledgers
members.ledgers_frozen_at is set by App\Domain\Payouts\LedgerFreeze when a closure is settled. SavingsLedger::record, LoanLedger::post and SocialFundLedger::post all call LedgerFreeze::assertOpen first and throw MemberLedgersFrozenException, so a settled position can never drift from the voucher the member is holding. Any new ledger that writes against a member must ask the same gate.
