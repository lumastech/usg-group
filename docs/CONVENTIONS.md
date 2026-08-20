# Conventions

How the Unity Savings Group system is built. Every module follows these; where a
module spec conflicts with something here, raise it rather than quietly diverging.

---

## 1. Money

**All money is an integer of ngwee.** K1 = 100 ngwee. There are no floats anywhere
in the money path — not in the database, not on the wire, not in JavaScript.

| Layer | Representation |
| --- | --- |
| Database | `BIGINT`, column suffixed `_ngwee` |
| Eloquent | `App\Casts\MoneyCast` → `Brick\Money\Money` |
| Inertia props | plain integer ngwee |
| Vue | `formatMoney(ngwee)` → `"K1,500.00"` |

- Construct with `App\Support\Kwacha`: `Kwacha::of(500)` (Kwacha) or
  `Kwacha::ofNgwee(50_000)`. Never `new Money`.
- Server-side formatting only for PDFs and Excel exports. Everything the browser
  renders is formatted in Vue, so the client controls presentation.
- **Model defaults must be mirrored into `protected $attributes`** as raw ints, not
  left only in the migration. Eloquent does not know database defaults, so a freshly
  created model reads `null` until `refresh()`. `Cycle` hit this with
  `joining_fee_ngwee`. See `.ai/rules/models.md`.

```php
// Reading
$member->joining_fee_ngwee;                    // Brick\Money\Money
Kwacha::toNgwee($member->joining_fee_ngwee);   // int, for Inertia

// Sending to the frontend
'amount_ngwee' => $transaction->amount_ngwee->getMinorAmount()->toInt(),
```

```ts
// Rendering
import { formatMoney } from '@/lib/money';
formatMoney(150_000); // "K1,500.00"
```

Use `<MoneyText :ngwee="…" />` rather than calling `formatMoney` in a template — it
applies tabular figures, which is what keeps columns of money aligned.

---

## 2. Domain services

**Every financial mutation goes through a domain service in `app/Domain/{Module}/`.**
Business rules never live in a controller, a model, or a Vue component.

A domain service:

1. Runs inside a `DB::transaction()`.
2. Validates the business rule itself and throws a `DomainRuleException` subclass —
   the rule lives in the service, not in a Form Request.
3. Writes an activity-log entry.
4. Returns the created record(s).

Controllers do three things only: authorise, validate shape (not rules), and hand
off to the service.

```php
public function store(RecordSavingsRequest $request, SavingsLedger $ledger): RedirectResponse
{
    $this->authorize('record', [SavingsTransaction::class, $member]);

    $ledger->record($member, $month, Kwacha::ofNgwee($request->integer('amount_ngwee')), $recorder);

    return back()->with('success', 'Savings recorded.');
}
```

### Immutable ledgers

Ledger rows are never edited or deleted. A correction is a **reversing entry** that
points at the original. This is a constitutional requirement, not a preference — the
group must be able to reconstruct any month from the ledger alone.

---

## 3. Cycles

Every record belongs to exactly one cycle.

- Models with a `cycle_id` use the `App\Models\Concerns\BelongsToCycle` trait, which
  applies `CycleScope` and adds `cycle()`, `scopeAcrossCycles()` and `scopeForCycle()`.
- `App\Domain\Cycles\CurrentCycle` (a singleton) resolves the active cycle.
- **`CycleScope` is inert until a cycle is pinned.** `SetCurrentCycle` middleware pins
  it for every web request, so user-facing queries are automatically confined to the
  current cycle. Domain services and tests that legitimately span cycles are unaffected
  because they never pin one.

```php
Member::count();                    // pinned cycle only, in a web request
Member::acrossCycles()->count();    // every cycle
Member::forCycle($other)->count();  // one specific cycle
```

---

## 4. Enums

Native PHP backed enums in `app/Enums/`, TitleCase case names, lowercase values.

They are mirrored to TypeScript by an artisan command:

