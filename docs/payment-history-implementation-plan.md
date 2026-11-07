# General Payment History Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans`. Use checkbox (`- [x]`) tracking. Both skills were verified in the session catalog.

**Goal:** Let an admin review every recorded payment attempt across all suppliers in one searchable, filterable page.
**Architecture:** Add an authenticated read-only view over existing payments; preserve checkout, webhook verification, payment transitions, orders, and supplier history.
**Tech Stack:** Laravel 11, PHP 8.3, MariaDB, Inertia v1, React 18, Tailwind `wb-*` components, Vite, PHPUnit. Sources: `composer.lock`, `package.json`, `config/database.php`, `resources/js/Components/admin/ui.jsx:1`.

**Execution completed locally — 2026-11-08, Asia/Manila.** Tasks 1–4 implemented and independently reviewed. Regression, browser smoke, production build, and no-Terminal package verification passed locally. Hosted schema/grants, Linux/cPanel execution, and production smoke remain pending rollout gates. The original planning audit below is retained as historical evidence; execution results are recorded at the end.

---

## UAT source and open questions

Source: owner conversation, 2026-11-08, requesting a general payment history and then an implementation plan.

- **Context:** Admin needs a general payment history for campaign operations.
- **Current Behavior:** Attempts are accessible through individual supplier details; no central attempts route/navigation exists (`app/Http/Controllers/Admin/SupplierController.php:73`, `resources/js/Pages/Admin/SupplierShow.jsx:87`, `routes/web.php:64`, `resources/js/Components/admin/ui.jsx:11`).
- **Issue:** Reviewing outcomes requires opening each supplier separately.
- **Expected Result:** Payment History lists attempts across suppliers with current status, supplier/business, amount, request reference, dates, filters, pagination, and links to supplier details.

No missing decision prevents planning this additive reader. Proposed scope follows the central payment-attempt page discussed with the owner. **History means one row per recorded attempt with its current status, not a permanent journal of every status transition.** An event journal, payment editing, refunds, export, and reconciliation are separate enhancements, excluded here.

## Root cause, approach, and audit findings

1. **Missing general reader, not missing storage:** supplier details already project stored payments; navigation and protected routes do not include a payment index (`app/Http/Controllers/Admin/SupplierController.php:73`, `resources/js/Components/admin/ui.jsx:11`, `routes/web.php:64`). Add one reader rather than another payment source.
2. **Canonical status:** payment enum values are `pending`, `completed`, `failed`, `expired`; amount is decimal(10,2); request ID is unique (`database/migrations/2026_11_06_000005_create_payments_table.php:11`). Existing UI labels are Pending, Paid, Failed, Link expired (`resources/js/lib/admin.js:25`). There is no separate processing status.
3. **Creation writer:** checkout reuses a live pending attempt, otherwise expires previous pending attempts and creates a row after HitPay returns successfully (`app/Services/PaymentService.php:55`). A creation exception returns 502 without a new payment row (`app/Http/Controllers/PaymentController.php:19`). Such failures cannot be reconstructed as attempts by this page.
4. **Update writers:** completed webhook updates payment before deciding whether an order can be applied (`app/Services/PaymentService.php:152`); failed notifications affect only pending rows (`app/Services/PaymentService.php:192`); expiry updates stale pending rows (`app/Services/PaymentService.php:213`). Duplicated completion is ignored (`app/Services/PaymentService.php:126`). Do not alter these guards.
5. **All entry paths:** supplier checkout, verified webhook, and dev simulation call existing services (`app/Http/Controllers/PaymentController.php:15`, `app/Http/Controllers/HitPayWebhookController.php:13`, `app/Http/Controllers/DevController.php:38`). Scheduler calls expiry through `invites:process` (`routes/console.php:6`, `app/Console/Commands/ProcessInvites.php:15`). A table-based reader automatically covers existing and future rows from these paths.
6. **Existing readers:** supplier summaries/details, Overview, and supplier CSV consume payments (`app/Support/AdminPresenter.php:34`, `app/Http/Controllers/Admin/DashboardController.php:28`, `app/Http/Controllers/Admin/SupplierController.php:94`). Their projections and totals remain unchanged.
7. **Authorization:** existing middleware authenticates application users, expires idle sessions, and returns private/no-store/noindex headers; it does not implement a distinct staff-role hierarchy (`app/Http/Middleware/EnsureAdmin.php:16`). Use that established boundary, not browser roles or tenant identifiers. Prefix/page size are configured (`config/admin.php:5`, `config/admin.php:14`).
8. **Data limits:** nullable paid_at/webhook payload exist, but there is no failed-at or event table in the payment migration. Orders reference a supplier, not a particular payment (`database/migrations/2026_11_06_000006_create_orders_table.php:13`). Do not infer per-attempt refund eligibility or order attribution. Existing refund warnings stay in existing views.
9. **Simulation identification:** generated dev request IDs begin `dev_` (`app/Services/HitPayService.php:19`). Label those Simulated. Do not label every other row Live: sandbox/live provenance is not persisted in the payment schema.

