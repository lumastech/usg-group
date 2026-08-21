---
paths:
  - 'app/Domain/Savings/**'
---

# Savings

## Savings ledger is append-only and the lockdown cap is per month
`SavingsTransaction` throws `ImmutableLedgerException` on update/delete (guard in the model's `booted()`). Corrections are reversing `Adjustment` entries posted through `SavingsLedger::record()`, never edits. Any rebuild must therefore be derivable from the ledger — never patch a row.

The lockdown cap (Sept onward, K500) applies to the member's TOTAL for the month, not to the single deposit: `assertValidContribution()` adds `savedInMonth()` before comparing. Two K500 deposits in September are refused. Only `SavingsTransactionType::Contribution` follows the minimum/increment/cap rules, and only Active members may contribute (`MemberNotActiveException`) — adjustments and imports are still allowed for members who have left, so their ledger can be closed out.

## Pooled pro-rata matches the treasurer's "Interest Earned" column
Asked and answered (2026-08-21): PooledProRataStrategy (whole interest pool split by cumulative savings, month 1 excepted via FlatOwnSavingsStrategy) is confirmed to match how the treasurer fills the workbook's Interest Earned column. Savers earn from the group's lending whether or not they borrowed, so a heavy borrower showing a net loss is correct, not a bug.
