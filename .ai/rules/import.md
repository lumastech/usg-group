---
paths:
  - 'app/Domain/Import/**'
---

# Import

## Workbook import is idempotent on the ledgers, and never imports interest
Idempotency is by natural key (member + month + kind) read off the ledgers themselves, not from an import log: savings look for a SavingsTransaction of type ImportOpening, loans for a LoanTransaction of the matching type on that month, the fund for a Contribution, declarations for a Declaration row. Re-running posts only what has since been added to the workbook.

Interest is deliberately NOT imported. It is a pooled pro-rata split the app recomputes from the savings it now holds, so importing the workbook's interest column would post the same money twice. ImportReconciliation reports it as an advisory line that is expected to differ.

Everything posts through the module that owns it (SavingsLedger / SocialFundLedger / LoanLedger) so imported figures are subject to the same immutability and freeze rules as figures captured at the table.

WorkbookReader is tolerant by design: sheets matched loosely by name, header row found rather than assumed, month columns recognised from the heading text ("Dec", "Dec-25", "December 2025") and sub-columns picked by name rather than by offset. Tests build the fixture workbook with PhpSpreadsheet instead of committing a binary — see tests/Feature/Console/ImportWorkbookTest.php for the layout the parser expects.