## Live database/system validation — 2026-11-08

Read-only SELECT/SHOW inspection ran against **local `wedworks_chatbot`**, not cPanel. No records changed. No tests, migration, seeding, scheduler, or provider HTTP requests ran during planning.

| Read-only local check | Result |
| --- | --- |
| Suppliers / payments | 1 / 1 |
| Payment statuses | completed: 1 |
| Request IDs beginning dev_ | 1 |
| Null paid_at / null webhook_payload | 0 / 0 |
| Orphan payment rows | 0 |
| Completed payments without supplier order | 0 |
| Triggers on payments | None |
| Keys | PRIMARY(id), UNIQUE(hitpay_request_id), INDEX(supplier_id,status) |
| Foreign key | supplier_id → suppliers; ON DELETE CASCADE |
| JSON constraint | json_valid(webhook_payload) |

Evidence: safe aggregate queries plus `SHOW COLUMNS FROM payments`, `SHOW INDEX FROM payments`, and `information_schema.TRIGGERS`, `REFERENTIAL_CONSTRAINTS`, `CHECK_CONSTRAINTS`, executed in this planning session. No personal rows or secrets retained. Local nullable columns: paid_at, webhook_payload, created_at, updated_at. Other payment fields are non-null. Enum, precision, keys and field sizes match the migration. One simulated local row does not prove real HitPay behavior.

**Supabase/RLS:** Not applicable: this app uses Laravel's MySQL connection (`config/database.php`, `phpunit.xml:26`), not a Supabase client. MariaDB constraints and application middleware apply; no Supabase policies proposed. Production DB grants are **UNVERIFIED**.

**cPanel validation: UNVERIFIED.** Before rollout, inspect the hosted payments schema, nullability, keys, FK, triggers, status counts, and orphan count read-only in phpMyAdmin. Do not transfer local row-count assumptions. Investigate divergence before rollout; no migration is proposed. Remote provider behavior and hosting PHP version also remain UNVERIFIED until deployment checks.

## Scope and constraints

### In scope

- Payment History navigation and protected read-only page; one row per attempt, including multiple attempts per supplier.
- Search supplier name/business/email and request ID; status filter; inclusive start-date range in Asia/Manila.
- Existing configured page size (25), newest started_at first, descending ID tie-break; result count, Clear, Refresh.
- Columns: attempt ID, supplier/business/details link, PHP amount with two decimals, status, started/paid/expiry timestamps, request ID; late/simulated badges.
- Mobile table scrolling, accessible labels, validation/loading/empty states.
- Tests, owner documentation, rebuilt assets and refreshed production-vendor cPanel ZIP. Owner has no cPanel Terminal (decision from conversation, 2026-11-08).

### Out of scope — do not change

- Schema/indexes/migrations/backfills, imports, payment or supplier writes.
- Immutable event journal, provider processing substates, exact failure reasons, rejected webhook browser, request-failure journal.
- Refund actions/statuses, manual payment overrides, reconciliation, financial totals, CSV export, auto-polling.
- Existing supplier views/CSV, Overview, emails, grace rules, payment guards, scheduler, Hermes, production DNS/credentials.

### Non-negotiable safety constraints

