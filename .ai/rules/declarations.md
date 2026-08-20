---
paths:
  - 'app/Domain/Declarations/**'
---

# Declarations

## A declaration is a promise, and DeclarationWindow is the only clock
Declarations move no money. Nothing reaches a ledger until the month's trading session is concluded, which is why a Submitted row stays editable and is only ever read after `lockMonth()`.

`DeclarationWindow` is the single source of "where in the month are we". HandleInertiaRequests::month() delegates to `payload()`, and the declaration screens, the trading console and the dashboards all read the same shape — do not re-derive the window state anywhere else, or the banner and the form under it will disagree.

`DeclarationService::submit()` re-runs the modules that own the amounts: savings through `SavingsLedger::assertValidContribution()` (increment + lockdown cap), the loan request through `LoanEligibilityService`. Declaring something the table would later refuse is the failure mode this prevents. The repayment is deliberately NOT validated — paying more or less than the schedule is a matter for the trading table.

`onBehalf: true` is the treasurer's late-entry path: it accepts a declaration after the window closes and stamps `is_late`. It never opens the window early — before 08:00 on the 1st nobody, treasurer included, may capture anything.
