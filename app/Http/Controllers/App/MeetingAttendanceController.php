<?php

namespace App\Http\Controllers\App;

use App\Domain\Governance\MeetingRegister;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tapping a name on the attendance register.
 *
 * One member, one request, so the ring on screen tracks the room as it fills. The
 * mark is a set membership rather than a stored yes/no: marking somebody present who
 * already is changes nothing, which is what makes a shaky thumb harmless.
 */
class MeetingAttendanceController extends Controller
{
    public function __construct(protected MeetingRegister $register) {}

    public function __invoke(Request $request, Meeting $meeting, Member $member): RedirectResponse
    {
        $this->authorize('record', $meeting);

        $validated = $request->validate([
            'present' => ['required', 'boolean'],
        ]);

        $this->register->mark($meeting, $member, (bool) $validated['present']);

        return back();
    }
}
