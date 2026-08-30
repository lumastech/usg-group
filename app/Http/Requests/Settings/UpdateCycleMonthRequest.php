<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Shape only: which dates were sent and that they are dates.
 *
 * Whether the resulting calendar is a legal one — inside the month, in order, and on a
 * month that has not already been traded — belongs to CycleCalendar, which is the
 * single place those answers are decided.
 */
class UpdateCycleMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::CyclesCalendar->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'declarations_open_at' => ['required', 'date'],
            'declarations_close_at' => ['required', 'date'],
            'trading_starts_on' => ['required', 'date'],
            'trading_concludes_on' => ['required', 'date'],
            'disbursement_on' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'declarations_open_at' => 'declarations open',
            'declarations_close_at' => 'declarations close',
            'trading_starts_on' => 'trading opens',
            'trading_concludes_on' => 'trading concludes',
            'disbursement_on' => 'disbursement',
        ];
    }

    /**
     * One of the posted dates, read in the group's own timezone.
     *
     * Non-nullable because the rules above have already refused a missing one; the
     * calendar takes dates, not maybes.
     */
    public function scheduleDate(string $field): CarbonInterface
    {
        return $this->date($field)
            ?? throw new InvalidArgumentException("The [{$field}] date was not validated before it was read.");
    }
}
