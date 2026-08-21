<?php

namespace App\Http\Controllers\My;

use App\Domain\Notifications\NotificationChannelManager;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's notification preferences.
 *
 * The page tells the member the truth about what will actually happen rather than
 * only what they asked for: a member who picks SMS but has no number on record is
 * shown that the group will still email them, because NotificationChannelManager
 * falls back rather than dropping the message.
 */
class SettingsController extends Controller
{
    public function show(Request $request, NotificationChannelManager $channels): Response
    {
        $member = $this->member($request);

        return Inertia::render('my/Settings', [
            'member' => $member === null ? null : [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'phone' => $member->phone,
                'email' => $member->user?->email,
                'notification_channel' => $member->notification_channel->value,
            ],
            'channels' => array_map(fn (NotificationChannel $channel): array => [
                'value' => $channel->value,
                'label' => $channel->label(),
            ], NotificationChannel::cases()),
            'effective' => $member === null ? [] : $channels->for($member),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        activity()
            ->performedOn($member)
            ->causedBy($request->user())
            ->event('notification_preferences_updated')
            ->log('Notification preferences updated by the member');

        return back()->with('success', 'Your notification settings have been saved.');
    }

    protected function member(Request $request): ?Member
    {
        return Member::query()->where('user_id', $request->user()->id)->with('user')->first();
    }
}
