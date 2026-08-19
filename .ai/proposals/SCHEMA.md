# Unity Savings Group — Proposed Schema & Domain Services
Status: **awaiting approval** — no code written yet.

Source of truth used: the group Constitution PDF and
`UNITY_SAVINGS_Spreadsheet-2025_TO_2026.xlsx` (9 sheets, 30 members).

---

## 0. Findings from the real workbook that change the design

These are things the brief did not mention but the actual data requires. Decisions needed.

### F1. Interest is a **pooled pro-rata distribution**, not per-member accrual
From `SAVINGS!F6`:
```
member_interest(month) = cumulative_savings(member, up to month)
                       / cumulative_savings(ALL members, up to month)
                       * TOTAL loan interest charged group-wide that month
```
Borrowers pay 5%/month reducing balance into a pool; the pool is split across **all**
members in proportion to their cumulative savings. `SAVINGS!AG` proves this:
"Interest Earned" − "Interest Paid" = "Net Gain/(Loss)", and members who borrow heavily
show a **loss**. This is the core financial engine and it is not in the brief.

December is an anomaly: `SAVINGS!D6 = C6*5%` — a flat 5% of the member's own December
savings, not a pool split. Modelled as a per-month `allocation_method` on the cycle month.

### F2. The Social Fund also **issues loans at 10% per month**
Sheet `Social Fund Summary` tracks New Loan / Penalty / Interest @10% / Balance C/F per
member, with a K18,133.50 loan book and K200.47 cash at hand. Entirely absent from the
brief. Needs its own tables (below), or an explicit decision to drop it.

### F3. Penalties in the data are K50 and K100, not only K100/day
`Social Fund Summary` rows show K50 and K100 penalty entries. Modelled as a
`penalty_rules` lookup on the cycle rather than a hard-coded K100.

### F4. Loan balances can go **negative** (overpayment credit)
Siitu Simutanyi runs to −K60.64 and interest is then charged on the negative balance.
The engine must either allow a credit balance or stop accrual at zero — decision needed.

### F5. Repayment-term table has a gap between the two sources
Constitution: `18,000–29,000 → 8`, `30,000+ → 10`. Brief: `18,001–29,999 → 8`, `30,000+ → 10`.
Proposal: use the brief's version (it closes the K29,000–K30,000 gap) and store the bands
as **data** (`repayment_terms` table, cycle-scoped) so the committee can amend them.

### F6. Trading is **member-to-member routing**, not a central pot
The `Trading` sheet pairs each borrower with the specific savers whose money funds them
(e.g. Bertha Chileshe K30,000 + Jessica K30,000 → Bertha Chileshe K60,000). The treasurer
console must produce these payment instructions, so trading needs a `trading_matches` table.

### F7. Declarations include a 5th column: **Social Loans**
`Declarations!G` — social-fund loan requests, consistent with F2.
`Total Expected Payment = Savings + Loan Repayment − Loan Requested − Social Loan`.

### F8. The workbook has no per-loan records
`LOANS` is a month-by-month grid of New Loan / Interest / Repayment / C/F per member.
Individual loans and their schedules cannot be reconstructed. The importer will therefore
create **monthly loan movement rows** plus one synthetic open `loan` per member carrying
the running balance; it will not fabricate installment schedules for history.

---

## 1. Money & precision

- All money = `BIGINT` **ngwee** (K1 = 100 ngwee). No floats, no decimals.
- `brick/money` wraps the values in the domain layer; casts convert at the model boundary.
- Pro-rata interest allocation uses **largest-remainder**: allocate `floor()` shares, then
  distribute the residual ngwee one at a time to the largest fractional parts, so the
  allocations always sum exactly to the pool. A `residual_ngwee` column records the drift.

---

## 2. ERD

### Module 1 — Foundation