```bash
php artisan unity:generate-ts-enums          # writes resources/js/types/enums.ts
php artisan unity:generate-ts-enums --check  # fails if the committed file drifted
```

`resources/js/types/enums.ts` is **generated and committed** — never edit it by hand.
Re-run the command after changing any enum.

---

## 5. Role-aware UI

This is the part most likely to be got wrong, so it is spelled out.

### 5.1 Permissions are the currency; roles are bundles

`App\Enums\Permission` holds every granular ability (`loans.approve`,
`payouts.execute`, …). `App\Enums\MemberRole::permissions()` maps each office to its
bundle, and `RoleSeeder` syncs that into spatie's tables.

**Authorisation always checks a permission, never a role.** To re-scope an office,
edit `MemberRole::permissions()` and reseed — no code that checks authorisation
should need to change.

Approval and execution are deliberately split: the chairperson approves loans
(`loans.approve`) but cannot disburse them; the treasurer disburses (`loans.disburse`)
but cannot approve. That separation is a control, so do not "helpfully" grant both.

### 5.2 The backend is the source of truth

Every route and action is guarded by a policy or by permission middleware:

```php
Route::post('loans/{loan}/approve', ApproveLoanController::class)
    ->middleware('permission:loans.approve');
```

**Frontend permission checks are UX only.** They decide what to render. They never
secure anything — a user who forges a permission in the browser still gets a 403.

### 5.3 Shared props

`HandleInertiaRequests::share()` puts these on every page:

```
auth.user      { id, name, email, member_id, member_number, roles[], permissions[] }
currentCycle   { id, name, status, dates…, is_lockdown, month: { window, … } }
flash          { success?, error?, warning?, info? }
```

`currentCycle.month.window` is one of `before_declarations | declarations | between |
trading | closed` and drives banners and form availability.

### 5.4 Frontend helpers

```ts
const { can, canAny, hasRole, isCommittee, isMember, currentCycle } = usePermissions();

can('loans.approve');
canAny(['payouts.approve', 'payouts.execute']);
```

```vue
<Can permission="loans.approve">
    <AppButton>Approve</AppButton>
    <template #fallback><p>Awaiting the chairperson.</p></template>
</Can>

<Can :any="['payouts.approve', 'payouts.execute']">…</Can>
<Can :all="['loans.approve', 'fund.approve-outflow']">…</Can>
```

### 5.5 Navigation is data-driven

`resources/js/config/navigation.ts` is the single source. Each item declares the
permissions it needs; `useNavigation()` filters it. The committee sidebar and the
member bottom-nav both render from it, so adding a section means adding one entry —
never editing a layout.

### 5.6 Two layouts, one app

| Route group | Layout | Audience |
| --- | --- | --- |
| `/app/*` | `layouts/unity/AdminLayout.vue` | Committee and admin. Dark sidebar, desktop-first, responsive. |
| `/my/*` | `layouts/unity/MemberLayout.vue` | Members. Mobile-first, bottom nav, large touch targets. |

Most of the group uses phones, so `/my` is built for a small screen first. A
committee member who is also an ordinary member switches between the two through
`PortalSwitcher` in the topbar.

### 5.7 Server-driven UI state

Pages receive an `abilities` prop **computed from real policy results**, so buttons
render from what the server would actually permit rather than from duplicated
frontend logic.

```php
return Inertia::render('app/loans/Show', [
    'loan' => $loan,
    'abilities' => [
        'approve' => $request->user()->can('approve', $loan),
        'disburse' => $request->user()->can('disburse', $loan),
        'markDefault' => $request->user()->can('markDefault', $loan),
    ],
]);
```

```vue
<AppButton v-if="abilities.approve" @click="approve">Approve</AppButton>
```

For collections, compute abilities per row and gate `DataTable`'s `#actions` slot on
them. Hide what the user cannot do rather than disabling it, unless the disabled
state itself carries information.

### 5.8 Page payloads are API resources

