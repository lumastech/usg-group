---
paths:
  - 'app/Domain/Approvals/**'
---

# Approvals

## Two flavours of the two-person rule — pick by when the signatures arrive
`DualApprovalGate` is for approvals collected over time: each approver visits the portal separately and an `Approval` row tracks the state between visits.

`TwoPersonRule::assertDistinctCommittee()` is for actions confirmed in one sitting at the trading table — loan approval and collateral sign-off. One committee member acts, a second types their own credentials into ConfirmDialog's `dual-approval` variant on the same device, and both signatures arrive in a single request.

The credentials half is verified in HTTP by `App\Concerns\ResolvesSecondApprover` (password via Hash::check, plus the required permission on the second user); the domain only checks that the two members are distinct committee members and that neither is the subject. Do not trust an approver id sent from the client.
