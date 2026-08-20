# Unity Savings Group — Module-by-Module Claude Code Prompts (v2 — Inertia + Vue 3, custom role-aware dashboard)

How to use: paste **Prompt 0** first in a fresh Claude Code session inside your repo. Then feed each module prompt in order after the previous module's tests pass. No Filament anywhere — all UI is a custom Inertia + Vue 3 dashboard with role-aware rendering.

---

## PROMPT 0 — PROJECT KICKOFF, CONVENTIONS & UI FOUNDATION

You are building the Unity Savings Group Management System, a Laravel app for a Zambian village-banking group (~30 members) running a Dec 2025 – Nov 2026 savings/lending cycle. We build module by module; I paste one module spec at a time. Set up the project and the UI foundation now; all later modules must follow these conventions.

**Stack:**
- Laravel 12, PHP 8.3, MySQL 8
- Inertia.js v2 + Vue 3 (Composition API, `<script setup>`) + TypeScript
- Tailwind CSS v4, Vite. NO component framework clone-outs — custom-designed components (I care about a distinctive dashboard design, not a Bootstrap/Filament look)
- Auth: Laravel Breeze (Inertia/Vue variant) as the base, then customized
- spatie/laravel-permission, spatie/laravel-activitylog, maatwebsite/excel, barryvdh/laravel-dompdf, Pest + vitest for Vue component tests
- Timezone `Africa/Lusaka`, currency ZMW

**Money & domain conventions — non-negotiable:**
- All money as integer ngwee (K1 = 100). `Money` Eloquent cast; a shared TS `formatMoney(ngwee): "K1,500.00"` util; API/Inertia props always send ngwee integers, formatting happens in Vue.
- Every financial mutation goes through a Domain Service in `app/Domain/{Module}/`, in a DB transaction, activity-logged. Business rules never live in controllers or Vue.
- Immutable ledgers: corrections are reversing entries, never edits/deletes.
- All records scoped to `cycle_id`; global scope + current-cycle resolver.
- Native PHP backed enums in `app/Enums/`; mirror them to TS via a generated `resources/js/types/enums.ts` (write an artisan command `unity:generate-ts-enums`).

**ROLE-AWARE UI ARCHITECTURE (the core of this prompt — build it properly once):**
1. Roles: `admin`, `chairperson`, `vice_chairperson`, `treasurer`, `vice_treasurer`, `member`. Granular permissions (e.g. `loans.approve`, `loans.disburse`, `payouts.execute`, `members.manage`, `fund.approve-outflow`, `governance.record`, `reports.view`, `declarations.submit-own`) assigned to roles in a seeder — permissions are the currency, roles are just bundles.
2. Backend is the source of truth: every route/action guarded by policies + permission middleware. Frontend awareness is UX only, never security.
3. `HandleInertiaRequests::share()` exposes `auth.user` (id, name, member_id, roles[], permissions[]) and `currentCycle` (id, name, status, key dates, lockdown flag, current trading window state) on every page.
4. Frontend helpers:
   - `usePermissions()` composable: `can('loans.approve')`, `hasRole('treasurer')`, `isCommittee()`.
   - `<Can permission="loans.approve">…</Can>` wrapper component (and `<Can :any="[...]">`).
   - Navigation is data-driven: a single `navigation.ts` config where each item declares required permissions; the sidebar renders only what the user can access. Same config drives a mobile bottom-nav for the member portal.
5. Two layouts, one app:
   - `AdminLayout.vue` — sidebar + topbar dashboard shell for committee/admin (desktop-first, but responsive).
   - `MemberLayout.vue` — mobile-first shell (bottom nav, large touch targets) for regular members: most members use phones.
   - Route groups: `/app/*` (committee, permission-gated per section) and `/my/*` (member self-service). A committee member who is also a member can switch between the two via a context switcher in the topbar.
6. Server-driven UI state pattern: pages receive an `abilities` prop per resource (e.g. a loan detail page gets `abilities: {approve: bool, disburse: bool, markDefault: bool}` computed from policies) so buttons render/disable from real policy results, not duplicated frontend logic.

