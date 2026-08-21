---
paths:
  - 'app/Domain/Notifications/**'
---

# Notifications

## One daily pass owns every calendar notification
CycleNotificationScheduler::run(Cycle, date) evaluates all six calendar rules against the `cycle_months` rows CycleMonthPlanner already laid out — including the weekend adjustment. Never recompute a date here or add a second scheduled command per rule: drift between the notification dates and the dates the declaration/trading screens enforce is silent and nobody notices until a member complains.

Every batch is claimed in `notification_dispatches` via NotificationDispatchLog::once() BEFORE sending. The row is written first on purpose — a crash mid-batch means some members miss one reminder, rather than everyone getting a duplicate money notice next run. The unique index on `key` is what makes concurrent runs safe.

Notifications never hard-code a channel: MemberNotification::via() asks NotificationChannelManager, which honours the member's preference and falls back to any channel they actually have an address for.
