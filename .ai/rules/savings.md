---
paths:
  - 'app/Domain/Savings/**'
---

# Savings

## Savings ledger is append-only and the lockdown cap is per month
`SavingsTransaction` throws `ImmutableLedgerException` on update/delete (guard in the model's `booted()`). Corrections are reversing `Adjustment` entries posted through `SavingsLedger::record()`, never edits. Any rebuild must therefore be derivable from the ledger — never patch a row.

The lockdown cap (Sept onward, K500) applies to the member's TOTAL for the month, not to the single deposit: `assertValidContribution()` adds `savedInMonth()` before comparing. Two K500 deposits in September are refused. Only `SavingsTransactionType::Contribution` follows the minimum/increment/cap rules, and only Active members may contribute (`MemberNotActiveException`) — adjustments and imports are still allowed for members who have left, so their ledger can be closed out.
