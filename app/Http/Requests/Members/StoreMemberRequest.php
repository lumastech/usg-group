<?php

namespace App\Http\Requests\Members;

use App\Concerns\MemberValidationRules;
use App\Domain\Cycles\CurrentCycle;
use App\Domain\Members\MembershipRegistrar;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Registering a new member.
 *
 * The joining fee minimum depends on which month of the cycle the member joins in,
 * so it is resolved from the submitted join date rather than fixed on the form.
 */
class StoreMemberRequest extends FormRequest
{
    use MemberValidationRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Member::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cycle = $this->cycle();

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'nrc_number' => $this->nrcRules($cycle->id),
            'is_diaspora' => ['boolean'],
            'joined_on' => [
                'required',
                'date',
                'after_or_equal:'.$cycle->starts_on->toDateString(),
                'before_or_equal:'.$cycle->ends_on->toDateString(),
            ],
            'joining_fee_ngwee' => ['required', 'integer', 'min:'.$this->minimumJoiningFeeNgwee()],
            'joining_fee_paid' => ['boolean'],
            ...$this->contactRules(),
            ...$this->nextOfKinRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->memberValidationMessages(),
            'joining_fee_ngwee.min' => sprintf(
                'Members joining in month %d of the cycle pay at least %s.',
                $this->monthSequence(),
                Kwacha::format($this->minimumJoiningFeeNgwee()),
            ),
        ];
    }

    /** Which month of the cycle the submitted join date falls in. */
    public function monthSequence(): int
    {
        return app(MembershipRegistrar::class)->monthSequenceFor(
            $this->cycle(),
            Carbon::parse($this->date('joined_on') ?? Carbon::today()),
        );
    }

    public function minimumJoiningFeeNgwee(): int
    {
        return Kwacha::toNgwee(
            app(MembershipRegistrar::class)->joiningFeeFor($this->cycle(), $this->monthSequence()),
        );
    }

    public function cycle(): Cycle
    {
        return app(CurrentCycle::class)->getOrFail();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_diaspora' => $this->boolean('is_diaspora'),
            'joining_fee_paid' => $this->boolean('joining_fee_paid'),
        ]);
    }
}
