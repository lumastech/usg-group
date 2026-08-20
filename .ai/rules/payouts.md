---
paths:
  - 'app/Domain/Payouts/**'
---

# Payouts

## No round-off is applied, and only the loan is deducted
The workbook's ROUNDOFF ADJSTMNT column is carried everywhere (payouts.round_off_ngwee, the statement line, the voucher) but NoRounding is bound in AppServiceProvider, so net payable equals net value to the ngwee. The group has not settled on a convention. Adopting one is a change of that single binding — RoundDownToStep(5_000) is written and tested; do not add rounding logic anywhere else. Non-zero remainders post to the Social Fund as an Adjustment referencing the payout.

A closure deducts the member's outstanding loan and nothing else. Their Social Fund position is NOT netted off (unlike MemberMonthBalance::net_value_ngwee, which subtracts social_loan_balance_ngwee) — the fund is the group's money, and a K250 contribution was never the member's savings to hand back. A funeral grant is linked beside the closure screen, never inside the sum.

Interest for a deceased member stops at the last cycle month whose end date falls on or before date_of_death; the month they died in does not count, because interest is credited when a month closes.