**cycles**
| column | type | notes |
|---|---|---|
| id | id | |
| name | string | "2025–2026" |
| starts_on / ends_on | date | 2025-12-01 / 2026-11-30 |
| registration_closes_after_month | tinyint | default 3 |
| loan_lockdown_starts_month | tinyint | default 10 (September) |
| lockdown_savings_cap_ngwee | bigint | 50000 (K500) |
| final_repayment_date | date | 2026-11-07 |
| joining_fee_ngwee / late_joining_fee_ngwee | bigint | 100000 / 200000 |
| social_fund_contribution_ngwee | bigint | 25000 |
| monthly_interest_bps | int | 500 = 5% |
| social_fund_interest_bps | int | 1000 = 10% |
| min_savings_ngwee / savings_increment_ngwee | bigint | 50000 / 50000 |
| borrowing_target_ngwee | bigint | 5000000 (K50,000) |
| max_loan_multiple | tinyint | 2 |
| weekend_trading_policy | enum | `next_monday` (default) / `previous_friday` |
| status | enum | draft, active, closing, closed |

**cycle_months** — one row per month; all data hangs off this.
| column | type | notes |
|---|---|---|
| cycle_id | fk | |
| sequence | tinyint | 1..12 |
| month | date | first of month |
| declarations_open_at / close_at | datetime | 1st 08:00 → 3rd 23:59 |
| trading_starts_on / concludes_on | date | 4th → resolved 7th |
| disbursement_on | date | resolved for weekends |
| interest_allocation_method | enum | `own_savings_flat` (Dec) / `pooled_pro_rata` |
| status | enum | pending, declarations_open, trading, closed |

**members**
| column | type | notes |
|---|---|---|
| user_id | fk nullable | portal login |
| cycle_id | fk | |
| member_number | int | sheet row order |
| full_name, nrc_number, physical_address, phone | string | |
| next_of_kin_name / _phone / _relationship | string | |
| is_diaspora | bool | |
| status | enum | Active, LeftEarly, Expelled, Deceased |
| status_effective_on | date nullable | |
| joined_on | date | |
| joining_fee_ngwee | bigint | 100000 or 200000 |
| joining_month_sequence | tinyint | drives late fee |

**roles / permissions / model_has_roles** — spatie/laravel-permission (*approval needed*).
Roles: Member, Treasurer, ViceTreasurer, Chairperson, ViceChairperson, Admin.

**activity_log** — spatie/laravel-activitylog, on every financial model.

**approvals** (two-person integrity, polymorphic)
| column | type | notes |
|---|---|---|
| approvable_type / approvable_id | morph | Loan, Payout, SocialFundGrant |
| action | string | `loan.approve`, `payout.release` |
| requested_by_member_id | fk | |
| first_approver_member_id / second_approver_member_id | fk nullable | must differ |
| status | enum | pending, partially_approved, approved, rejected |
| note | text | committee discretion audit note |

### Module 2 — Savings

**savings_transactions**
| column | type | notes |
|---|---|---|
| member_id, cycle_month_id | fk | |
| type | enum | contribution, joining_fee, adjustment, import_opening |
| amount_ngwee | bigint | validated: ≥K500 and % K500 for contributions |
| declared_amount_ngwee | bigint nullable | link to declaration for variance |
| recorded_by_member_id | fk | |

**interest_allocations**
| column | type | notes |
|---|---|---|
| member_id, cycle_month_id | fk | |
| pool_total_ngwee | bigint | group loan interest that month |
| member_basis_ngwee | bigint | cumulative savings |
| pool_basis_ngwee | bigint | group cumulative savings |
| amount_ngwee | bigint | largest-remainder result |
| method | enum | mirrors cycle_months |

**member_month_balances** (materialised snapshot, rebuildable)
`cumulative_savings`, `cumulative_interest_earned`, `cumulative_interest_paid`,
`loan_balance`, `social_loan_balance`, `net_value`, `two_times_savings`,
`eligible_to_borrow`, `borrowed_to_date`, `borrowing_target_balance`.

### Module 3 — Loans

**repayment_terms** — cycle-scoped bands: `min_ngwee`, `max_ngwee`, `months`.

**loan_requests** — FIFO queue.
`declaration_id`, `member_id`, `cycle_month_id`, `amount_ngwee`,
`requested_at` (FIFO key), `queue_position`, `status` (queued, approved, rejected,
disbursed, lapsed), `eligibility_snapshot` (json), `discretion_override_approval_id`.

