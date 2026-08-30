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

## A declaration is a request until the committee approves it; approved_at is the payment gate
Three steps, in order: the member declares (Submitted), the committee "asks" (DeclarationService::approve → Approved, labelled "Pending payment", `declarations.approve` permission), and only then may the member OR the treasury start a payment. Submit refuses a declaration that is no longer isEditable(), so approval is what freezes the member out; DeclarationService::reopen hands it back, and only while the status is still Approved.

The payment gate is `Declaration::isApproved()` (the approved_at stamp), NOT the status. lockMonth() turns both Submitted and Approved rows into Locked when the trading session opens, so status alone cannot say whether the committee ever asked for it — and a member turning up to pay on the 5th needs an approval given against an already-Locked row. approve() therefore stamps approved_at and leaves a Locked row Locked.

Everything that collects savings goes through DeclarationService::assertPayable(): CollectionInitiator::savings for the push path and My\PaymentController::assertSavingsPayable for the card path. Any new savings collection route must ask the same gate.
