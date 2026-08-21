<?php

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How a member wants to be reached.
 *
 * Authorised against the member record on the route, not the signed-in user, so the
 * same policy that guards the rest of a member's own details guards this too.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateOwnContactDetails', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notification_channel' => ['required', Rule::enum(NotificationChannel::class)],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notification_channel.required' => 'Choose how you would like to hear from the group.',
        ];
    }
}