- Server middleware protects direct route access. Never rely on hidden client navigation.
- Parameter-bind filters; group OR search clauses inside status/date predicates; escape literal `%`, `_`, backslash as existing supplier search does (`app/Http/Controllers/Admin/SupplierController.php:169`).
- Explicit projection only: no whole-model serialization, checkout URLs, invite tokens, raw webhook payloads, credentials, mobile numbers, or unrelated answers.
- GET does not expire links, send emails, call providers or mutate business records. Existing session idle bookkeeping is retained.
- Keep server amounts decimal strings and format two decimals in this new page without altering shared existing formatting.
- Persisted status remains authoritative. Overdue pending rows stay Pending, with informational “Awaiting expiry update” badge; the reader does not mark them expired.
- Existing supplier deletion cascades payment deletion; this feature cannot recover deleted attempts or promise immutable retention.

### Payment history contract

**Proposed new route:** `GET /{configured-admin-prefix}/payments`, name `admin.payments`, component `Admin/Payments`. New identifiers below are additions, not existing-code claims.

| Case | Acceptance |
| --- | --- |
| Authenticated admin | All stored attempts; started_at DESC, id DESC; config page size |
| Four statuses | Pending/Paid/Failed/Link expired directly from payment.status, not supplier.status |
| Retry or failed→completed | Separate IDs remain separate; same ID shows latest state once; no invented prior events |
| Search | Trimmed partial text on four fields; grouped/escaped conditions; no ownership scope from browser |
| Dates | Filter started_at, from midnight inclusive to day after end exclusive, configured Manila timezone |
| Empty fields | Omit predicates; Clear resets page 1 |
| Invalid input | Reject unknown status, q >160 chars, arrays/objects, malformed/impossible dates, reversed range, nonpositive/noninteger page; JSON 422 or normal Inertia validation errors |
| Guest/invite-only session | Admin login redirect; JSON 401; no payment props |
| Authenticated User | Same existing admin identity boundary; no invented non-admin User role |
| Direct non-staff DB writes | No new DB access or write endpoint; existing server writers/DB permissions unchanged; remote grants UNVERIFIED |
| Historic nullable fields | Null paid_at shows dash; webhook payload unused; no row excluded because paid_at null |
| Simulation | dev_ prefix badge; non-dev rows not guaranteed live |
| Late completed/no order | Show stored Paid and late flag; existing supplier details handle review; no automatic refund inference |
| Overdue pending | Show Pending and informational badge based on server time; no mutation |
| All creation paths | Existing table reader includes real/sandbox/dev attempts without writer hooks |
| Provider creation error/rejected webhook | No fabricated attempt; technical logs out of scope |
| Empty/out-of-range pagination | Empty state; page past end redirects to last page preserving filters, or page 1 for zero results |
| Unexpected missing supplier | Preserve payment in reader, nullable supplier fallback and no link; investigate integrity separately |

Proposed Inertia payload:

```text
payments: {data, total, current_page, last_page, from, to}
filters: {q, status, date_from, date_to}
options.statuses: [{value, label}] for the four allowlisted payment statuses
server_now: ISO-8601
payments.data[]: {
  id, supplier: {id, name, business_name} | null, request_id,
  amount: decimal-string, status, started_at: ISO-8601,
  paid_at: ISO-8601 | null, expires_at: ISO-8601,
  late: boolean, simulated: boolean, awaiting_expiry_update: boolean
}
```

Pagination from/to are row positions; date_from/date_to are start-date filters.

## Files

Complete planned change set. Referenced writer/auth/schema files are audit evidence, not edit targets.

| File | Responsibility |
| --- | --- |
| `tests/Feature/AdminPaymentHistoryTest.php` — new | Access/query/projection/read-only/integration tests |
| `tests/Unit/.gitignore` — new | Preserve empty configured Unit test directory for fresh checkouts |
| `app/Http/Controllers/Admin/PaymentHistoryController.php` — new | Validated filters, read query, pagination, mapping |
| `routes/web.php` | Protected GET route |
| `resources/js/Pages/Admin/Payments.jsx` — new | Central UI, filters, refresh, links |
| `resources/js/Components/admin/ui.jsx` | Navigation entry |
| `docs/ADMIN.md` | Owner guide and limitations |
| `docs/payment-history-implementation-plan.md` | This plan and later execution evidence |
| `public/build/manifest.json` | Production entrypoint manifest |
| `public/build/assets/` | Generated hashed bundles; filenames assigned by Vite |
| `deployment/wedworks-cpanel.zip` | Refreshed production package including vendor |
| `deployment/README.md` | No-Terminal update instructions |