**loans**
`member_id`, `cycle_id`, `loan_request_id`, `principal_ngwee`, `interest_bps`,
`term_months`, `disbursed_on`, `first_due_on`, `final_due_on`,
`status` (Requested, Approved, Disbursed, Repaying, Settled, Defaulted),
`approval_id`, `outstanding_ngwee` (cached), `settled_on`.

**loan_installments** — generated at disbursement.
`loan_id`, `sequence`, `due_on`, `opening_balance_ngwee`, `interest_ngwee`,
`principal_ngwee`, `total_due_ngwee`, `paid_ngwee`, `status` (pending, paid, part_paid, missed).

**loan_transactions** — the immutable money ledger.
`loan_id`, `cycle_month_id`, `type` (disbursement, interest_charge, repayment,
penalty_charge, write_off, adjustment), `amount_ngwee`, `balance_after_ngwee`,
`occurred_on`, `recorded_by_member_id`, `source` (manual, trading, import).

**loan_penalties**
`loan_id`, `type` (late_transfer_daily, missed_installment), `days_late` nullable,
`rate_bps` nullable, `amount_ngwee`, `social_fund_transaction_id` (auto-routing),
`applied_on`, `waived_at`, `waiver_approval_id`.

**loan_defaults** → `collateral_claims` → `collateral_items`
Claim: `loan_id`, `outstanding_ngwee`, `declared_on`, `status`
(draft, committee_review, signed_off, settled), `approval_id`.
Item: `description`, `estimated_value_ngwee`, `serial_or_marks`, `recovered_at`.

### Module 4 — Social Fund

**social_fund_contributions** — `member_id`, `amount_ngwee` (25000), `paid_on`, `status` (paid/unpaid).

**social_fund_transactions** — running balance ledger.
`direction` (in/out), `category` (contribution, penalty, loan_disbursement,
loan_repayment, loan_interest, funeral_grant, baby_gift, gathering, diaspora_apportionment,
bank_interest, bank_charge), `member_id` nullable, `amount_ngwee`, `balance_after_ngwee`, `source_type/id` morph.

**social_fund_grants**
`member_id`, `type` (funeral, unity_baby, gathering, diaspora),
`beneficiary_name`, `beneficiary_relationship` (enum: Parent, Spouse, Child, Other),
`amount_ngwee`, `approval_id`, `paid_on`.
*Validation: funeral grants reject any relationship outside Parent/Spouse/Child.*

**social_fund_loans** + **social_fund_loan_transactions** *(F2 — confirm you want this)*
Mirrors the loan tables at 10%/month.

### Module 5 — Declarations & Trading

**declarations**
`member_id`, `cycle_month_id`, `savings_ngwee`, `loan_repayment_ngwee`,
`loan_requested_ngwee`, `social_loan_requested_ngwee`,
`total_expected_ngwee` (computed, may be negative), `submitted_at`,
`is_late` (outside 1st–3rd), `status` (draft, submitted, superseded).
Unique: (member_id, cycle_month_id) where status != superseded.

**trading_sessions** — `cycle_month_id`, `opened_at/closed_at`, `opened_by`,
`available_funds_ngwee`, `loan_requests_ngwee`, `surplus_deficit_ngwee`, `status`.

**trading_matches** *(F6)* — `trading_session_id`, `payer_member_id`,
`payee_member_id`, `amount_ngwee`, `instruction_sent_at`, `confirmed_at`.

**trading_entries** — expected vs actual reconciliation per member.
`declaration_id`, `expected_ngwee`, `actual_received_ngwee`, `actual_sent_ngwee`,
`received_on`, `days_late`, `variance_ngwee`, `penalty_id` nullable.

### Module 6 — Exits & Payouts

**member_exits** — `member_id`, `from_status`, `to_status`, `effective_on`,
`reason` (enum incl. dishonesty/theft/unruly/loan_misconduct for expulsion),
`meeting_id` nullable, `recorded_by_member_id`, `notes`.

**payouts**
`member_id`, `cycle_id`, `case` (active_share_out, early_leaver, expelled, deceased),
`total_savings_ngwee`, `interest_ngwee` (0 for leaver/expelled),
`outstanding_loan_ngwee`, `outstanding_social_loan_ngwee`,
`net_value_ngwee`, `round_off_adjustment_ngwee`, `net_payable_ngwee`,
`paid_ngwee`, `balance_ngwee`, `approval_id`, `calculated_at`, `paid_on`.

