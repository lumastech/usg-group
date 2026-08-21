<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail.
 *
 * Every financial mutation in the application is activity-logged, and the ledgers
 * are immutable — a correction is a reversing entry, never an edit. That makes this
 * page the group's actual accountability mechanism rather than a debugging aid: the
 * chairperson can see who did what, to which record, on which day, and nothing that
 * ever happened can be missing from it.
 *
 * Read-only by construction. There is no route here that writes.
 */
class AuditController extends Controller
{
    /** How many entries a page shows. The log is long; the filters are the tool. */
    protected const PER_PAGE = 30;

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->can(Permission::AuditView->value) ?? false, 403);

        $activities = Activity::query()
            ->with('causer')
            ->when($request->filled('causer'), fn ($query) => $query
                ->where('causer_type', User::class)
                ->where('causer_id', $request->integer('causer')))
            ->when($request->filled('subject_type'), fn ($query) => $query
                ->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('log'), fn ($query) => $query
                ->where('log_name', $request->string('log')))
            ->when($request->filled('from'), fn ($query) => $query
                ->where('created_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query
                ->where('created_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where('description', 'like', '%'.$request->string('search')->trim().'%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('app/Audit', [
            'activities' => [
                'data' => $activities->getCollection()->map(fn (Activity $activity): array => $this->row($activity))->all(),
                'meta' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'from' => $activities->firstItem(),
                    'to' => $activities->lastItem(),
                ],
            ],
            'filters' => [
                'causer' => $request->integer('causer') ?: null,
                'subject_type' => $request->string('subject_type')->toString() ?: null,
                'log' => $request->string('log')->toString() ?: null,
                'from' => $request->string('from')->toString() ?: null,
                'to' => $request->string('to')->toString() ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'causers' => $this->causers(),
            'subjectTypes' => $this->subjectTypes(),
            'logs' => $this->logs(),
        ]);
    }

    /**
     * One log entry, flattened for the table.
     *
     * The raw properties are sent alongside the summary so a reviewer can open a row
     * and see the before/after the log actually recorded, rather than a paraphrase.
     *
     * @return array<string, mixed>
     */
    protected function row(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'description' => $activity->description,
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'causer' => $activity->causer instanceof User
                ? ['id' => $activity->causer->id, 'name' => $activity->causer->name]
                : null,
            'subject_type' => $activity->subject_type,
            'subject_label' => $activity->subject_type === null
                ? null
                : class_basename($activity->subject_type).' #'.$activity->subject_id,
            'subject_id' => $activity->subject_id,
            'properties' => $activity->properties->toArray(),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * The users who have actually caused something, for the filter.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function causers(): array
    {
        return User::query()
            ->whereIn('id', Activity::query()
                ->where('causer_type', User::class)
                ->whereNotNull('causer_id')
                ->distinct()
                ->pluck('causer_id'))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }

    /**
     * The model types present in the log, for the filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function subjectTypes(): array
    {
        return Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => Str::headline(class_basename($type)),
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function logs(): array
    {
        return Activity::query()
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->map(fn (string $log): array => ['value' => $log, 'label' => Str::headline($log)])
            ->all();
    }
}
