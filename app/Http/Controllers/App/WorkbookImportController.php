<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Import\ImportReconciliation;
use App\Domain\Import\WorkbookImporter;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShareOut\UploadWorkbookRequest;
use App\Models\Cycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Upload, look at what would happen, then confirm.
 *
 * The upload and the import are deliberately two requests. A workbook kept by hand for
 * a year will always have a row the app cannot resolve, and the committee has to see
 * that list — and the reconciliation under it — before anything is written. The file
 * stays on the private disk between the two steps and is deleted once it has been
 * imported.
 *
 * The import itself is idempotent on the natural key, so a confirmation posted twice
 * by an impatient click posts nothing the second time.
 */
class WorkbookImportController extends Controller
{
    public function __construct(
        protected WorkbookImporter $importer,
        protected ImportReconciliation $reconciliation,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        abort_unless($request->user()->can(Permission::CyclesManage->value), 403);

        $cycle = $currentCycle->get();
        $upload = $request->session()->get('workbook_import');

        return Inertia::render('app/Import', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
            ],
            'upload' => $upload,
            'preview' => $upload === null || $cycle === null
                ? null
                : $this->preview($cycle, $upload['path']),
        ]);
    }

    /** Step one: take the file and hold it for the dry run. */
    public function store(UploadWorkbookRequest $request): RedirectResponse
    {
        $file = $request->file('workbook');
        $path = 'imports/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        Storage::disk('local')->put($path, $file->get());

        $request->session()->put('workbook_import', [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Workbook uploaded. Check the dry run below before importing.');
    }

    /** Step two: post everything the dry run listed. */
    public function import(Request $request, CurrentCycle $currentCycle): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::CyclesManage->value), 403);

        $upload = $request->session()->get('workbook_import');

        if ($upload === null) {
            return back()->withErrors(['workbook' => 'Upload a workbook before importing.']);
        }

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['workbook' => 'Your login is not linked to a member record.']);
        }

        try {
            $result = $this->importer->import(
                $currentCycle->getOrFail(),
                Storage::disk('local')->path($upload['path']),
                $actor->load('user'),
            );
        } catch (Throwable $exception) {
            return back()->withErrors(['workbook' => $exception->getMessage()]);
        }

        Storage::disk('local')->delete($upload['path']);
        $request->session()->forget('workbook_import');

        $message = "{$result['posted']} entr(ies) imported, {$result['skipped']} already present.";

        if ($result['failed'] !== []) {
            return back()->with('success', $message)->withErrors([
                'workbook' => count($result['failed']).' entr(ies) could not be posted: '
                    .implode('; ', array_map(
                        fn (array $row): string => "{$row['member']} — {$row['reason']}",
                        $result['failed'],
                    )),
            ]);
        }

        return back()->with('success', $message);
    }

    /** Throws away the held file without importing it. */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::CyclesManage->value), 403);

        $upload = $request->session()->pull('workbook_import');

        if ($upload !== null) {
            Storage::disk('local')->delete($upload['path']);
        }

        return back()->with('success', 'Upload discarded.');
    }

    /**
     * The dry run: what would be posted, what is already there, and how it ties out.
     *
     * @return array<string, mixed>
     */
    protected function preview(Cycle $cycle, string $path): array
    {
        try {
            $plan = $this->importer->plan($cycle, Storage::disk('local')->path($path));
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }

        return [
            ...$plan,
            'reconciliation' => $this->reconciliation->for($cycle, $plan['workbook_totals']),
        ];
    }
}