**next_of_kin_arrangements** — for deceased members whose loan > savings.
`member_id`, `payout_id`, `next_of_kin_name/phone/relationship`,
`shortfall_ngwee`, `agreed_schedule` (json), `status`.

### Module 7 — Governance

**committee_terms** — `member_id`, `office` (enum), `term_start/term_end`,
`succeeds_committee_term_id` (Vice→Chair continuity), `status` (active, ended, vacated).

**officer_resignations** — `committee_term_id`, `notice_given_on`,
`effective_on` (notice + 1 month), `accepted_at`.

**vacancies** — `office`, `opened_on`, `cause` (resignation, no_confidence, death), `filled_by_member_id`, `filled_on`.

**meetings** — `cycle_id`, `held_on`, `type` (monthly, agm, special),
`total_members_at_time`, `quorum_required` (60%), `attendance_count`, `quorum_met` (bool), `minutes`.

**meeting_attendances** — `meeting_id`, `member_id`, `present` (bool), `apology` (bool).

**votes** — `meeting_id` nullable, `type` (no_confidence, amendment, election),
`subject_type/subject_id` morph, `opened_by_member_id`,
`threshold_basis` (**total_members** for no-confidence, **members_present** for amendments),
`threshold_bps` (6000 = 60%), `votes_for`, `votes_against`, `abstentions`,
`basis_count`, `passed` (bool), `closed_at`.

**constitution_amendments** — `cycle_id`, `proposed_by_member_id`, `proposed_on`,
`clause_reference`, `current_text`, `proposed_text`, `meeting_id`, `vote_id`,
`status` (proposed, tabled, passed, rejected), `effective_on`.
*Validation: reject a proposal within 6 months of the last passed amendment.*

### Module 8 — Share-out & reporting

**share_out_runs** — `cycle_id`, `run_at`, `run_by_member_id`, `status` (draft, final),
`total_savings_ngwee`, `total_interest_ngwee`, `total_loans_ngwee`, `total_wealth_ngwee`.

**share_out_lines** — mirrors the SHARE OUT sheet columns:
`member_value_ngwee`, `total_loan_ngwee`, `net_value_ngwee`,
`round_off_adjustment_ngwee`, `net_payable_ngwee`, `paid_ngwee`, `balance_ngwee`.

### Module 9 — Notifications

**notifications** (Laravel default) + **sms_messages**
`member_id`, `phone`, `body`, `channel_reference`, `status`
(queued, sent, failed, delivered), `provider`, `sent_at`, `error`.

### Data migration

**import_runs** — `filename`, `checksum`, `dry_run`, `started_at/finished_at`,
`status`, `totals` (json: imported vs sheet totals), `reconciliation_report` (json).

**import_rows** — `import_run_id`, `sheet`, `row_number`, `idempotency_key`
(unique: run-independent hash of sheet+member+month+field), `imported_type/id`, `status`, `message`.

---

## 3. Domain service interfaces (loans engine)