Anything a page renders a domain record from goes through an Eloquent API resource
in `app/Http/Resources/`, never an array assembled in the controller — so the same
record has one shape wherever it appears, and its `abilities` travel with it.

`JsonResource::withoutWrapping()` is set in `AppServiceProvider`, so a single
resource arrives as the prop itself while a paginated collection keeps its
`data`/`meta` envelope, which is what `DataTable` reads.

```php
return Inertia::render('app/members/Index', [
    'members' => MemberResource::collection($members),   // members.data + members.meta
    'member' => new MemberResource($member),             // the object itself
]);
```

Module-specific Vue partials (forms, dialogs) live in
`resources/js/components/<module>/`; only genuinely reusable pieces are promoted
into `resources/js/components/unity/`.

### 5.9 The two-person rule

Sensitive actions (disbursement, payouts, fund outflows) need a second committee
member. `<ConfirmDialog variant="dual-approval">` collects the second approver's
credentials and posts them; `App\Domain\Approvals\DualApprovalGate` verifies them
server-side and checks the second approver is a *different* user holding the right
permission. The dialog only collects the signature — it decides nothing.

---

## 6. Design system

Custom components live in **`resources/js/components/unity/`** and are the only ones
module screens should use. `resources/js/components/ui/` holds the starter kit's
shadcn-vue primitives, kept for the auth and settings screens; do not build module
UI on them.

| Component | Purpose |
| --- | --- |
| `AppButton` | Variants map to intent: `primary`, `gold`, `secondary`, `outline`, `ghost`, `destructive` |
| `AppCard` | The surface every panel sits on |
| `StatCard` | Dashboard headline figure with optional trend |
| `DataTable` | Server-side sort/filter/paginate via Inertia partial reloads; ability-gated row actions |
| `MatrixTable` | Workbook month-matrix: sticky first column, header and totals row |
| `MoneyInput` | Types Kwacha, emits ngwee, enforces step increments |
| `MoneyText` | Formats ngwee with tabular figures |
| `Modal` / `ConfirmDialog` | Overlays; `ConfirmDialog` carries the dual-approval variant |
| `FormField` / `TextInput` | Labels, hints and **server-side** validation errors |
| `StatusBadge` | Enum-driven colour, so a status reads the same everywhere |
| `Stepper`, `EmptyState`, `Toast`, `Can` | Wizards, empty lists, flash messages, permission gating |

Live gallery: **`/app/styleguide`**. It renders the real components, so it doubles as
a smoke test.

### Palette

Deep green carries actions and identity; warm gold marks value and the single most
important figure on a screen. The sidebar is dark in both light and dark mode — it is
the app's fixed anchor. Tokens are CSS custom properties in `resources/css/app.css`
(`--brand-*`, `--gold-*`); use the Tailwind utilities (`bg-brand-700`, `text-gold-400`)
rather than raw values.

Money and any figure that stacks in a column uses the `.tabular` utility.

### Validation errors

Always come from the server. The client never decides a value is invalid — what the
user sees must match what was actually rejected. Pass `form.errors.field` into
`FormField`'s `error` prop.

---

## 7. Testing

- **Pest** for backend. Feature tests by default; unit tests for pure calculation
  (`WeekendAdjustmentPolicy`, `InterestPoolAllocator`).
- **Vitest + @vue/test-utils** for components: `npm test`.
- Every change ships with a test. Run the narrowest useful selection:

```bash
php artisan test --compact --filter=SavingsLedger
npm test
```

Money assertions use exact ngwee integers, never formatted strings, so a rounding
change fails loudly.

### Before finishing

```bash
vendor/bin/pint --dirty     # PHP formatting
vendor/bin/phpstan analyse  # level 7
npm run types:check         # vue-tsc
php artisan test --compact
npm test
```

---

## 8. Project rules

`.ai/rules/` holds settled decisions and non-obvious traps, mapped to file globs by
`.ai/rules/index.md`. Read the rules matching any path you are about to touch, and
record new durable ones with the Boost `record-rule` tool rather than in a comment.
