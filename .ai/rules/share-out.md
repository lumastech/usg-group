---
paths:
  - 'app/Domain/ShareOut/**'
---

# Share Out

## The checklist is advice on screen and the decision in the domain
Closing and ShareOut are two states on purpose: CycleCloser::beginClosing stops lending and opens the checklist, openShareOut is what a clean checklist opens, and only ShareOut lets PayoutExecutor settle anybody. Never transition Active straight to ShareOut.

ShareOutPreflight is re-run inside openShareOut rather than trusted from the page — a repayment can land between looking at the checklist and signing for it. Overriding a dirty checklist costs a written reason AND a second committee member (TwoPersonRule); a clean one needs only cycles.manage.

ShareOutBatchRunner settles each member in their own transaction. One member who cannot be settled is reported by name and stepped over, never rolled back with the rest — the group is standing in a room with cash on the table. Note the two signatories are always skipped by their own batch (a member cannot approve their own payout); they are settled afterwards by a different pair on the closures screen. The runner only sweeps up ActiveShareOut; exits stay on the closures screen.
