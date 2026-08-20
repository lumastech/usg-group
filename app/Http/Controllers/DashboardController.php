<?php

namespace App\Http\Controllers;

use App\Enums\MemberRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The post-login entry point.
 *
 * There is no dashboard of its own here: the portal has exactly two landing pages,
 * /app for the committee and /my for everyone else. This route exists so that a
 * single well-known URL — the one Fortify redirects to after login — sends each
 * user to the portal they actually work in.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $committee = array_map(
            fn (MemberRole $role): string => $role->value,
            array_filter(MemberRole::cases(), fn (MemberRole $role): bool => $role->isCommittee()),
        );

        return redirect()->route(
            $request->user()->hasAnyRole($committee) ? 'app.dashboard' : 'my.dashboard'
        );
    }
}
