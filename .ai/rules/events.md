---
paths:
  - 'app/Events/**'
---

# Events

## The two loan penalties are two events, on purpose
LatePenaltyCharged (K100/day) is mirrored into the Social Fund. MissedInstallmentPenaltyCharged (10% of the outstanding balance) is NOT — it stays with the lending pool, and `unity:reconcile-social-fund` compares only the daily penalty against fund inflows. A listener that treats them as the same event breaks that reconciliation.

TradingSessionConcluded is dispatched AFTER the concluding transaction commits, never inside it. Its listener renders a PDF per member and mails them; none of that belongs in the transaction holding the month's ledger rows.