**DESIGN SYSTEM (build now, reuse everywhere):**
- Design direction: clean fintech dashboard, distinctive not generic — pick a confident accent palette (suggest deep green + warm gold, evoking Zambian colors, on a near-white surface with a dark sidebar), Inter or similar for UI, tabular figures for money. Dark sidebar + light content. Show me a style tile (colors, type scale, spacing) as a demo page `/app/styleguide` before building components.
- Core components in `resources/js/Components/ui/`: `AppButton`, `AppCard`, `StatCard` (label, value, trend, icon), `DataTable` (TS generic: server-side pagination/sort/filter via Inertia partial reloads, column slots, row actions gated by abilities, CSV export hook), `MatrixTable` (sticky first column + sticky header, horizontal scroll — this powers all the workbook-style month-matrix screens), `Modal`, `ConfirmDialog` (with a `dual-approval` variant that captures a second user's credentials — see two-person rule), `FormField` wrappers with server-side validation error display, `MoneyInput` (formats K display, emits ngwee int, enforces step increments via prop), `StatusBadge` (enum-driven colors), `Stepper` (wizards), `EmptyState`, `Toast`.
- `MoneyInput` must support `:step="50000"` (K500 in ngwee) and min/max — savings and loan forms depend on it.
- Vitest tests for `MoneyInput` (increment enforcement, ngwee emission) and `DataTable` (ability-gated actions render/hide).

**Cycle mechanics (backend):**
- `cycles` table: name, start_date, end_date, declaration_open_day (1, opens 08:00), declaration_close_day (3), trading_start_day (4), trading_end_day (7), loan_lockdown_from (2026-09-01), savings_cap_during_lockdown (K500), final_repayment_deadline (2026-11-07), weekend_policy enum (`friday_before | monday_after`, default monday_after), status (`Planned | Active | ShareOut | Closed`).
- `WeekendAdjustmentPolicy` service with unit tests incl. month boundaries.
- Seed the Active 2025–26 cycle.

**Do now:** scaffold everything above, build the styleguide page and core components, seed roles/permissions and the cycle, write `docs/CONVENTIONS.md` (including the role-aware UI pattern) — then STOP and show me the styleguide before any module work.

---

## PROMPT 1 — MEMBERS, ROLES, AUTH, AUDIT

Build the membership foundation on the Prompt 0 architecture.

**Schema:**
- `members`: user_id (nullable — some members may never log in), full_name, nrc_number (unique, `######/##/#`), phone, physical_address, is_diaspora, joined_at, joining_fee_paid (ngwee), status enum `Active | LeftEarly | Expelled | Deceased`, status_changed_at, status_reason, expulsion_ground enum (`Dishonesty | Theft | UnrulyBehavior | LoanMisconduct`, required when Expelled), date_of_death (required when Deceased).
- `next_of_kin`: member_id, name, phone, relationship enum (`Spouse | Parent | Sibling | Child | Other` + label).

**Business rules:**
1. Joining fee ≥ K1,000; if `joined_at` in cycle month 3, ≥ K2,000 (late registration).
2. Registration hard-locks after the last day of cycle month 3 → `MembershipClosedException`; no override.
3. Status transitions only via `MemberStatusService`, each logged; transitions drive later payout logic.
4. Linking a `users` login to a member sends an invite (email now, SMS-ready later); a user account gets the `member` role automatically.

**UI (Inertia/Vue):**
- `/app/members` — DataTable: name, NRC, phone, status StatusBadge, diaspora tag, savings/loan summary columns (placeholder props until Modules 2–3); filters (status, diaspora); row actions gated by `abilities` (edit, change status, invite login). Visible to `members.manage` + read-only variant for `reports.view`.
- `/app/members/create` — form with next-of-kin repeater rows; joining-fee field auto-shows the K2,000 minimum notice when the join date is in month 3; entire page returns a locked state with an explanatory `EmptyState` after month 3.
- `/app/members/{id}` — profile: details card, next-of-kin card, status timeline (from activity log), placeholder StatCards for savings/loan/net value.
- Status-change flows via `ConfirmDialog`; expulsion requires selecting a ground; death requires date_of_death.
- `/my/profile` (MemberLayout) — own details + next of kin, read-only except phone/address (edit requests logged).
- Policy tests proving member A cannot access member B's data, and that a `treasurer` without `members.manage` sees read-only.

**Import stub:** `php artisan unity:import-members {file} {--dry-run}` parsing the commitment-sheet columns (name, NRC, address, NOK name/phone/relationship).

**Tests:** joining-fee tiers, month-3 boundary lockout, NRC validation, status transition guards, invite flow, authorization matrix (each role vs each members endpoint — write it as a Pest dataset).

---

## PROMPT 2 — SAVINGS LEDGER & STATEMENTS

**Schema:**
- `savings_transactions`: cycle_id, member_id, month (first of month), amount, type `Deposit | InterestPosting | Adjustment`, source `Declaration | Manual | Import`, recorded_by, notes. Immutable.
- `member_monthly_summaries` (cached, rebuildable via `unity:rebuild-summaries`): savings_this_month, interest_this_month, cumulative_savings, cumulative_interest.

**Business rules:**
1. Deposits: min K500, multiples of K500; from `loan_lockdown_from` the member's monthly deposit total may not exceed K500 → `LockdownSavingsCapException`. Non-Active members rejected.
2. `SavingsService::deposit()` is the only write path.
3. `InterestDistributionService`: monthly group loan-interest income distributed pro-rata by cumulative savings at month start; strategy behind an interface; rounding remainders → admin fund (documented). Build and test the math now with stubbed income; wire real income in Module 3.
4. Net Value = cumulative savings + interest − outstanding loan (loan side returns 0 until Module 3; define `OutstandingLoanProvider` interface now).

**UI:**
- `/app/savings` — MatrixTable replicating the workbook SAVINGS sheet: sticky member column; per-month column pairs (Savings | Interest); Total Savings, Total Interest, Net Value columns; footer totals row; month filter; Excel + PDF export buttons (server-generated, workbook-faithful layout). Manual deposit entry via modal (permission `savings.record`, MoneyInput step K500, live lockdown-cap warning when month ≥ September).
- `/app/savings/{member}` — member drill-down: monthly bar chart (savings) + line (cumulative), transaction ledger DataTable.
- `/my/savings` (MemberLayout) — StatCards (my total savings, interest earned, net value), monthly history list, "Download my statement (PDF)".
- Committee dashboard StatCard: group total savings (registers on `/app/dashboard`, which starts life in this module as a widget grid — later modules add their widgets).

**Tests:** increment validation (K499/K500/K750/K1,000), lockdown cap across multiple deposits, immutability, pro-rata worked example with exact ngwee + remainder handling, rebuild idempotency, `savings.record` permission gate.

---

## PROMPT 3 — LOANS ENGINE

Core module. Services in `app/Domain/Loans/`: `LoanEligibilityService`, `RepaymentScheduleGenerator`, `InterestEngine`, `PenaltyService`, `LoanDisbursementQueue`, `LoanRepaymentService`, `DefaultWorkflowService`.

**Schema:**
- `loans`: cycle_id, member_id, principal, status `Requested | Approved | Disbursed | Repaying | Settled | Defaulted | Rejected`, requested_at, approved_by, second_approver_id, disbursed_at, disbursement_position, settled_at, discretion_override (bool) + discretion_note, current_balance (denormalized, rebuildable).
- `loan_schedule_items`: due_month, amount_due, amount_paid, paid_at, status `Pending | Paid | PartiallyPaid | Missed`.
- `loan_transactions`: type `Disbursement | InterestCharge | Repayment | LatePenaltyDaily | MissedInstallmentPenalty | WriteOff`, amount, occurred_on, balance_after, notes. Immutable.
- `collateral_claims`: loan_id, status `Draft | CommitteeSignOff | Enforced | Released`, second_signer_id, items JSON [{description, estimated_value}].

**Business rules (each in its service, each unit-tested):**
1. **Eligibility** (at request AND re-checked at disbursement): Active member; no loan in Requested/Approved/Disbursed/Repaying (one at a time) unless `discretion_override` with note by a committee member; principal ≤ 2 × cumulative savings; request date before `loan_lockdown_from` (hard block, no override); schedule must end by `final_repayment_deadline` — compress tenor if needed and flag "compressed schedule".
2. **Tenor** by principal: 1,000–2,000→1mo; 2,001–5,000→2; 5,001–10,000→4; 10,001–18,000→6; 18,001–29,999→8; ≥30,000→10. Value object, boundary tests.
3. **Interest 5%/month reducing balance**: posted by `InterestEngine` on each trading date for Repaying loans; schedule = equal principal installments + current month's interest; keep original vs current expected amounts.
4. **Daily late penalty**: K100 × days late (received after adjusted trading date) → `LatePenaltyDaily` on the ledger + `LatePenaltyCharged` event (Social Fund mirrors it in Module 4 — stub listener now).
5. **Missed/partial installment**: month closes Missed or PartiallyPaid → 10% × outstanding balance penalty added.
6. **FIFO disbursement** by requested_at on the adjusted 7th; out-of-order requires typed reason.
7. **Two-person rule**: approval (and collateral sign-off) requires two distinct committee users. UI: `ConfirmDialog` dual-approval variant — first approver clicks approve, dialog then asks the second committee member to enter their own credentials on the same device (re-auth endpoint validating password + permission); both ids stored.
8. **Default workflow**: Defaulted → guided collateral claim (itemized household goods to the outstanding value, per the constitution's guarantee clause) → second sign-off → Enforced.
9. **K50,000 cycle target** per member: borrowed vs target vs balance-to-borrow — tracked and dashboarded, not blocking.

**UI:**
- `/app/loans` — DataTable with status tabs (Requested / Approved / Disbursed+Repaying / Settled / Defaulted), columns: member, principal, balance, next due, days-late flag; abilities-gated row actions.
- `/app/loans/request` — Stepper wizard: pick member → amount (MoneyInput; live eligibility panel calling an eligibility-check endpoint: shows 2×-savings ceiling for that member, one-loan status, lockdown state, computed tenor + preview schedule) → review → submit. Ineligibility reasons render inline, red, specific.
- `/app/loans/{id}` — header StatCards (principal, balance, next due, penalties to date), schedule table (original vs current), full ledger, action bar from `abilities` (approve [dual], disburse, record repayment, mark default).
- `/app/loans/queue` — trading-day FIFO queue: ordered list, disburse buttons top-down, out-of-order requires reason modal. Only `loans.disburse`.
- `/app/loans/matrix` — MatrixTable mirroring the workbook LOANS sheet + Excel/PDF export.
- `/app/loans/targets` — K50,000 tracker DataTable (borrowed, target, balance) with red badges under target.
- `/my/loan` (MemberLayout) — current loan card: balance, next amount + date, schedule, penalty history; request form shown only when eligible, otherwise a friendly explanation of each failed condition; loan history.
- Dashboard widgets: loans outstanding total, loans in queue, members with penalties this month.

**Tests (exact-ngwee worked examples):** tenor boundaries; 2× cap; one-loan + override; September block; deadline compression; full K10,000/4-month lifecycle (every InterestCharge, every installment); partial payment → 10% penalty; 3 days late → K300 + event; FIFO order; dual-approval (same user twice fails; second user without permission fails); balance rebuild = denormalized balance; eligibility endpoint contract test.

---

## PROMPT 4 — SOCIAL FUND

**Schema:**
- `social_fund_transactions`: cycle_id, member_id (nullable), type `Contribution | LatePenaltyInflow | FuneralGrant | UnityBabyGrant | GatheringExpense | DiasporaApportionment | Adjustment`, amount (signed), occurred_on, reference (e.g. loan_transaction_id), recorded_by, second_approver_id (required on ALL outflows), notes. Immutable.
- `funeral_grant_claims`: member_id, deceased_name, relationship enum RESTRICTED to `Parent | Spouse | Child`, claim_date, status `Submitted | Approved | Paid | Rejected`, approver ids.

**Business rules:**
1. Contribution: exactly K250, once per member, in full (reject ≠ K250; reject second).
2. `LatePenaltyCharged` listener → matching `LatePenaltyInflow` with back-reference; `unity:reconcile-social-fund` asserts loan-side penalties total = fund-side inflows total.
3. Funeral grant K1,000, ONLY parent/spouse/child (enum + policy; no override). Dual approval before Paid.
4. Unity baby grant K500, dual approval.
5. Diaspora apportionment tool: total amount → equal split across `is_diaspora` members → individual pending outflows → paid on transfer confirmation. Rounding remainder stays in fund.
6. Balance can never go negative → `InsufficientSocialFundException`.

**UI:**
- `/app/fund` — dashboard: balance StatCard, monthly in/out bar chart, unpaid-contributions list (with "record contribution" quick action), recent ledger.
- `/app/fund/ledger` — DataTable + workbook-style export.
- `/app/fund/claims` — claims DataTables (funeral, unity baby) with status flows and dual-approval dialogs; funeral claim form's relationship select only offers the three allowed values, with a note explaining the constitutional restriction.
- `/app/fund/apportionment` — diaspora split calculator: input total, preview per-member amounts, confirm → creates pending outflows; checklist UI to mark transfers done.
- `/my/fund` — own contribution status, submit/track own claims.

**Tests:** K250 exactness + duplicate rejection; penalty mirroring + reconciliation; sibling claim rejected; overdraw; diaspora rounding; outflows without second approver rejected.

---

## PROMPT 5 — DECLARATIONS & TRADING CONSOLE

**Schema:**
- `declarations`: cycle_id, member_id, month, saving_amount, loan_repayment_amount, loan_requested_amount, total_expected_payment (saving + repayment − loan_requested; may be negative), submitted_at, is_late, status `Submitted | Locked | Processed`.
- `trading_sessions`: cycle_id, month, scheduled_conclude_date (weekend-adjusted), status `Open | Concluded`, concluded_by.
- `trading_entries`: session_id, member_id, declaration_id, expected_in, actual_in, received_at, expected_out, actual_out, disbursed_at, variance, penalty_days.

**Business rules:**
1. Window: opens 08:00 on the 1st, closes end of the 3rd. Members blocked outside it; treasurer can enter late ones stamped `is_late`.
2. One declaration per member per month; editable until close, then Locked.
3. Cross-module validation: saving_amount via SavingsService rules (incl. lockdown cap), loan_requested via LoanEligibilityService (inline reasons), loan_repayment defaults to the member's scheduled amount.
4. Window close → auto-create trading session + pre-populate entries (expected_in = saving + repayment; expected_out from FIFO queue).
5. **Trading console** (treasurer, the operational heart): per-member rows — mark received (datetime → auto penalty_days vs adjusted conclude date), confirm FIFO disbursements, live totals (expected vs actual, cash position). **Conclude session**: preview screen ("this will post: N deposits totalling K…, M repayments…, interest for X loans, Y missed-installment penalties") → one transaction posting savings deposits, repayments, InterestEngine run, missed-installment marking. Full rollback on any failure.
6. Declarations export mirroring the workbook Declarations sheet (negative totals included).

**UI:**
- `/my/declarations` (MemberLayout) — the month's declaration form: three MoneyInputs with live validation (savings step/cap, repayment prefilled, loan request with eligibility feedback), computed Total Expected Payment (red when negative), window countdown banner; outside window → locked card with dates; history list.
- `/app/declarations` — month-stepped DataTable of all declarations, missing-members panel, late badges, export.
- `/app/trading` — the console: month stepper, session status, per-member grid (declared vs received with datetime pickers, penalty-day chips auto-computed), disbursement panel (FIFO order), sticky footer totals, Conclude button → preview modal → confirm. Only `trading.operate`.
- Dashboard widgets: current window state (declarations open / trading open / idle, with countdown), missing declarations count.

**Tests:** window boundaries (07:59 vs 08:00 on the 1st; 23:59 on the 3rd); one-per-month; negative expected payment; atomic conclusion with forced mid-failure rollback; penalty-day computation across weekend-adjusted dates; treasurer late-entry flagging.

---

## PROMPT 6 — EXITS & PAYOUT CALCULATOR

`app/Domain/Payouts/PayoutCalculator`, one method per case, returning `PayoutBreakdown` (line items + net); `payouts` table (member_id, case enum, breakdown JSON, amount, executed_at, two approver ids).

**The four cases:**
1. **ActiveShareOut**: savings + interest − outstanding loan = Net Value; round-off adjustment (CONFIRM the rounding convention with me — workbook has a "ROUNDOFF ADJSTMNT" column) → Net Payable; remainders → admin fund.
2. **LeftEarly**: savings ONLY, no interest, at cycle end; loans still deducted; negative net → debt record.
3. **Expelled**: savings, no interest, minus loans, at cycle end.
4. **Deceased**: savings − loan + interest accrued up to date_of_death only. Negative net → `next_of_kin_repayment_arrangements` record (member_id, next_of_kin_id, amount_owed, agreed_terms, status) instead of payout. Funeral grant stays in Module 4 but is linked on the closure screen.

**Rules:** payout case must match member status; LeftEarly/Expelled/Deceased blocked until cycle = ShareOut, with committee override + note for compassionate early settlement of Deceased; dual approval on execution; execution freezes the member's ledgers.

**UI:**
- `/app/closures` — list of members pending closure by status.
- `/app/closures/{member}` — Stepper wizard: computed breakdown rendered as an itemized statement card (every line: label, formula hint, amount) → linked funeral grant status if applicable → dual approval → execute → printable payout voucher PDF. Negative-net path swaps the final step for debt/arrangement creation.
- `/my/…` unaffected (closed members lose portal write access automatically).

**Tests:** each case with worked numbers; interest cutoff at date of death; negative nets create records not negative payments; ledger freeze; case/status mismatch rejected; ShareOut gating + override path.

---

## PROMPT 7 — GOVERNANCE

**Schema:**
- `committee_terms`: cycle_id, member_id, role enum (`Chairperson | ViceChairperson | Treasurer | ViceTreasurer | Signatory`), started_at, ended_at, end_reason (`TermEnd | Resigned | Removed`), resignation_notice_date.
- `meetings`: cycle_id, meeting_date, type (`Monthly | Special | ShareOut`), attendance pivot, quorum_met (computed).
- `motions`: meeting_id (nullable for out-of-meeting no-confidence), type (`NoConfidence | Amendment | General`), subject, target_member_id, proposed_by, votes_for/against/abstentions, threshold_basis (`TotalMembers | MembersPresent`), passed (computed), decided_at.
- `amendments`: motion_id, section_reference, current_text, proposed_text, effective_date.

**Business rules:**
1. 1-year term limit; succession helper proposes next-cycle committee (Vice-Chair→Chair, Vice-Treasurer→Treasurer) for confirmation, never auto-appoints.
2. Resignation: ended_at ≥ notice_date + 1 month (waivable with note).
3. No-confidence: 60% of TOTAL Active members; pass → term ends `Removed`, vacancy task opens.
4. Quorum: 60% of Active members present; no quorum → motions cannot be decided (UI disables the decide action with an explanation).
5. Amendments: only if ≥ 6 months since last passed amendment (or cycle start); 60% of members PRESENT — deliberately different basis from no-confidence.
6. Show-of-hands: tallies only, no per-member vote records.
7. Assigning a committee term grants the matching spatie role for its duration; ending the term revokes it (sync job + test).

**UI:**
- `/app/governance/committee` — current committee cards + term history timeline; succession proposal generator.
- `/app/governance/meetings/{id}` — attendance register built for a phone in the meeting room: full member list, tap-to-toggle present, live quorum ring (e.g. "18/30 — quorum met ✓"); motions panel: record tallies, auto pass/fail vs the correct threshold shown with the math ("needs 18 of 30 total members").
- `/app/governance/amendments` — log + new proposal (gated by the 6-month rule with a countdown when blocked).
- Only `governance.record`; read views for all committee.

**Tests:** both thresholds with fraction rounding (confirm ceiling with me, then test e.g. 29 members → 18); quorum gate; 6-month spacing; notice math; role sync on term start/end.

---

## PROMPT 8 — SHARE-OUT, DASHBOARDS, REPORTS & IMPORT

**Share-out:**
1. Cycle Active → ShareOut: pre-flight checklist page (all loans Settled/Defaulted-with-enforced-claims, all sessions concluded, fund reconciled, unpaid contributions resolved) — each item green/red with drill-down links; transition blocked until clean or overridden with notes (dual approval).
2. `/app/shareout` — MatrixTable pixel-mirroring the workbook SHARE OUT sheet (monthly savings+interest pairs, Total Savings, Total Interest, Outstanding Loan, Net Value, Round-off Adjustment, Net Payable; footer totals). Excel + PDF export. A test asserts the totals row ties to the ledgers to the ngwee.
3. Batch payout runner: iterate ActiveShareOut via Module 6, per-member vouchers + master payout schedule PDF for signatories.

**Dashboard (`/app/dashboard`, now complete):**
- Group StatCards: total savings, loans outstanding, social fund balance, cash position, trading window state.
- Risk widget: negative Net Value members with projected 3-month minimum repayments at 5%/month growth (mirrors the "Min Repayments-Negative NV" sheet), link to full page `/app/risk`.
- K50,000 target tracker summary.
- Compliance: unpaid contributions, late declarations, missed installments.
- Widgets permission-aware: a signatory without `loans.view` doesn't get loan widgets — driven by the same permissions config, tested.

**Reports hub (`/app/reports`):** all workbook-faithful exports in one place — SAVINGS, LOANS, SOCIAL FUND, Declarations, SHARE OUT — plus "Monthly statement pack": one command/button generating the month's full pack (Module 9 mails it).

**Workbook import (now that all ledgers exist):** `unity:import-workbook {file} {--dry-run}` for the real `UNITY_SAVINGS_Spreadsheet2025_TO_2026.xlsx` (sheets: SAVINGS, LOANS, SOCIAL FUND, Declarations, work sheet) as historical transactions; idempotent (natural keys member+month+type); ends with a reconciliation report (imported vs recomputed vs workbook totals, every discrepancy listed). Also an `/app/import` UI: upload → dry-run preview table → confirm.

**Tests:** pre-flight gate; share-out tie-out; negative-NV projection math; import idempotency on a fixture workbook; permission-aware widget rendering.

---

## PROMPT 9 — NOTIFICATIONS & POLISH

**Channels:** `NotificationChannelManager` — mail now, `SmsGateway` interface with log-only fake (real gateway later, likely Africa's Talking; build the interface only). Member notification preferences (mail/SMS/both) on `/my/settings`.

**Scheduled (cycle-date-aware, weekend-adjusted):**
1. Declaration window opening — 1st, 08:00, all Active members.
2. Declaration reminder — 3rd morning, non-submitters only.
3. Trading day — adjusted 7th, committee.
4. Repayment due — 2 days before trading, members with items due, amount included.
5. Penalty applied — event-driven, immediate, math shown.
6. September lockdown — Aug 25 + Sept 1: no new loans, K500 cap.
7. Final-deadline countdown — weekly from Oct 1 to members with balances: balance + required payments to clear by 7 Nov.
8. Statement pack — after session conclusion: personal statement to each member, full pack to committee.

**Polish:**
- `/app/audit` — activity-log review (filter by user/model/date), chairperson + admin.
- Nightly DB dump command + storage docs.
- Member portal auth hardening: rate limiting, lockout.
- Seeders: demo cycle, 30 placeholder members, 3 months of realistic transactions.
- `docs/RUNBOOK.md`: treasurer monthly checklist (open declarations → close → console → conclude → statements) + cycle-end checklist.
- Lighthouse pass on the member portal (mobile); fix anything egregious.
- Full test suite + coverage summary by module; list known gaps.

---

## OPEN QUESTIONS THE AGENT MUST ASK (don't let it guess)

1. Interest: 5%/month reducing balance (workbook behavior — the default here) vs 5% once at disbursement (one constitution line)?
2. Share-out round-off convention (nearest K5? K10? down or nearest)?
3. 60% thresholds: ceiling on fractions?
4. Interest distribution to members: pro-rata by savings assumed — confirm against how the treasurer fills "Interest Earned" today.
5. The workbook's Trading sheet pairs members with equal amounts (e.g. two members at K30,000) — is that member-to-member matching (one member's inflow funds another's loan)? If yes, the trading console needs a matching feature; explain the current manual process.
