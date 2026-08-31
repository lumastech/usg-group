---
paths:
  - 'app/Domain/Members/**'
---

# Members

## Membership registration is a window, not a permission
Registration closes after month 3 of the cycle (Cycle::registrationOpenForMonth) and the constitution allows no override — MemberPolicy::create returns false for admins too, so there is no "force" path to add one.

Anyone joining in month 3 pays the late fee (K2,000 vs K1,000). MembershipRegistrar::register validates the amount actually paid against that tier and throws JoiningFeeBelowMinimumException; it never silently substitutes the minimum.

unity:import-members deliberately registers everyone on the commitment sheet as joining on the cycle's first day, whatever date the import is run — importing in month 5 must not charge the founding signatories the late fee.

## A member's email belongs to their login, and only MemberEmailUpdater moves it
There is no email column on `members` — the address sits on the linked `users` row, which is why `Member::routeNotificationForMail()` returns null for anyone never invited.

The committee edits it on the member edit form, but the write goes through `App\Domain\Members\MemberEmailUpdater`: it lowercases, refuses an address another login holds (merging accounts is not a correction), clears `email_verified_at`, and logs an `email_changed` activity on the member so the change shows on the profile timeline. Redirecting password resets is an account-takeover path, so it must stay auditable.

A member with no login is refused rather than quietly given one — attaching a login is `MemberInviter`'s job, and it sends an invitation the member has to act on. `UpdateMemberRequest` mirrors this: `email` is `prohibited` unless `$member->hasLogin()`.
