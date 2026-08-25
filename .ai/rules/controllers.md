---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## /dashboard is a redirect, not a page
The portal has exactly two landing pages: `app.dashboard` (/app, committee) and `my.dashboard` (/my, member). `App\Http\Controllers\DashboardController` at /dashboard renders nothing — it forwards each user to one of those based on `MemberRole::isCommittee()`. It exists only because Fortify's `home` config and post-login/passkey redirects need one well-known URL.

Do not add a third dashboard page. Committee figures belong in `App\Http\Controllers\App\DashboardController` + `resources/js/pages/app/Dashboard.vue`, fed by `CycleOverview` so the dashboard and the reports agree by construction. Tests that just need any authenticated page for shared-prop assertions should hit `route('my.dashboard')`.

## Resolve the acting member with User::actingMember(), never $request->user()->member
The administrator holds every permission (MemberRole::Admin => Permission::cases()) and deliberately no member record, so any policy-passing action that hands `$request->user()->member` to a domain service's non-nullable `Member $actor` used to 500 with a TypeError.

`User::actingMember(): Member` throws AuthorizationException with a message the person at the screen can act on. Use it wherever the actor flows into a non-nullable Member param and there is no form field to hang an error on. Controllers that already guard with the house pattern — `back()->withErrors([... 'Your login is not linked to a member record.'])` — keep it; an inline field error is better UX than a 403 on a form submission.

Domain services deliberately take a non-nullable actor: an entry in the ledgers names the member who made it. Do not loosen a service signature to `?Member` to get past this.
