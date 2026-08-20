---
paths:
  - 'app/Domain/Loans/**'
  - app/Domain/Loans/LoanEligibilityService.php
---

# Loans

## The savings module reads loans through two seams, now ledger-backed
The savings module never queries loans directly. It asks two contracts, both bound in AppServiceProvider:

- `OutstandingLoanProvider` (→ `LedgerOutstandingLoans`) — loan balance, social-fund balance, interest paid, borrowed-to-date per member/month. `MemberBalanceCalculator` derives every loan column from it, which is what makes `unity:rebuild-summaries` idempotent; do not reintroduce reading those values back off the snapshot.
- `MonthlyInterestIncome` (→ `LedgerInterestIncome`) — the month's interest pool, which is the interest portion of repayments actually *received*, not interest charged. Distributing charged-but-unpaid interest would hand members a share of income the fund does not hold.

Module 3 swapped the null implementations here rather than touching anything in `app/Domain/Savings`. `NoOutstandingLoans` / `NoInterestIncome` are kept for tests that need a loanless world. The social fund still answers zero until module 4.

## The loan balance lives in the ledger; repayments clear penalties, then interest, then principal
`loans.current_balance_ngwee` is a cache. The authority is the signed sum of `loan_transactions` (`LoanLedger::balanceNgwee`), and `LoanLedger::rebuild()` recomputes it — entries are immutable, so a rebuild only moves the denormalised column, never a row.

Every repayment stores its split across `penalty_portion_ngwee` / `interest_portion_ngwee` / `principal_portion_ngwee`, allocated in that order by `LoanRepaymentService::allocate()`. Those columns are what make principal outstanding, interest paid and the month's interest pool all derivable from the ledger alone — do not add a second source for any of them. Letting a short payment touch principal before a penalty would quietly cut the interest owed to the rest of the group.

`InterestEngine` charges 5% of the principal still outstanding on the month's trading date and reprices only the schedule's *current* columns; the `original_*` columns are the schedule the member was handed and are never touched.

## Eligibility returns every failed reason, and the September lockdown has no override
`LoanEligibilityService::check()` never throws and never stops at the first failure — it returns a `LoanEligibility` carrying every failed condition as `{code, message}`. The request wizard, `/my/loan` and the `POST /app/loans/eligibility` endpoint all render those same messages, so reword them in the service, not on a screen.

It is run twice per loan: at request, and again inside `LoanDisbursementQueue::disburse()` (passing `ignoring: $loan`), because savings, status and other loans all move in the days between.

The one-loan-at-a-time rule bends to a committee `discretion_override` with a written note. The lockdown (`Cycle::isLockdownMonth`, September onward) does not — `overriding: true` still returns the `lockdown` reason. Do not add an escape hatch for it.
