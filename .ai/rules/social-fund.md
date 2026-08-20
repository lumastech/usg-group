---
paths:
  - 'app/Domain/SocialFund/**'
---

# Social Fund

## The fund ledger is the only balance, and pending things never touch it
`social_fund_transactions.amount_ngwee` is signed — inflows positive, outflows negative — so the balance is a plain SUM and nothing caches it. Entries are immutable (`ImmutableLedgerException` in `booted()`); corrections are reversing Adjustments.

`SocialFundLedger::post()` is the single door and enforces both rules there, never at the call sites: any entry with a negative amount needs two distinct committee members (so a negative Adjustment is held to the same rule as a grant — the sign decides, not the type), and the balance may never go below zero (`InsufficientSocialFundException`).

Approved-but-unpaid work is deliberately kept OUT of the ledger. A `GrantClaimStatus::Approved` claim and a `Pending` `DiasporaApportionmentItem` hold the two signatures but post nothing; the outflow appears only at pay/confirmTransfer. That is what stops an approved grant understating what the fund still holds. Do not post on approval.

Diaspora splits use floor division on purpose (`intdiv`), not `LargestRemainder` as the savings interest pool does — nobody may get a ngwee more than anybody else, and the remainder stays in the fund.
