# Runbook

What the committee does, and when. Everything here is a real screen or a real
command — nothing in this document is aspirational.

The system is cycle-date-aware: declarations open at 08:00 on the 1st, close at the
end of the 3rd, trading opens on the 4th and concludes on the 7th unless the 7th is
a weekend, in which case the cycle's weekend policy moves it. Those dates are laid
out once in `cycle_months` when the cycle is planned, and every screen, export and
notification reads them from there. **If a date looks wrong, fix the cycle, not the
screen.**

---

## 1. The monthly cycle

### 1st — declarations open

Nothing to do. At 08:00 the scheduler runs `unity:notify`, which mails and texts
every active member that the window is open, with the minimum saving and — from the
lockdown month on — the cap.

Check it went: `/app/audit`, or the `notification_dispatches` table.

### 1st to 3rd — capture what comes in by phone

Most members declare themselves at `/my/declarations`. For anybody who phones the
treasurer instead:

1. `/app/declarations` → **Record on behalf**.
2. Enter the saving and the repayment they have committed to.

The reminder to non-submitters goes out automatically on the morning of the 3rd.
Nobody has to build the chase list — it is whoever has not submitted.

### 4th — the sheet is laid out

`unity:open-trading-sessions` runs at 06:00 daily and opens the session for any
month whose window has closed, locking the declarations into it. If a declaration
is captured late, running the schedule again keeps the sheet in step.

Nothing to do unless the console shows no session: then run it by hand.

```bash
php artisan unity:open-trading-sessions
```

### The 7th (adjusted) — trading day

The committee is notified on the morning of the day, with the number of
declarations and the total expected in.

1. `/app/trading` — the console.
2. Mark each member's money **as it is received**, with the date it actually
   arrived. The date drives the K100-a-day late penalty; entering the 9th on the
   12th still charges two days, and entering the 12th charges five.
3. Work the disbursement queue for approved loans.
4. Check the preview totals against the cash on the table.
5. **Conclude the session.**

Concluding is the only act that posts anything. Until then the whole day is a
worksheet. It posts, in one transaction and in this order: last month's missed
installments and their 10%, this month's interest, then every member's savings and
repayment. Either all of it lands or none of it does.

### After concluding — statements

Automatic. Concluding raises `TradingSessionConcluded`; the listener builds the
month's pack and posts it out — each member their own statement, the committee the
four group sheets. To rebuild a pack by hand:

```bash
php artisan unity:statement-pack --month=<sequence>
```

### Two days before trading

Members with an installment falling due are told the amount. No action needed.

---

## 2. The treasurer's monthly checklist

- [ ] **1st** — confirm the declaration notice went out.
- [ ] **1st–3rd** — capture phoned-in declarations on behalf of members.
- [ ] **3rd, evening** — check `/app/declarations` for who is still missing; call them.
- [ ] **4th** — confirm the trading session is open at `/app/trading`.
- [ ] **5th** — check the repayment reminders went to everyone with an installment.
- [ ] **7th (adjusted)** — run the trading console; mark receipts with real dates.
- [ ] **7th** — reconcile the preview totals against the cash, then **conclude**.
- [ ] **7th** — confirm the statement pack was built and posted.
- [ ] **Any month** — `php artisan unity:reconcile-social-fund` should report agreement.

---

## 3. Cycle-end checklist

### From the lockdown month (September)

The lockdown notice goes out a week before the month opens and again on the 1st.
From then on:

- No new loans may be issued. The application form refuses them; there is no
  override.
- Savings are capped at K500 a month. The declaration form refuses more.

- [ ] Confirm both notices went out.
- [ ] Work `/app/risk` — every member under water, and the minimum repayments that
      bring them level. Call them before the deadline, not after.

### From 1 October — the countdown

Every seventh day, each member still carrying a balance is told what they owe and
what they must pay on each remaining trading day to clear it by 7 November.

- [ ] Weekly: check `/app/risk` is shrinking.
- [ ] Any member who will not clear: agree an arrangement and record it.

### 7 November — final repayment date

- [ ] Conclude the November trading session as normal.
- [ ] Anything still outstanding is a default; work it through
      `/app/loans` → mark default, and the collateral claim if there is one.

### Share-out

- [ ] `/app/shareout/preflight` — every check must pass. It will not let you past
      an unreconciled fund or an unconcluded month.
- [ ] `/app/shareout` — review the sheet against the workbook.
- [ ] Run the batch. Payouts are two-person: an approver and an executor, and they
      may not be the same person.
- [ ] Executing a payout **freezes that member's ledgers permanently.** Nothing can
      be posted against them afterwards. Check the voucher before you execute it.
- [ ] Export the pack, print the vouchers, close the cycle.

---

## 4. Commands

| Command | When | What |
| --- | --- | --- |
| `unity:notify` | daily 08:00, scheduled | The day's calendar notifications. Idempotent. |
| `unity:notify --pretend --date=…` | any time | Dry run — shows what a date would send. |
| `unity:open-trading-sessions` | daily 06:00, scheduled | Opens sessions for closed windows. |
| `unity:backup-database` | nightly 01:30, scheduled | Dump + prune. See `docs/STORAGE.md`. |
| `unity:statement-pack` | after concluding | Rebuilds a month's pack. |
| `unity:rebuild-summaries` | after an import or restore | Rebuilds cached balances from the ledgers. |
| `unity:reconcile-social-fund` | monthly | Asserts loan-side penalties and fund inflows agree. |
| `unity:sync-committee-roles` | after an import or restore | Reconciles portal roles from `committee_terms`. |
| `unity:import-workbook` | once, at migration | Imports the group's spreadsheet as history. |

The scheduler must actually be running for any of the daily ones to happen:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. When something looks wrong

**A ledger figure is wrong.** Do not edit it. Every ledger in this system is
immutable and a correction is a reversing entry. `/app/audit` shows who posted what.

**A member says they were charged a penalty unfairly.** The notification they
received shows the arithmetic. The same figures are on their statement and in
`/app/loans/<id>`. If it is genuinely wrong, reverse it — do not delete it.

**A member is not receiving notifications.** `/my/settings` shows what the system
will actually do for them: a member with no phone number and no portal login cannot
be reached at all. Give them a number or invite them at `/app/members`.

**The month posted half way.** It cannot have. Concluding is one transaction. If the
console shows a session still open, nothing was posted; run it again.