Tables: payments and suppliers read only. No new API write endpoints, policies, RPCs, jobs, triggers or migrations.

## Phase 0 — Baseline tests

### Task 1: Write failing tests first

File: proposed test class only. Fixtures follow `tests/Feature/AdminTest.php:29`; executed test directory verified in `phpunit.xml`.

- [x] Confirm local config is not cached. Keep `tests/TestCase.php:16` database guard; tests must use `wedworks_chatbot_test`. Never run tests against cPanel or development DB.
- [x] Baseline command: `php artisan test --filter='AdminTest|PaymentTest|DevModeTest'`. Baseline passed: 106 tests / 1,058 assertions; see execution evidence below.
- [x] Create this literal starter test, then run red verification:

```php
<?php
namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        $this->travelTo(now()->startOfMinute());
    }

    private function attempt(string $status): Payment
    {
        $n = ++$this->sequence;
        $supplier = app(SlotService::class)->register([
            'name' => "Person $n", 'email' => "person$n@example.com",
            'mobile' => '+6391800'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Business $n", 'business_type' => 'caterer',
            'location' => 'Tagaytay',
        ])['supplier'];
        return Payment::create([
            'supplier_id' => $supplier->id, 'hitpay_request_id' => "req_test_$n",
            'amount' => '10000.50', 'checkout_url' => "https://checkout.example/pay/$n",
            'status' => $status, 'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'paid_at' => $status === 'completed' ? now() : null,
            'webhook_payload' => null,
        ]);
    }

    public function test_guests_cannot_read_payment_history(): void
    {
        $this->get('/admin/payments')->assertRedirect('/admin/login');
        $this->getJson('/admin/payments')->assertUnauthorized();
    }

    public function test_all_attempts_use_safe_projection_and_stable_order(): void
    {
        $old = $this->attempt('failed');
        $new = $this->attempt('completed');
        $this->actingAs(User::factory()->create())->get('/admin/payments')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($p) => $p->component('Admin/Payments')
                ->where('payments.total', 2)
                ->where('payments.data.0.id', $new->id)
                ->where('payments.data.0.amount', '10000.50')
                ->where('payments.data.0.status', 'completed')
                ->where('payments.data.1.id', $old->id)
                ->where('payments.data.1.paid_at', null)
                ->missing('payments.data.0.checkout_url')
                ->missing('payments.data.0.webhook_payload')
                ->missing('payments.data.0.supplier.invite_token'));
    }

    public function test_status_filter_and_validation(): void
    {
        $this->attempt('failed');
        $paid = $this->attempt('completed');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/payments?status=completed')->assertInertia(fn ($p) => $p
            ->where('payments.total', 1)->where('payments.data.0.id', $paid->id));
        $this->getJson('/admin/payments?status=processing')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
```

Command: `php artisan test --filter=AdminPaymentHistoryTest`. Expected **FAIL** before implementation: missing route returns 404 instead of redirect/200/422. Resolve fixture failures first. Do not suppress Inertia component-existence checks; they remain red until Phase 2 adds the page.

- [x] Add tests before production implementation for all four statuses; same-supplier retries; grouped q+status+dates; each search field; escaped literal wildcards; oversized q; array query values; impossible/reversed dates; midnight bounds; one-sided ranges; invalid page; >25 rows/tied starts; empty and beyond-end page; null dates/payload; simulation/late badges; unchanged overdue pending; private-field absence across props.
- [x] Read-only test: snapshot payment arrays, supplier status and Order/StatusHistory/EmailLog/AuditLog counts after fixtures; multiple GET/filter/refresh requests must not change them. Enable `Http::preventStrayRequests()` after fixture setup. Normal session housekeeping is allowed.
- [x] Test invite-only guest access and idle-session expiry. No separate non-admin User role exists in this middleware; do not invent one.
- [x] Add integration tests using valid fixture/service paths from `tests/Feature/PaymentTest.php:42`: pending creation with fake HTTP, handler completion/failure, expiry, dev IDs, repeated webhook, Refresh seeing latest state without duplicate rows or new side effects.

