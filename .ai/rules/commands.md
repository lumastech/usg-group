---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Open a shut window with unity:open-for-testing, never by editing the domain
The constitution's windows are dates on `cycles` and `cycle_months` — DeclarationWindow, MemberPolicy::create and Cycle::isLockdownMonth all read them. To exercise a month out of season, move the dates with `unity:open-for-testing` (--phase=declarations|trading, --close to restore); never add a test-only bypass inside a domain service, or the guard you skipped stops being covered.

--close restores from a snapshot in storage/app/private and refuses when none exists. Do not "restore" by re-running CycleMonthPlanner::plan() — it resets every month's status to pending, silently undoing months the group already traded.

UNITY_OPEN_REGISTRATION makes a /register sign-up also register a member, but it routes through MembershipRegistrar, so the cycle's registration window and joining fee tier still apply. Both switches must be closed before real money is held.
