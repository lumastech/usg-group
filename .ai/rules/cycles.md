---
paths:
  - 'app/Domain/Cycles/**'
---

# Cycles

## The calendar is dates, never rules — and a month's dates stay inside their month
`cycle_months` rows are what DeclarationWindow, the trading console and CycleNotificationScheduler all read, so `CycleCalendar` (behind /app/settings/calendar, `cycles.manage`) is the only place a window may be moved, and moving one opens the real code path with the real validation still running.

Three constraints it enforces, all load-bearing:
- Every date must fall inside that month's own calendar month. HandleInertiaRequests::currentMonth() resolves "this month" by the calendar, so a window outliving its month would leave the shell showing one month while another was still taking declarations.
- A month whose status is Closed has been traded and posted; its dates are the record of a session that happened and may not be moved. Same for a cycle past Active.
- Declarations must close before trading opens: the session is built from the sheet and the sheet cannot move underneath it. Extending a window during trading is the treasurer's `onBehalf` late capture, not a calendar change.

CycleMonthPlanner::datesFor() is the single source of the 1st/3rd/4th/7th shape — `plan()` and CycleCalendar::resetToConstitution() both read it, so "back to the constitution" and a freshly planned cycle can never diverge. `unity:open-for-testing` still exists for CLI dry runs and snapshots what it overwrites; the settings screen does not.