Constraints: test DB only, synthetic identities, fake HTTP/mail, no live keys or payment calls, no production fixture writes.

## Phase 1 — Authorized read endpoint

### Task 2: Add route and controller

Files: proposed controller/test, `routes/web.php`.

- [x] Import new controller and add inside existing `admin` middleware group:

```php
Route::get('/payments', [PaymentHistoryController::class, 'index'])->name('payments');
```

- [x] Validate query scalars before casting: q nullable string max:160; status exact allowlist; dates strict Y-m-d and end>=start when both present; page integer min:1. Missing fields normalize to empty filter strings; keep only defined filters.
- [x] Query Payment, eager-load `supplier:id,name,business_name`, group request-ID OR supplier search inside date/status predicates; bind escaped text. No inner join that drops unmatched relations or join to orders that duplicates rows.
- [x] Parse start-date boundaries in `config('app.timezone')`. Existing writer stores app-time now (`app/Services/PaymentService.php:76`); verify date boundary tests before assuming UTC conversion. Apply these shapes conditionally using separate Carbon instances:

```php
$query->where('started_at', '>=', $from->startOfDay()->format('Y-m-d H:i:s'));
$query->where('started_at', '<', $to->startOfDay()->addDay()->format('Y-m-d H:i:s'));
$query->orderByDesc('started_at')->orderByDesc('id');
$page = $query->paginate(config('admin.per_page'))->withQueryString();
```

- [x] Explicitly map contract props; `(string) $payment->amount`, ISO timestamps, nullable paid_at/supplier, dev_ prefix flag, pending expiry flag using captured server_now. No raw models or payload.
- [x] Redirect beyond-end pages to last valid page with normalized filters; avoid loops for empty results.
- [x] No provider/service calls or business mutations. Preserve middleware/session behavior.

Commands: `php -l app/Http/Controllers/Admin/PaymentHistoryController.php`; `php artisan route:list --name=admin.payments`; `php artisan test --filter=AdminPaymentHistoryTest`. Expect valid syntax/new protected route; backend assertions green after Phase 2 supplies component. Missing-component failures remain explicit until then.

Manual check after Phase 2: guest/admin access and response headers in browser Network panel. Constraints: no schema/writer changes, no auth bypass, no visibility scoped by browser identity.

## Phase 2 — Admin UI and documentation

### Task 3: Add Payment History page/navigation

Files: proposed page/test, admin UI, `docs/ADMIN.md`.

- [x] Add `['/payments', 'Payment History']` navigation entry; AdminLayout title Payment History/current `/payments`.
- [x] Reuse established Head/Link/router/usePage, TextInput, Select, Table/Th/Td/Pager, PAYMENT_PILL and fmtDate conventions (`resources/js/Pages/Admin/Suppliers.jsx:1`, `resources/js/Components/admin/ui.jsx:136`). Derive admin base from shared props, not hardcoded /admin.
- [x] Search, status, labelled start-date inputs, Apply/Clear/Refresh. Apply/Clear reset pagination. Refresh/Pager use applied filters, not unsubmitted edits. Restore form state on Back/Forward props changes; show field validation errors and preserve editable invalid values.
- [x] Loading disables repeated submission with accessible feedback; no auto-polling or per-keystroke provider requests.
- [x] Render exact contract columns; supplier details link under configured prefix, nullable fallback without link. Format amount locally via Intl.NumberFormat en-PH/currency PHP/minimumFractionDigits:2/maximumFractionDigits:2; leave shared peso helper unchanged.
- [x] Show result count, empty state, accessible badges, horizontal mobile scroll and existing Pager metadata shape.
- [x] Help text explains current-status attempts, Pending awaiting confirmation, omitted pre-creation errors, simulation versus unknown live/sandbox provenance, and Refresh reloading stored data only.
- [x] Document location, fields, filters, Manila start-date basis, nullable dates, badges, pending/expiry distinction, existing supplier drill-down, no full event journal/provider error browser/refund controls.

Commands: `php artisan test --filter=AdminPaymentHistoryTest`; `npm run build`. Expect focused tests green/Vite exit 0. No npm lint script exists (`package.json`); do not claim one ran. Changed-PHP syntax check above is the applicable lint check.

