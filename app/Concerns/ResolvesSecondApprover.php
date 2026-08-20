<?php

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Turns the credentials typed into the dual-approval dialog into a second approver.
 *
 * The two-person rule only means something if the second signature is really theirs, so
 * the password is verified here rather than trusted from the client, and the permission
 * is checked against the server's own record of what that user holds. Every refusal
 * lands on the password field, where the person confirming is looking.
 */
trait ResolvesSecondApprover
{
    /**
     * @return array<string, mixed>
     */
    protected function secondApproverRules(): array
    {
        return [
            'approver_email' => ['required', 'email'],
            'approver_password' => ['required', 'string'],
        ];
    }

    /**
     * The member behind the confirming credentials.
     *
     * @param  Permission  $permission  the ability the confirmer must hold
     */
    protected function secondApprover(Permission $permission): Member
    {
        $user = User::query()->where('email', $this->input('approver_email'))->first();

        if ($user === null || ! Hash::check($this->input('approver_password'), $user->password)) {
            throw ValidationException::withMessages([
                'approver_password' => 'Those credentials do not match a portal login.',
            ]);
        }

        if ($user->is($this->user())) {
            throw ValidationException::withMessages([
                'approver_email' => 'This action needs a second, different committee member to confirm it.',
            ]);
        }

        if (! $user->can($permission->value)) {
            throw ValidationException::withMessages([
                'approver_email' => "{$user->name} does not hold the permission needed to confirm this action.",
            ]);
        }

        $member = $user->member;

        if ($member === null) {
            throw ValidationException::withMessages([
                'approver_email' => "{$user->name} is not linked to a member record in this cycle.",
            ]);
        }

        return $member->load('user');
    }
}
