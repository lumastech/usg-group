---
paths:
  - 'app/Domain/Reporting/**'
---

# Reporting

## Dashboard widgets are stripped server-side, and the share-out sheet reads PayoutCalculator
ShareOutSheet takes its month cells from SavingsMatrix but its six closing columns from PayoutCalculator, never from arithmetic of its own — that is what makes a forfeited interest (left early / expelled) and a death's interest cutoff come out right on the sheet. The Outstanding Loan column is read back off the breakdown's debit line (which is stored negative, so abs() it), which keeps Net Value = Total − Loan true on the face of the sheet. The loan column is struck as at today, so tests must stand past the disbursement date.

CycleOverview's sections are separable and DashboardController::visibleSections drops the ones the user holds no permission for. A signatory without loans.view does not merely see fewer tiles — lending/risk/target never reach their browser. Add a new section by adding it to both the overview and widgetsFor(), or it will leak.

NegativeNetValueProjection amortises the shortfall at the cycle's own monthly_interest_bps: interest on the opening balance first, repayment after, level payment rounded UP to the ngwee so the plan genuinely ends at zero.