Manual checks: smoke table below. Constraints: no write UI, no raw payload/secrets, no changes to existing summaries, no dependency added.

## Phase 3 — Regression verification and rollout

### Task 4: Verify and rebuild the no-Terminal release

Files: tests/docs above, this plan, production manifest/assets, deployment ZIP/README.

- [x] Run `php artisan test --filter='AdminPaymentHistoryTest|AdminTest|PaymentTest|DevModeTest'`, then `php artisan test`; record actual counts/results. Handoff's 282 passed is historical, not current verification. Preserve database guard.
- [x] Repeat new-controller PHP syntax check and `npm run build`; resolve all manifest asset paths. Do not run local config:cache.
- [x] Run `git diff --check`; attribute pre-existing whitespace separately. Review props/artifacts for secrets; .env remains ignored.
- [x] Prepare an empty release directory outside repo from tracked application files. Exclude .git/tests/docs/tools/deployment archives/node_modules/current vendor, .env, uploads/logs/session/view/config/route caches and public/hot/storage symlink. Include hidden .htaccess, env example, new code/assets and Laravel storage directory placeholders.
- [x] In staging run `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist`; do not replace development vendor. Include resulting production vendor/package discovery data, not machine-specific Laravel caches.
- [x] Regenerate deployment/wedworks-cpanel.zip with hidden files/relative forward-slash entries/storage directories. Avoid recursive archive inclusion. Update deployment README with no-Terminal update steps preserving server .env/storage.
- [x] Verify ZIP integrity and existence of new controller/page/routes/nav, vendor/autoload.php, public/.htaccess, manifest and referenced bundles. Assert absence of .env/public/hot/.git/local data/test-only dependencies/config caches. Verify staged package discovery; check local production lock/platform requirements. Selected cPanel PHP and Linux execution remain UNVERIFIED until hosted checks.

Actual local execution results are recorded below. The package includes production dependencies and compiled assets for an update without server npm/Composer. Remote deployment has not occurred.

| Smoke check | Expected |
| --- | --- |
| Isolated local data: four statuses/retries | Every attempt once; correct labels and supplier link |
| Search+status+date; literal wildcard text | Correct predicate intersection |
| Manila midnight and centavos | Inclusive boundaries and two decimals |
| >25 rows/equal start times | Stable order and paging/filter retention |
| Clear/Refresh/Back/Forward/invalid form | Consistent state and recoverable errors |
| Mobile and keyboard | Usable scrollable table and labels |
| Guest/invite-only/idle session | No payment disclosure |
| Network props | No checkout/invite URL, raw payload or secrets |
| Simulation/null paid_at/late/overdue pending | Truthful flags/dashes; no mutations |
| Fake future writer update | Refresh shows latest stored state with unchanged side-effect counts |
| Existing supplier views/Overview | Unchanged histories and totals |
| Linux/PHP production-like extraction | Autoload, routing, manifest, permissions/auth work |
| cPanel existing rows | Match read-only phpMyAdmin counts/statuses |

### Deployment order and production gates

1. Read-only phpMyAdmin schema/count validation above; confirm existing admin sign-in, correct app key, HTTPS, server PHP and writable storage. Unrelated hosting corrections are separate work.
2. Back up files/database; retain server .env and storage/uploads/sessions. Never replace production database with local data.
3. Upload/extract verified ZIP in staging outside the web document root. Keep domain pointed at public; do not expose .git or ZIP archives.
4. Release matching controller/route/page/nav/manifest/assets together in a maintenance window. Git deployment alone must not assume Composer exists; production vendor comes from package. Retain old hashed assets until open sessions can reload.
5. If server caches exist, ask host to clear them or use a controlled one-time cPanel cron running verified `php artisan optimize:clear` in the actual app directory with private log; verify success/remove temporary job. Never upload Windows config caches. No migration or seed is required by this feature.
6. Smoke-test signed-out and signed-in page/filters/details against existing hosted rows only; compare before/after aggregate counts/statuses read-only. No live charge needed.
7. Preserve existing scheduler: expiry is maintained there, not by the page. Missing cron is a separate operational issue.
8. Record release commit/package checksum and smoke results. Coordinator handles authorized Git integration/push separately; hosted deployment is pending.

## Rollback

