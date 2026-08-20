<?php

namespace App\Concerns;

use App\Enums\NextOfKinRelationship;
use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validation shared by every form that writes a member record.
 *
 * The NRC pattern is Zambia's national registration card format, ######/##/#, and
 * uniqueness is per cycle rather than global: the same person re-registers with the
 * same NRC when the group starts a new cycle.
 */
trait MemberValidationRules
{
    public const NRC_PATTERN = '/^\d{6,7}\/\d{2}\/\d$/';

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nrcRules(int $cycleId, ?int $memberId = null): array
    {
        $unique = Rule::unique(Member::class, 'nrc_number')->where('cycle_id', $cycleId);

        return [
            'required',
            'string',
            'regex:'.self::NRC_PATTERN,
            $memberId === null ? $unique : $unique->ignore($memberId),
        ];
    }

    /**
     * Details a member may keep up to date about themselves.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function contactRules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:30'],
            'physical_address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The next-of-kin repeater rows.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function nextOfKinRules(): array
    {
        return [
            'next_of_kin' => ['array', 'max:5'],
            'next_of_kin.*.name' => ['required', 'string', 'max:255'],
            'next_of_kin.*.phone' => ['nullable', 'string', 'max:30'],
            'next_of_kin.*.relationship' => ['required', Rule::enum(NextOfKinRelationship::class)],
            'next_of_kin.*.relationship_label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function memberValidationMessages(): array
    {
        return [
            'nrc_number.regex' => 'The NRC must be in the format 123456/78/9.',
            'nrc_number.unique' => 'Another member in this cycle is already registered with that NRC.',
            'next_of_kin.*.name.required' => 'Give the name of each next of kin, or remove the row.',
        ];
    }
}
