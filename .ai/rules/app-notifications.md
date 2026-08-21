---
paths:
  - 'app/Notifications/**'
---

# App Notifications

## Member is the notifiable, not User; SMS is a real channel behind an interface
Notifications go to `Member`, which uses Notifiable with routeNotificationForMail() → user?->email and routeNotificationForSms() → phone. That is deliberate: the phone number lives on the member record, so the group can text somebody who has never been invited to sign in.

Extend App\Notifications\MemberNotification and implement toMail() + toSms(). Write the SMS body separately — it is 160 characters, not a squeezed email. `via()` is inherited; do not override it.

The SMS channel is registered in AppServiceProvider (Notification::extend('sms', …)) and resolves SmsGateway from config('notifications.sms.gateway'). The default LogSmsGateway writes what it would have sent to the log. Nothing in the app may name a provider — swapping in Africa's Talking is one new class plus one config value.