1. Restore preceding application release with matching manifest/assets/vendor while retaining server .env and storage. Clear applicable route/view caches with same hosting procedure.
2. Revert route/controller/page/nav implementation together; no dangling navigation link.
3. Retain all payment/supplier/order records. No SQL rollback, reseeding or data deletion: feature is read only.
4. Any later schema correction requires separately inspected forward corrective migration; never edit applied migrations. None is needed by this plan.

## Proposed implementation commits

Implementation changes are prepared; coordinator handles commits/integration. Include the setup-only `tests/Unit/.gitignore` so a clean checkout retains the empty directory referenced by phpunit.xml.

1. `feat: add protected read-only admin payment history` — new test/controller/page, routes, admin navigation. Minimal page ships with route so component checks pass.
2. `docs: document payment history behavior and rollout` — ADMIN guide, deployment README, execution results in this plan.
3. `build: refresh production assets and cPanel payment history package` — manifest, generated assets, verified ZIP.

## Self-review

| Adversarial check | Result |
| --- | --- |
| Scope/non-goals/expected-result consistency | PASS: central attempts list, no claim of immutable events or every provider substate |
| Existing and future writer coverage | PASS: canonical table reader, no backfill; all audited creation paths; pre-row errors explicitly excluded |
| Direct route/job/DB bypass | PASS for design: existing middleware, no write route/job/privilege added; remote DB grants UNVERIFIED/unchanged |
| Exact identifiers | PASS: existing references inspected; new route/controller/page/test/props labelled proposed; future hashes generated |
| File/phase/commit consistency | PASS: complete change table maps to Tasks 1–4 and three commits; audit references not implementation targets |
| Competing source of truth | PASS: payment.status canonical; supplier/order state not used as attempt status or payment attribution |
| No placeholders/vague locations | PASS: explicit contracts/paths/test code; synthetic test fixtures; generated asset names intentionally not guessed |
| Live data and limitations | PASS: local schema/aggregate evidence separated from unverified hosted data; no real-payment claim |
| Tests/build/lint/rollback | PASS locally: fresh results below; hosted rollout gates remain pending |
| Execution scope | PASS: additive read-only implementation, docs, tests and release assets; no schema/writer changes or hosted data mutation |

Covered: audited source writers/readers, API/auth, local schema/nullability/keys/triggers, aggregate data, tests, UI conventions, production artifacts and cPanel rollout. Excluded: immutable event capture, refund management and production access. No anomaly described as legacy data without evidence.


## Execution evidence — 2026-11-08, Asia/Manila

All implementation tasks above are checked for local completion. Hosted verification is explicitly pending below. Tasks 1, 2 and 3 each passed independent specification and code-quality reviews; independent whole-feature source review found no issues. Task 4 release verification is recorded here for coordinator review.

### Test safety and regression

- Tests used only guarded `wedworks_chatbot_test` with synthetic identities and fake mail/provider calls. `tests/TestCase.php` still rejects any other database. No developer or hosted business data was changed. No local config cache was generated.
- Fresh-checkout setup issue: phpunit.xml references empty `tests/Unit`; Git did not preserve it. Added only `tests/Unit/.gitignore` (`*` and `!.gitignore`) so future clean checkouts can run the suite. No app/config/database change was needed.
- Pre-implementation baseline: **106 passed / 1,058 assertions**. Red verification: **12 failing new tests** against missing route (404); fixture issues resolved before implementation.
- Final required focused command: `php artisan test --filter='AdminPaymentHistoryTest|AdminTest|PaymentTest|DevModeTest'` — **118 passed / 1,263 assertions**, exit 0, 12.62s. Regex also matches other test classes containing Admin; this explains the count.
- New class: `php artisan test --filter=AdminPaymentHistoryTest` — **12 passed / 205 assertions**, exit 0.
- Invalid intermediate full run: accidentally overlapping the new-class run with the full suite reset the shared guarded test database, producing missing-table/table-definition errors (**7 failed, 287 passed / 2,785 assertions**). This is a test orchestration error, not accepted verification; no application or test code was weakened. All test processes ended before the final standalone rerun.
- Coordinator independent full-suite verification: **294 passed / 2,853 assertions**, exit 0, **38.95s**, run alone.
- Accepted final full command: `php artisan test` — **294 passed / 2,853 assertions**, exit 0, **39.56s**, run alone. Existing RegistrationTest doc-comment metadata deprecation warning remains; PHPUnit 12 will require attributes. No failures remain in the accepted run.
- `php -l app/Http/Controllers/Admin/PaymentHistoryController.php` and `php -l routes/web.php`: no syntax errors. `git diff --check`: exit 0.

