---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## /dashboard is a redirect, not a page
The portal has exactly two landing pages: `app.dashboard` (/app, committee) and `my.dashboard` (/my, member). `App\Http\Controllers\DashboardController` at /dashboard renders nothing — it forwards each user to one of those based on `MemberRole::isCommittee()`. It exists only because Fortify's `home` config and post-login/passkey redirects need one well-known URL.

Do not add a third dashboard page. Committee figures belong in `App\Http\Controllers\App\DashboardController` + `resources/js/pages/app/Dashboard.vue`, fed by `CycleOverview` so the dashboard and the reports agree by construction. Tests that just need any authenticated page for shared-prop assertions should hit `route('my.dashboard')`.