```php
namespace App\Domain\Loans\Contracts;

interface RepaymentTermResolver {
    /** Months to repay for a given principal, from cycle-scoped bands. */
    public function monthsFor(Cycle $cycle, Money $principal): int;
}

interface LoanEligibilityChecker {
    /**
     * Runs every gate: no running loan, 2x savings cap, September lockdown,
     * cycle-end deadline reachable, member status Active.
     * @return EligibilityResult{eligible: bool, maxAmount: Money, failures: array<string,string>}
     */
    public function assess(Member $member, Money $requested, CarbonInterface $at): EligibilityResult;

    /** Same, but with a committee discretion override attached. */
    public function assessWithOverride(Member $member, Money $requested, Approval $override, CarbonInterface $at): EligibilityResult;
}

interface RepaymentScheduleGenerator {
    /** Equal (or larger) installments over the resolved term, reducing-balance interest. */
    public function generate(Loan $loan): InstallmentSchedule;
}

interface InterestEngine {
    /** 5% of the outstanding balance for one month; the charge, not the balance. */
    public function monthlyCharge(Loan $loan, CycleMonth $month): Money;

    /** Group-wide interest charged in a month — the pool to be distributed. */
    public function poolFor(CycleMonth $month): Money;
}

interface InterestPoolAllocator {
    /**
     * Pro-rata by cumulative savings, largest-remainder rounding.
     * Guarantees sum(allocations) === pool exactly.
     * @return Collection<int, InterestAllocation>
     */
    public function allocate(CycleMonth $month): Collection;
}

interface DisbursementQueue {
    public function enqueue(LoanRequest $request): LoanRequest;      // FIFO by requested_at
    public function queueFor(CycleMonth $month): Collection;
    /** Disburses in FIFO order until available funds run out; rest lapse. */
    public function runDisbursement(CycleMonth $month, Money $availableFunds, Member $actor): DisbursementResult;
}

interface PenaltyService {
    public function applyLateTransfer(Member $m, CycleMonth $month, int $daysLate): Penalty;   // K100/day → social fund
    public function applyMissedInstallment(LoanInstallment $i): Penalty;                        // 10% of outstanding
    public function waive(Penalty $p, Approval $approval, string $reason): Penalty;
}

interface LoanStateMachine {
    /** Requested → Approved → Disbursed → Repaying → Settled|Defaulted. Throws on illegal moves. */
    public function transition(Loan $loan, LoanStatus $to, Member $actor, ?string $note = null): Loan;
}

interface CollateralClaimService {
    public function openClaim(Loan $loan, array $items, Member $actor): CollateralClaim;
    public function signOff(CollateralClaim $claim, Approval $approval): CollateralClaim;
}

interface PayoutCalculator {
    /** Strategy per member status: active / early leaver / expelled / deceased. */
    public function calculate(Member $member, Cycle $cycle, ?CarbonInterface $asOf = null): PayoutBreakdown;
}

/** Every money mutation goes through here: DB transaction + activity log (who/when/before/after). */
interface MoneyMutator {
    public function mutate(Member $actor, string $reason, Closure $operation): mixed;
}

interface DualApprovalGate {
    public function request(Model $subject, string $action, Member $requester, ?string $note = null): Approval;
    /** Rejects if approver === requester or === first approver. */
    public function approve(Approval $approval, Member $approver): Approval;
    public function assertApproved(Model $subject, string $action): void;
}
```

---

## 4. Decisions — RESOLVED

1. **Packages** — ✅ `spatie/laravel-permission` approved.
   ❌ `barryvdh/laravel-dompdf` **rejected** → PDF output will be a print-optimised
   Blade/Inertia view with `@media print` rules that the user prints to PDF from the
   browser. Excel exports remain via `maatwebsite/excel`. Statements and the share-out
   report are therefore "Excel + printable HTML", not server-generated PDF files.
   Pre-approved by the brief: `spatie/laravel-activitylog`, `maatwebsite/excel`, `brick/money`.
2. **Social Fund loans at 10%/month (F2)** — ✅ **build in full**: `social_fund_loans`,
   `social_fund_loan_transactions`, and the `social_loan_requested_ngwee` declaration column,
   with origination and repayment workflow.
3. **Seeder data** — ✅ seed the **real member data** from the constitution: names, NRC
   numbers, physical addresses and next-of-kin (name, phone, relationship) for all 30 members.
   Note: this commits personal data of 30 people to the repository.

### Assumptions taken where no answer was given

These follow from the workbook itself; say the word if any is wrong.

4. **Interest model (F1)** — proceeding with pooled pro-rata distribution, since it is the
   literal formula in `SAVINGS!F6` of the live workbook, with December on `own_savings_flat`.
5. **Negative loan balances (F4)** — **allowed** as credit balances, with interest continuing
   to accrue on the negative figure, mirroring what the workbook actually does
   (Siitu Simutanyi, −K60.64). A dashboard flag surfaces members in credit.
6. **Repayment bands (F5)** — using the brief's table (`18,001–29,999 → 8`, `30,000+ → 10`),
   stored as cycle-scoped `repayment_terms` rows so the committee can amend them.
7. **Frontend** — Inertia v3 + Vue, matching the installed starter kit. Member portal is
   mobile-first.