### Browser and build

Coordinator smoke used the guarded TEST database, 27 synthetic attempts, and Ego desktop plus 390px mobile. Verified page 2 has 2 rows; combined search/status has 7 rows; Refresh ignores unsaved search and retains page 2; reversed dates show server validation while preserving entered values; Clear resets; Back restores applied search/status; currency has two decimals; supplier detail links work; guest route redirects to login; all four status badges plus late/simulated/expiry badges render. Mobile document width remains 390px with a horizontally scrollable table. Standard DOM input/change dispatch was needed for native Ego date fill to notify React (automation limitation). Temporary local smoke server stopped and ignored `.env` restored. No hosted data or provider charges.

`npm run build`: Vite 6.4.5, **162 modules**, exit 0, 2.42s. Manifest 0.20 kB; `app-4_74B2nk.css` 25.60 kB (gzip 6.43 kB); `app-DbI6ewpo.js` 403.85 kB (gzip 128.32 kB). No npm lint script exists; PHP syntax checks are recorded above.

### No-Terminal production package

Fresh empty external stage: `C:/Users/Rovick/AppData/Local/Temp/wedworks-release-2vzr9kmz`. Copied tracked existing files plus intended new application source/build files; omitted removed hashes, tests/docs/tools/deployment/node_modules/development vendor, `.env`, runtime files, uploaded data, public hot/storage link and machine-specific caches. Included hidden examples, `public/.htaccess` and storage placeholders.

- Staging-only `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist`: exit 0, **77 production installs**, optimized autoload and package discovery successful. Development vendor unchanged.
- Fresh staged PHP subprocess `php artisan route:list --name=admin.payments --no-ansi`: exit 0, one `GET|HEAD admin/payments` route to `Admin\PaymentHistoryController@index`.
- `composer check-platform-reqs --no-dev`: all pass locally on Windows PHP **8.3.29**. Inspected production lock PHP constraints; root/framework dependencies require at least PHP **8.2**. Selected cPanel PHP/extensions and Linux execution remain **UNVERIFIED**.
- `deployment/wedworks-cpanel.zip`: **8,055,594 bytes; 6,327 files; 7,243 entries including directories**.
- SHA-256: `eb6baffb7076d6b577beb324ff2bb4abb789019f2f57f683a4a7c7e46098a6f8`.
- ZIP permissions verified after metadata normalization: Unix create_system 3, regular files 0644, directories 0755 plus DOS directory flags. Exact content SHA-256 parity for every entry and timestamps preserved; archive CRC and manifest/exclusions rechecked.
- ZIP CRC test passes. Verified relative forward-slash entries; new controller/page/routes/navigation, production autoload, package/service discovery, hidden files, manifest and exact referenced assets present. No stale build hashes; no dev-only packages, `.env`, nested ZIP, local database/log/uploads/session/view/config/route cache, tests, or Git metadata. All storage files are directory placeholders.
- Deployment README now describes no-Terminal update, preserving hosted `.env`/storage, matching manifest/assets, host-assisted or controlled one-time cron cache clearing, and rollback. No migration or seed required for this feature.

### Remaining rollout gates (not locally completable)

- [ ] Read-only hosted schema/nullability/keys/FK/triggers/status/orphan validation and DB grants inspection.
- [ ] Confirm selected cPanel PHP/extensions satisfy production lock, Linux extraction/autoload/routing, permissions, HTTPS, auth and existing scheduler.
- [ ] Back up and upload/extract package; release matching files/assets/vendor and clear any stale hosted caches through the documented controlled procedure.
- [ ] Signed-out/signed-in hosted smoke against existing rows and read-only before/after aggregate comparison; no live charge.

### Local integration pending

- [ ] Coordinator to record release commit and authorized push after final review. Record hosted rollout outcome under the gates above; local completion does not claim production deployment.
