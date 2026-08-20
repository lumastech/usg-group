---
paths:
  - 'app/Domain/Loans/**'
---

# Loans

## Module 3 plugs into two seams, already bound
The savings module never queries loans directly. It asks two contracts, both bound in AppServiceProvider to null implementations:

- `OutstandingLoanProvider` (→ `NoOutstandingLoans`) — loan balance, social-fund balance, interest paid, borrowed-to-date per member/month. `MemberBalanceCalculator` derives every loan column from it, which is what makes `unity:rebuild-summaries` idempotent; do not reintroduce reading those values back off the snapshot.
- `MonthlyInterestIncome` (→ `NoInterestIncome`) — the month's interest pool. `InterestDistributionService::distribute()` falls back to it when no pool is passed.

When the lending engine lands, rebind these two rather than editing the savings domain.
