<?php

namespace App\Http\Controllers\App;

use App\Domain\SocialFund\SocialFundContributions;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialFund\StoreSocialFundContributionRequest;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/** Records one member's K250 into the Social Fund. */
class SocialFundContributionController extends Controller
{
    public function __construct(protected SocialFundContributions $contributions) {}

    public function __invoke(StoreSocialFundContributionRequest $request): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['amount_ngwee' => 'Your login is not linked to a member record.']);
        }

        $member = $request->member();

        try {
            $this->contributions->record(
                $member,
                Kwacha::ofNgwee($request->integer('amount_ngwee')),
                $actor,
                $request->filled('occurred_on') ? Carbon::parse($request->string('occurred_on')->toString()) : null,
                $request->input('note'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['amount_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', "Social fund contribution recorded for {$member->full_name}.");
    }
}
