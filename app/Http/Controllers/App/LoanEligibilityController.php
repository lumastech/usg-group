<?php

namespace App\Http\Controllers\App;

use App\Domain\Loans\LoanEligibilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\CheckLoanEligibilityRequest;
use App\Support\Kwacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The live eligibility panel behind the request wizard.
 *
 * It answers with the same object the service uses internally, so what the member is
 * shown while typing an amount is exactly what will be enforced when they submit — the
 * ceiling, the open-loan position, the lockdown state and the tenor the amount earns.
 */
class LoanEligibilityController extends Controller
{
    public function __invoke(CheckLoanEligibilityRequest $request, LoanEligibilityService $eligibility): JsonResponse
    {
        $result = $eligibility->check(
            $request->member(),
            Kwacha::ofNgwee($request->integer('principal_ngwee')),
            Carbon::now(),
            $request->boolean('overriding'),
        );

        return response()->json($result->toArray());
    }
}
