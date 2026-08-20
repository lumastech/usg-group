---
paths:
  - 'app/Listeners/**'
---

# Listeners

## Only the daily late penalty is mirrored into the Social Fund
`PenaltyService::chargeLatePayment` raises `LatePenaltyCharged`; `MirrorLatePenaltyToSocialFund` turns it into a `LatePenaltyInflow` via `LatePenaltyMirror`. The mirror is keyed on the loan entry through the `reference` morph, so a replayed event posts nothing twice.

The 10% missed-installment penalty is NOT mirrored — it is a charge on the loan and stays with the lending pool. That is why `LatePenaltyMirror::chargedOnLoans()` counts `LatePenaltyDaily` alone, and why `unity:reconcile-social-fund` compares only that against the fund's inflows. Adding the other penalty type to either side breaks the reconciliation.
