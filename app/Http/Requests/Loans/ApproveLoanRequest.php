<?php

namespace App\Http\Requests\Loans;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The two-person rule, arriving as one request.
 *
 * The signed-in committee member is the first approver; the second types their own
 * credentials into the dialog on the same device. Both halves are verified here against
 * the server — the client only collects them.
 */
class ApproveLoanRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('loan'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->secondApproverRules();
    }

    public function loan(): Loan
    {
        return $this->route('loan');
    }

    /** The confirming committee member, once their credentials have checked out. */
    public function confirmer(): Member
    {
        return $this->secondApprover(Permission::LoansApprove);
    }
}
