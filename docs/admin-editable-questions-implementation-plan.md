# Admin-Editable Website Questions Implementation Plan

**Goal:** The owner can add, edit, delete and reorder the website questions (Chatbot 2) from the admin area, and the supplier chat, the answer checks, the admin supplier page, My Website and the Hermes API all follow what the owner saved.
**Root cause:** The 8 questions, their options and their checks are constants and a `match` in one PHP class (`app/Support/WebsiteQuestions.php:14-145`), and three more places repeat field names by hand (`ConfirmWebsiteAnswersRequest.php:40`, `WebsiteChat.jsx:286`, `SupplierController.php:191-196`). There is no table to edit.
**Approach:** Move the questions into a `website_questions` table seeded with today's 8 (per business type), keep `WebsiteQuestions::for()` and `::rules()` as the one public entry point (now reading the table), save a copy of the questions each supplier saw next to their answers, add an admin Questions page, and add readable question labels to the Hermes payload. Registration questions (Chatbot 1), upload rules, payment, statuses and the AI wording service logic are untouched.
**Tech stack:** Laravel 11.57.1, PHP 8.3.29, MariaDB (XAMPP), Inertia v1, React 18, Tailwind 3.4, Vite 6 (`composer.json`, `package.json`, `php artisan --version`). Tests: PHPUnit through `php artisan test`, directory `tests/Feature` against database `wedworks_chatbot_test` (`phpunit.xml`).
**Source:** User, in chat: "can we make the questions able to modify in dashboard? like we can add, edit, delete, reorder, etc? is this possible>" and, after the options were laid out, "i chose 1" (option 1 = full control, with the four special questions protected from deletion).

## Open questions (resolve before implementing)

| # | Question | Why it matters | Recommended answer |
| --- | --- | --- | --- |
| 1 | Also protect **Business Description** (cannot be deleted, made optional or retyped)? You approved protecting four (services, areas, photos/logo/colors, style). | The chat has a hard-coded 5-character rule for it (`WebsiteChat.jsx:286`), the server requires it (`WebsiteQuestions.php:121`), and it is the main text Hermes builds the site from. | Yes, protect it (5 protected). Its wording stays editable. |
| 2 | Can the owner add a **second** Services, Service areas or Photos question? Earlier I listed those as addable types. | They write to fixed places: `services`, `service_areas` + `booking`, and the supplier's uploads (`ConfirmWebsiteAnswersRequest.php:28-40`, `UploadController.php:24`). A second one would overwrite the first one's answer. | No. Addable types are: short text, long text, number, choice. The three special questions exist once each. |
| 3 | The **preview** I mentioned: a list of the questions a chosen business type will see, in order, with a sample business name. Not an interactive copy of the chat. | A true chat preview needs a real supplier token and payment state. | List preview per business type. |
| 4 | What happens when a question is **deleted after some suppliers answered it**? | Their answers are kept either way. The choice is whether deleting is allowed. | Allowed (soft delete, can be restored). The page shows "N suppliers already answered this". Their saved copy keeps showing it. |
| 5 | A **required** question added after a supplier already confirmed their answers. | Payment only needs "answers confirmed" (`PaymentService.php:41`), so that supplier can still pay without answering the new question. | Do not force them. Only suppliers who have not confirmed yet are asked. Hermes simply has no answer for it. |
| 6 | **Maximum questions** per business type. | A long chat loses suppliers; the 24-hour window is short. | 20 (setting `admin.max_questions`). Today it is 8. |

Resolve 1, 2, 3 and 6 before Phase 1; 4 and 5 can follow the recommendation if you do not answer.

---

## Live database/system validation: 2026-11-07

Read-only (`php artisan tinker` counts, `migrate:status`, route list). No data changed.

1. **Live data exists.** `suppliers` = 3 (cake 2, makeup 1), `website_answers` = 1. An earlier check this session showed 0, so testing has started since. The migration must therefore be non-destructive: it adds a table and one nullable column and never rewrites a stored answer.
2. **No questions table yet.** Tables present: `admin_audit_logs, cache, cache_locks, campaigns, email_logs, failed_jobs, hermes_stage_log, job_batches, jobs, migrations, orders, password_reset_tokens, payments, sessions, settings, status_history, suppliers, uploads, users, website_answers`.
3. **All 13 existing migrations ran**, latest `2026_11_07_000001_create_settings_table` (`migrate:status`). New migrations use `2026_11_08_*`.
4. **Answers are one JSON blob per supplier**: `website_answers.answers` json, `supplier_id` unique, `submitted_at`, `locked_at` (`2026_11_06_000003_create_website_answers_table.php:11-18`). Keys inside are field names (`description`, `services`, `service_areas`, `booking`, `colors`, `style`, `additional`, `extra1`, `extra2`).
5. **There is no git repository** in the project folder (`fatal: not a git repository`). The Commit section reflects this.
6. UNVERIFIED: that `vendor/bin/pint` or any linter is installed. `composer.json` and `package.json` define no lint or typecheck script, so none is planned.
7. UNVERIFIED: JSON-path query support on this MariaDB. The plan avoids it (answers are counted in PHP; at most `slot_limit` = 10 answer rows can exist, `campaigns.slot_limit`, not editable per `docs/ADMIN.md:44`).

## Audit findings

**A. Concept discovery: every place the questions live**

1. Definitions and checks: `app/Support/WebsiteQuestions.php` (`STYLES` :14-21, `EXTRAS` :24-73, `for()` :80-115, `rules()` :118-145). Header comment says it is "the single source for both the chat and the server-side validation" (:9-10).
2. A second hand-written copy of the field names in the request: `ConfirmWebsiteAnswersRequest.php:40` (`['description','booking','colors','style','additional','extra1','extra2']` are trimmed and blanked to `null`) and the error messages :62-73 (`style.*`, `extra1.*`, `extra2.*`).
3. A third copy in the chat: `WebsiteChat.jsx:286` (`q.key === 'description'`, min 5), :290 (number format), :343 (Skip shown for every number question, even a required one).
4. A fourth in the admin: `SupplierController.php:179-206` formats stored answers and resolves `style` through `WebsiteQuestions::STYLES` (:192).
5. The prototype `docs/chatbot.html` (`CB2_GENERAL_STEPS`, `EXTRA_QUESTIONS`, lines ~1170-1222) was the wording source. Per the build prompt it is the reference for the original wording only (`WedWorks_MASTER_BUILD_PROMPT.md:42, :262`), not a live dependency.
6. Canonical source after this change: the `website_questions` table. `chatbot.html` stays as history. The constants in `WebsiteQuestions.php` are deleted after being copied into the seed migration, so there is no second source of truth.

**B. Writers and readers**

| Path | File | Role |
| --- | --- | --- |
| Only writer of `website_answers` content | `WebsiteDetailsService.php:44-47` (`updateOrCreate`) | Called from `InviteController.php:81` |
| Lock after payment | `PaymentService.php:186` (`update(['locked_at'])`) | Does not touch answers |
| Payment gate | `PaymentService.php:41` | Needs `submitted_at` only |
| Reader: chat | `InviteController.php:58-59` | `questions` + `saved` |
| Reader: AI reword | `AiWordingController.php:41-42` | Looks the question up by `key`, so a deleted or unknown key is rejected |
| Reader: My Website (after payment) | `MyWebsiteController.php:64-69` | questions + answers |
| Reader: admin supplier page | `SupplierController.php:55, :180-206` | label + formatted value |
| Reader: Hermes | `HermesOrderController.php:58` | **raw `answers` only, no labels** |
| Reader: AdminTest, WebsiteChatTest, AiWordingTest, MyWebsiteTest, HermesApiTest, PaymentTest, DevModeTest | `tests/Feature/*` | Build answers by hand with the seeded keys |

No creation path other than the one writer. `DevController.php` does not touch answers (grep found no match).

**C. Findings that change the design**

7. **Hermes gets no labels.** With fixed questions, `extra1` could be looked up in the docs (`docs/HERMES_API.md:111`). With owner-made questions, a key like `q_3fa91c2e` means nothing to the agent. The API must send each question's label and a readable answer alongside the raw answers.
8. **Answers must be explainable later.** If the owner rewords or deletes a question after a supplier confirmed, the admin page, My Website and Hermes would show the wrong label or none. Fix: save a copy of the questions in `website_answers.question_snapshot` at confirm time and read from it when present. Rows without a snapshot (the 1 live row, test fixtures) fall back to the current questions.
9. **`extra1`/`extra2` are shared across business types today** (each type has its own pair under the same two keys). A table where `key` is globally unique would force renaming stored answers and the Hermes contract. Instead `key` is unique **within the questions that can apply to one business type** (checked in code, tested), which keeps stored data and the API contract unchanged.
10. **Validation must come from the row**, not from `match ($q['type'])` on type alone: required/optional is per question (`optional` flag at `WebsiteQuestions.php:85-111`, today: description and style required, choice extras required, text/number extras optional).
11. **A returning supplier can have a draft in the browser** (`sessionStorage`, `WebsiteChat.jsx:208-240`). The draft stores an answer map and a position index; if questions changed, the index is clamped (`:219`) and answers are keyed by field, so a stale draft cannot write a wrong answer.

**D. Authorization**

12. App layer: all admin routes sit in the `admin` middleware group (`routes/web.php:53`), which runs `EnsureAdmin` before route-model binding (`bootstrap/app.php:26`). No roles exist (`docs/ADMIN.md:57`), so every admin may edit questions. The new routes join that group.
13. DB layer: the app uses one MariaDB account for everything; there are no database roles or row-level policies (Laravel/MariaDB, unlike the Supabase case this skill was written for). The server is the only gate. Public routes never write `website_questions`: the only writers are `QuestionService` (called by the admin controller) and the seed migration.
14. A hidden UI control is not the guard: protection of the locked questions is enforced in `QuestionService` and tested through direct HTTP requests.

**E. Variants** (12 business types, `BusinessTypes.php:11-24`; 6 common + 24 type-specific rows seeded)

| Variant | Supported today | Change needed |
| --- | --- | --- |
| venue, coordinator, photographer, caterer, florist, makeup, attire, entertainment, cake, invitations, transportation, other | 2 type-specific questions each (`WebsiteQuestions.php:24-73`) | Seed 2 rows each with `applies_to = [type]`, keys `extra1`/`extra2` |
| Question types | `textarea`, `number`, `choice`, `services`, `areas`, `media` (`for()` :84-112) | Add `text` (short answer) |

**F. Prior decisions**

15. D9 and rule 10 (answers lock after payment) stay: `WebsiteDetailsService::editable` (:20-28) is untouched.
16. T9 rule: "The AI only rewrites wording. It never changes which field the answer maps to, never decides validation, and never asks extra questions" (`WedWorks_MASTER_BUILD_PROMPT.md:390`). Owner-written text is just more scripted text, so this still holds. `AiWordingService` is not modified.
17. Build prompt line 262 fixes "8 questions = 6 common + 2 by business type" for the original build. This change intentionally supersedes it (the user asked for it). Docs that say "8 questions" are updated (`GO_LIVE_CHECKLIST.md:53`, `DEV_MODE.md:38`, `DEPLOYMENT.md:238`).
18. `docs/ADMIN.md:44` established the pattern "locked once anyone has registered" for the campaign price. For questions the lock is narrower (see Contract) because wording fixes must stay possible.

**G. Tooling**

19. Test harness to copy: `tests/Feature/AdminTest.php` (helpers `setUp` :30, `signedIn` :43, `supplier` :48, `paidOrder` :65; detail test :246-269) and `tests/Feature/WebsiteChatTest.php` (`supplier()` :34, `answers()` :52).
20. Checkbox pattern for the admin form: `resources/js/Pages/Admin/Settings.jsx:193` (`accent-wb-accent`). Table, panel, select, pager: `resources/js/Components/admin/ui.jsx`.
21. Admin labels for the activity page: `ACTION_LABELS` in `resources/js/lib/admin.js:46-59`. Admin nav: `NAV` in `ui.jsx:10-15`. Config: `config/admin.php`.
22. Build: `npm run build` (`package.json` scripts).

---

## Scope and constraints

### In scope
- Tables, model and seed of the default questions (Phase 1).
- `WebsiteQuestions` reads the table; request validation built from it; snapshot saved on confirm (Phase 2).
- Readers use the snapshot: My Website, admin supplier page, Hermes (with new `questions` array) (Phase 3).
- Admin Questions page: list, add, edit, delete (soft) and restore, reorder with up/down buttons, preview per business type, "N suppliers answered" counts, audit entries (Phases 4 and 6).
- Chat changes so any text/number/choice question works (Phase 5).
- Docs and tests.

### Out of scope: do not change
- Chatbot 1 registration questions (`RegistrationQuestions.php`) and the 12 business types.
- Upload rules and the `logo`/`photo` kinds (`UploadController.php`, `config/uploads.php`).
- Payment, statuses, expiry, slots, emails, scheduler.
- `AiWordingService` and its prompts (it still receives the question text).
- Roles and permissions (still one level, per `ADMIN.md:57`).
- Drag-and-drop reordering and any new npm package (up/down buttons only).
- Rewriting any stored answer.

### Non-negotiable safety constraints
- Never rewrite or delete a stored answer, and never rename a stored key.
- Locked questions cannot be deleted, retyped, re-keyed, made optional or limited to some business types, by any request (not just the UI).
- Server validation is built from the current question rows for that supplier's business type; unknown keys are never stored (`ConfirmWebsiteAnswersRequest.php:55-60` keeps working).
- The browser sends only a question `key` to the AI endpoint; the server looks the text up (`AiWordingController.php:41-47` unchanged).
- Owner-entered text is rendered by React as text (no `dangerouslySetInnerHTML`) and is never used in a URL, a file path or a query fragment.
- Audit entries hold question keys and field names only, never the question text or answers (same rule as `AuditLog.php:8`).

### Contract

**Table `website_questions`:** `id`, `key` string(40) indexed (not unique), `type` string(20) (`text|textarea|number|choice|services|areas|media`), `label` string(80), `text` string(500), `placeholder` string(160) null, `options` json null (list of `{value,label}`), `required` bool, `applies_to` json null (null = every business type, else a list of business type keys), `sort_order` unsigned int, `locked` bool, `deleted_at` null, timestamps. New column `website_answers.question_snapshot` json null.

**Fixed fields per type** (code map in `WebsiteQuestions`): `services` → `[services]`; `areas` → `[service_areas, booking]`; `media` → `[colors]`; every other type → `[key]`.

**Locked questions:** `description`, `services`, `areas`, `media`, `style` (see open question 1).
Allowed edits on a locked question: `label`, `text`, `placeholder`, and for `style` the option **labels** (values `elegant…surprise` never change). Not allowed: delete, `type`, `key`, `required`, `applies_to`, adding or removing options.

**Rules for any question:**
- `label` required, max 80. `text` required, max 500. The only `{…}` token allowed in `text` is `{business_name}` (replaced with the supplier's business name when shown).
- `placeholder` max 160.
- `type` on create is one of `text, textarea, number, choice`.
- `choice` needs 2 to 12 options, each 1 to 80 characters, no two equal ignoring case. A new option's `value` is its label at the time it is added. An existing option keeps its `value` when its label is edited.
- `applies_to`: "all" or at least one valid business type.
- New keys are generated by the server as `q_` + 8 random hex characters. The browser never supplies a key.
- **Clash rule:** two non-deleted questions that can both apply to one business type may not share a `key`. Checked on create (cannot clash), on changing `applies_to`, and on restore.
- **Cap:** for every business type, the number of non-deleted questions that apply must be at most `admin.max_questions` (20).
- **Used-answer freeze:** if any submitted answer already contains this question's `key`, then `type` and the `value` of every option that has been answered are frozen; options that were answered cannot be removed. Everything else stays editable.
- **Reorder:** the request lists every non-deleted question id exactly once; the server rewrites `sort_order` to 10, 20, 30…. Anything else is a 422.

**Answer rules generated per question (same as today unless stated):**

| Type | Rule |
| --- | --- |
| `textarea` | `required` or `nullable`, string, max 1000 (`description` keeps min 5, max 1000; `additional` keeps max 2000) |
| `text` (new) | `required` or `nullable`, string, max 200 |
| `number` | `required` or `nullable`, integer 1 to 100000 |
| `choice` | `required` or `nullable`, string, must be one of the option values |
| `services`, `areas`, `media` | exactly the rules at `WebsiteQuestions.php:122-130` |

**Hermes API:** `GET /api/hermes/orders/{reference}` keeps `answers` unchanged and adds `questions: [{ key, label, type, answer }]` in the order the supplier saw them, `answer` being a readable string or `null` (same text the admin page shows). Existing fields are not renamed.

**Acceptance cases**

| Case | Actor | Input | Required outcome |
| --- | --- | --- | --- |
| Defaults unchanged | supplier | open chat for each of the 12 types, before any edit | `WebsiteQuestions::for()` and `::rules()` equal the golden file captured from today's code |
| Add question | admin | POST text question "Years in business", all types | 302 back; row exists with generated `q_xxxxxxxx` key; every supplier chat shows it last |
| Add choice, duplicate options | admin | options "Yes", "yes" | 422 on `options` |
| Add with unknown `{token}` | admin | text "Hi {name}" | 422 on `text` |
| Add past the cap | admin | 13th custom question when 8 exist (cap 20) | 422, nothing created |
| Add, type `services` | admin | `type=services` | 422 |
| Edit wording | admin | change `text` of a question some suppliers answered | saved; old suppliers still see their saved wording on admin page/Hermes |
| Retype used question | admin | change `type` of a question with a submitted answer | 422 |
| Remove answered option | admin | drop an option that appears in a submitted answer | 422 |
| Edit locked | admin | delete / retype / optional / `applies_to` on `style` | 403 or 422, row unchanged |
| Edit locked label | admin | change label/text of `services` | saved |
| Clash | admin | set a seeded `extra1` to also apply to a type that has its own `extra1` | 422 naming the other question |
| Delete | admin | delete a custom question | soft-deleted; gone from chat and rules; `answers` already stored are untouched |
| Delete with answers | admin | delete a question 1 supplier answered | allowed; response shows "1 supplier"; that supplier's admin page still shows it |
| Restore | admin | restore; then clash/cap | restored when valid, 422 otherwise |
| Reorder | admin | valid full id list | `sort_order` rewritten; chat order follows |
| Reorder bad list | admin | missing id, extra id, duplicate id | 422, order unchanged |
| Per-type | supplier | question with `applies_to=[venue]` | shown to venue, absent for cake; a cake supplier posting that key has it dropped (not stored) |
| Required custom text | supplier | empty answer | 422 on that key; chat blocks empty client-side too |
| Optional custom text | supplier | empty answer | saved as `null` |
| Choice not in options | supplier | any other value | 422 |
| Snapshot | supplier | confirm | `question_snapshot` holds the questions as shown; later edits do not change what admin/Hermes show for this supplier |
| Re-confirm | supplier | edit and confirm again before paying | snapshot replaced with current questions |
| Legacy row | admin | supplier whose answers have no snapshot | admin page shows current labels (no error) |
| Unauthorized | guest | any `/admin/questions*` request | redirect to login, same as the other admin pages |
| Direct supplier write | supplier | POST `/invite/{token}/confirm` with extra keys | extra keys ignored (not stored) |
| Hermes | agent | GET order | `answers` as before + `questions[]` with labels |
| Every creation path | n/a | the only writer of answers is `WebsiteDetailsService::confirm` | covered by Snapshot and Re-confirm cases |

## Files

| File | Change | Responsibility |
| --- | --- | --- |
| `database/migrations/2026_11_08_000001_create_website_questions_table.php` | new | Table, plus the 30 default rows copied from today's class (6 common, plus 2 for each of the 12 types) |
| `database/migrations/2026_11_08_000002_add_question_snapshot_to_website_answers.php` | new | Nullable json column |
| `app/Models/WebsiteQuestion.php` | new | Model (soft deletes, casts, `appliesTo($type)` scope, `LOCKED` keys) |
| `app/Services/QuestionService.php` | new | Create, update, delete, restore, reorder; clash, cap, freeze and lock rules; used-count; audit |
| `app/Http/Requests/Admin/SaveQuestionRequest.php` | new | Input validation for create and update |
| `app/Http/Controllers/Admin/QuestionController.php` | new | Index, store, update, destroy, restore, reorder |
| `resources/js/Pages/Admin/Questions.jsx` | new | The admin page |
| `tests/Feature/QuestionAdminTest.php` | new | All acceptance cases |
| `tests/Support/website_questions_golden.json` | new | Output of today's `for()` and `rules()` for 12 types, captured before the refactor |
| `app/Support/WebsiteQuestions.php` | edit | Read the table; keep `for()` and `rules()` signatures; add `snapshot()`, `forAnswer()`, `readable()`; remove constants |
| `app/Http/Requests/ConfirmWebsiteAnswersRequest.php` | edit | Trim/blank every answer field from the questions; generic messages |
| `app/Services/WebsiteDetailsService.php` | edit | Save the snapshot on confirm |
| `app/Models/WebsiteAnswer.php` | edit | `question_snapshot` fillable and cast |
| `app/Http/Controllers/MyWebsiteController.php` | edit | Use `forAnswer()` |
| `app/Http/Controllers/Admin/SupplierController.php` | edit | Use `forAnswer()` and `readable()`; delete the duplicated formatter |
| `app/Http/Controllers/Api/HermesOrderController.php` | edit | Add `questions[]` |
| `routes/web.php` | edit | Six admin routes |
| `config/admin.php` | edit | `max_questions` |
| `resources/js/Components/WebsiteChat.jsx` | edit | `text` type, generic required check, Skip only when optional |
| `resources/js/Components/admin/ui.jsx` | edit | "Questions" nav item |
| `resources/js/lib/admin.js` | edit | Five activity labels |
| `tests/Feature/HermesApiTest.php` | edit | Assert `questions[]` |
| `docs/ADMIN.md` | edit | Questions page, rules, limits |
| `docs/HERMES_API.md` | edit | `questions[]`, keys are no longer a fixed list |
| `docs/BUILD_LOG.md` | edit | T13 section |
| `docs/DEPLOYMENT.md` | edit | Migration order; "8 questions" wording |
| `docs/GO_LIVE_CHECKLIST.md` | edit | Wording; check the Questions page before launch |
| `docs/DEV_MODE.md` | edit | "8 questions" wording |
| `public/build/*` | generated | `npm run build` output, never hand-edited |

(Seed count: 6 common + 12 types x 2 = 30 rows.)

## Phase 0: Failing tests first

### Task 0.1: Golden file of today's questions (run BEFORE touching any app code)
**File:** `tests/Support/website_questions_golden.json`
- [ ] Write a one-off tinker command that, for each key in `BusinessTypes::keys()`, stores `['questions' => WebsiteQuestions::for($type, 'Biz'), 'rules' => array_map(fn ($r) => array_map('strval', $r), WebsiteQuestions::rules($type))]` as JSON (`Rule::in` objects become strings with `strval`).
- [ ] Run: `php artisan tinker --execute="..."` writing to that path.
- [ ] Expected: file exists with 12 entries, 8 questions each. Do not edit it by hand.

### Task 0.2: `QuestionAdminTest`
**File:** `tests/Feature/QuestionAdminTest.php` (inside `tests/Feature`, which `phpunit.xml` runs). Copy `setUp`/`signedIn`/`supplier` from `AdminTest.php:30-64` and `answers()` from `WebsiteChatTest.php:52-65`. Use `RefreshDatabase` (migrations include the seed rows).

Golden test, literal:

```php
public function test_default_questions_and_rules_match_the_original_for_every_type(): void
{
    $golden = json_decode(file_get_contents(base_path('tests/Support/website_questions_golden.json')), true);
    foreach (BusinessTypes::keys() as $type) {
        $this->assertEquals($golden[$type]['questions'], WebsiteQuestions::for($type, 'Biz'), $type);
        $rules = array_map(fn ($r) => array_map('strval', $r), WebsiteQuestions::rules($type));
        $this->assertEquals($golden[$type]['rules'], $rules, $type);
    }
}
```

Create-and-ask test, literal:

```php
public function test_a_new_question_appears_last_in_every_chat_and_is_validated(): void
{
    $this->signedIn()->post('/admin/questions', [
        'label' => 'Years in business', 'text' => 'How long have you been in business?',
        'type' => 'text', 'required' => true, 'scope' => 'all',
    ])->assertRedirect();

    $key = WebsiteQuestion::where('label', 'Years in business')->value('key');
    $this->assertMatchesRegularExpression('/^q_[0-9a-f]{8}$/', $key);

    $s = $this->supplier(); // photographer, from the helper
    $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p
        ->has('chat.questions', 9)->where('chat.questions.8.key', $key));

    $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$key => '']))->assertSessionHasErrors($key);
    $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$key => '5 years']))->assertSessionHasNoErrors();
    $this->assertSame('5 years', WebsiteAnswer::sole()->answers[$key]);
}
```

Remaining tests: one method per row of the acceptance table (names like `test_locked_question_cannot_be_deleted`, `test_clash_between_types_is_rejected`, `test_reorder_rejects_a_bad_list`, `test_snapshot_keeps_old_wording_after_an_edit`, `test_legacy_answer_without_snapshot_still_renders`, `test_guests_are_redirected_from_every_questions_route`, `test_hermes_gets_labels`). Each posts to the real routes in Phase 4 and asserts the status, the database row and the Inertia props.
- [ ] Run: `php artisan test --filter=QuestionAdminTest`
- [ ] Expected: FAIL (class `WebsiteQuestion` and routes do not exist).

## Phase 1: Data layer

### Task 1.1: Migrations and model
**Files:** the two migrations, `app/Models/WebsiteQuestion.php`
- [ ] `create_website_questions_table`: columns as in the Contract. In the same migration insert the 30 default rows. Copy text, labels, placeholders and options **verbatim** from `WebsiteQuestions.php:84-100` (six common) and `:24-73` (24 extras). The description text becomes "...what makes {business_name} special to couples getting married?". Style options are `{value,label}` from `STYLES` (:14-21). `required`: description true, style true, services/areas/media/additional false, every choice extra true, every text/number extra false (this equals the `optional` flags at :85-111). `locked` true for description, services, areas, media, style. `sort_order`: common 10-60; extras `100 + typeIndex*10 + (n-1)`, `typeIndex` = position in `BusinessTypes::keys()`. Extras: `key` `extra1`/`extra2`, `applies_to = [type]`.
- [ ] `add_question_snapshot`: `$table->json('question_snapshot')->nullable()` after `answers`. `down()` drops it.
- [ ] Model: `SoftDeletes`; casts `options`, `applies_to` array, `required`, `locked` bool; scope `forType($type)` (applies_to is null or contains the type, filtered in PHP after a single `orderBy('sort_order')->orderBy('id')` query); `const LOCKED = ['description','services','areas','media','style']`.
- [ ] Run: `php artisan migrate` (dev database; the tests migrate their own database).
- [ ] Expected: 30 rows; read-only check `php artisan tinker --execute="echo App\Models\WebsiteQuestion::count();"` prints 30; `website_answers` row count still 1.

**Phase constraints:** no change to `website_answers.answers` data. Migration is forward-only; never edit it after it has run anywhere.

## Phase 2: One entry point reads the table

### Task 2.1: `WebsiteQuestions`
**File:** `app/Support/WebsiteQuestions.php`
- [ ] `for($type, $businessName)`: load rows for the type, return arrays in exactly today's shape (`key,label,type,fields,optional,text,placeholder,options`), `optional = ! required`, `text` with `{business_name}` replaced, `options` null unless choice. `fields` from the fixed map (Contract).
- [ ] `rules($type)`: build from the same rows using the answer rules table; the `services`/`service_areas`/`booking`/`colors` entries are emitted when the type has those questions, with today's exact rules (:122-130).
- [ ] `snapshot(Supplier)`: `for()` output, ready to store. `forAnswer(?WebsiteAnswer, Supplier)`: snapshot when not null, else `for()`. `readable(array $questions, array $answers)`: moves the formatting from `SupplierController.php:187-205` here (services as "name (price)", arrays joined, choice value shown as its option label, multi-field joined with " · ").
- [ ] Remove `STYLES` and `EXTRAS`. Search the code for `STYLES` first: only `SupplierController.php:192` and the class use it.
- [ ] Run: `php artisan test --filter=QuestionAdminTest::test_default_questions_and_rules_match_the_original_for_every_type`
- [ ] Expected: PASS (golden).

### Task 2.2: Request and service
**Files:** `ConfirmWebsiteAnswersRequest.php`, `WebsiteDetailsService.php`, `WebsiteAnswer.php`
- [ ] `prepareForValidation`: after building `services` and `service_areas`, loop over every field in `WebsiteQuestions::for(...)` that is not `services`/`service_areas`, trim strings and turn `''` into `null` (replaces the hard-coded list at :40).
- [ ] `messages()`: keep the description messages; add generic `*.required` ("Please answer this question.", or "Please choose an option." for choice) and `*.in` messages produced from the question list. Keep the existing `style` and `extra` wording for those keys so current tests still pass.
- [ ] `WebsiteDetailsService::confirm`: add `'question_snapshot' => WebsiteQuestions::snapshot($locked)` to the `updateOrCreate` values (:46).
- [ ] `WebsiteAnswer`: add `question_snapshot` to `$fillable` and cast `array`.
- [ ] Run: `php artisan test --filter=WebsiteChatTest` and `--filter=PaymentTest`
- [ ] Expected: PASS unchanged (seeded keys are the same).

**Phase constraints:** `editable()` rules, 403 vs 422 order and status moves are unchanged.

## Phase 3: Readers

### Task 3.1: My Website, admin page, Hermes
**Files:** `MyWebsiteController.php`, `SupplierController.php`, `HermesOrderController.php`, `tests/Feature/HermesApiTest.php`
- [ ] `MyWebsiteController.php:66`: `'questions' => WebsiteQuestions::forAnswer($supplier->websiteAnswer, $supplier)`.
- [ ] `SupplierController::answers()`: `forAnswer(...)` then `readable(...)`; return `label` and `value` as before (`AdminTest.php:262-264` expects the same output).
- [ ] `HermesOrderController::show`: keep `answers` (:58), add `'questions' => readable(forAnswer(...), answers)` mapped to `{key,label,type,answer}`.
- [ ] `HermesApiTest`: extend the existing show test (near `HermesApiTest.php:175`) to assert `data.questions.0.label` and `data.answers.style` still present.
- [ ] Run: `php artisan test --filter="AdminTest|MyWebsiteTest|HermesApiTest"`
- [ ] Expected: PASS.

**Phase constraints:** `answers` is not renamed or reshaped (the Hermes contract in `docs/HERMES_API.md:92-111`).

## Phase 4: Admin backend

### Task 4.1: Service, request, controller, routes
**Files:** `QuestionService.php`, `SaveQuestionRequest.php`, `QuestionController.php`, `routes/web.php`, `config/admin.php`, `resources/js/lib/admin.js`
- [ ] `config/admin.php`: `'max_questions' => 20,` with a one-line comment.
- [ ] Routes inside the existing `middleware('admin')` group (`routes/web.php:53`), registered **before** any `{question}` wildcard:
  `GET /questions` (`admin.questions`), `POST /questions` (`admin.questions.store`), `POST /questions/reorder` (`admin.questions.reorder`), `PUT /questions/{question}` (`admin.questions.update`), `DELETE /questions/{question}` (`admin.questions.destroy`), `POST /questions/{id}/restore` (`admin.questions.restore`). `{question}` and `{id}` use `whereNumber`. Restore takes the raw id because soft-deleted rows are not bound by default.
- [ ] `QuestionService`: implements every rule in the Contract inside `DB::transaction`, re-reading rows with `lockForUpdate` for update, delete, restore and reorder so two admins cannot break the clash/cap rules. `usedCount($key)` scans `WebsiteAnswer::whereNotNull('submitted_at')` in PHP and counts rows whose `answers` contain the key. Each change calls `AuditLog::record('question_created' | 'question_updated' | 'question_deleted' | 'question_restored' | 'questions_reordered', ['key' => ..., 'fields' => [...changed field names]])`.
- [ ] `SaveQuestionRequest`: the input rules above. `authorize()` returns true (the `admin` middleware is the gate, as for `SettingsController`).
- [ ] `QuestionController::index` returns `questions` (all non-deleted, with `used_count`, `locked`), `deleted` (soft-deleted), `types` (`BusinessTypes::ALL`), `max`. Mutating actions redirect back with the existing `admin_flash` message (`HandleInertiaRequests` shares it, per the T12 notes).
- [ ] `ACTION_LABELS` in `admin.js`: `question_created: 'Question added'`, `question_updated: 'Question edited'`, `question_deleted: 'Question deleted'`, `question_restored: 'Question restored'`, `questions_reordered: 'Questions reordered'`.
- [ ] Run: `php artisan test --filter=QuestionAdminTest`
- [ ] Expected: all backend cases PASS.

**Phase constraints:** no new middleware, no role system; every route stays behind `admin` (guests get the login redirect).

## Phase 5: Supplier chat

### Task 5.1: `WebsiteChat.jsx`
**File:** `resources/js/Components/WebsiteChat.jsx`
- [ ] `submitText` (:284-295): replace the `q.key === 'description'` check with a generic one: if `!q.optional && v === ''` set "Please answer this question."; keep the description 5-character message when `q.key === 'description'` (it is locked, so it always exists).
- [ ] `renderInput` (:326-381): add `case 'text'` using the same `TextareaInput` layout but a single-line `TextInput` (import already present from `./wb/forms`). In the `number` branch (:343) show the Skip button only when `q.optional`.
- [ ] Update the comment at :13 to say questions come from the `website_questions` table.
- [ ] Run: `npm run build`
- [ ] Manual check: sign in at `/admin`, add a required short-text question and an optional choice question, open a fresh invite link, answer through to the review card; the review row shows the new labels; Confirm saves; the supplier cannot skip the required one.

**Phase constraints:** no change to services, areas, media, payment cards or the countdown.

## Phase 6: Admin page

### Task 6.1: `Questions.jsx` and nav
**Files:** `resources/js/Pages/Admin/Questions.jsx`, `resources/js/Components/admin/ui.jsx`
- [ ] `ui.jsx` `NAV`: insert `['/questions', 'Questions']` after Suppliers.
- [ ] Page layout with the design system tokens only (`Panel`, `Table`/`Th`/`Td`, `Select`, wb `Button`, `Pill`, `Alert`, `Field`/`TextInput`/`Textarea` from `Components/wb/forms`; checkbox style as `Settings.jsx:193`):
  - Header row: "Add a question" button and a "Preview as" select (12 business types). The list is filtered to the type's questions in order, with `{business_name}` shown as "Your Business".
  - Table columns: order, question (label and text), type pill, "Applies to" pill ("All" or "N types"), required pill, answered ("N suppliers"), actions (Up, Down, Edit, Delete). Locked rows show a "Required by the site build" pill and no Delete.
  - Edit/add form in a `Panel`: label, question text (with a hint that `{business_name}` can be used), type (disabled when frozen), placeholder, options (one field per option with add/remove, choice only; frozen option values show as read-only), required, "Applies to" (All, or tick business types).
  - Up/Down send the **full** new order of ids (swap with the previous/next row in the full list, skipping rows hidden by the Preview filter) to `POST /questions/reorder`.
  - "Deleted questions" panel with Restore.
  - 375 px: table scrolls inside its wrapper like the suppliers table; no page-level horizontal scroll.
- [ ] Run: `npm run build`
- [ ] Manual check (built-in browser, 1280 px and 375 px): add, edit, reorder, delete, restore; delete is blocked on `Services / Packages`; the error text for a clash is readable.

## Phase 7: Docs

### Task 7.1
**Files:** the six docs in the Files table
- [ ] `ADMIN.md`: new "Questions" row in the page table and a section with the rules (locked questions, per-business-type, used-answer freeze, soft delete, cap, that existing suppliers keep what they answered, that Hermes receives labels).
- [ ] `HERMES_API.md`: document `questions[]`; replace the fixed key list at :111 with "keys of the default questions are `description`…; any other key starts with `q_` and is described in `questions[]`".
- [ ] `BUILD_LOG.md`: T13 section with decisions, test counts, and what was not tested.
- [ ] `DEPLOYMENT.md`, `GO_LIVE_CHECKLIST.md`, `DEV_MODE.md`: replace "8 questions" with "the questions"; add to the checklist "Open Admin → Questions and confirm the wording and order are what you want".

## Phase last: Regression verification and rollout

- Focused: `php artisan test --filter=QuestionAdminTest`, then `--filter="WebsiteChatTest|AiWordingTest|MyWebsiteTest|HermesApiTest|AdminTest|PaymentTest"`.
- Full suite: `php artisan test` (expected: the previous 212 plus the new tests, all passing).
- Lint/typecheck: none configured; skip. Build: `npm run build` must finish without errors.
- Smoke table (built-in browser):

| Role | Variant | Action | Expected |
| --- | --- | --- | --- |
| Admin | any | Questions page loads, 5 locked rows show no Delete | yes |
| Admin | all | Add required text question | appears at the end for all 12 types in Preview |
| Admin | cake | Add a question for cake only | cake preview shows it, venue preview does not |
| Admin | any | Reorder, reload | order persists |
| Supplier (invited) | cake | Open chat | custom question shown; required cannot be skipped |
| Supplier (confirmed, unpaid) | cake | Admin edits wording; supplier opens My Website after paying (dev mode) | their saved wording shows |
| Admin | confirmed supplier | Open supplier page | answers show with the labels they saw |
| Hermes | paid order | `GET /api/hermes/orders/{reference}` (with the token) | `answers` unchanged, `questions[]` with labels |
| Guest | n/a | `/admin/questions` | redirect to login |

- Data checks after deploy (read-only): `WebsiteQuestion::count()` = 30 right after migrating; `WebsiteAnswer::count()` equals the count before; the 1 existing answer row still renders on its admin page.
- Deployment order: put the site in maintenance (`php artisan down`), upload code and rebuilt `public/build`, run `php artisan migrate --force`, then `php artisan up`. Code before migration would query a table that does not exist yet.

## Rollback

1. Bad wording or order: edit or reorder on the Questions page (no deploy). A wrongly deleted question: Restore.
2. If the feature itself must go: re-deploy the previous code. The new table and the `question_snapshot` column are ignored by old code and harmless. Do not drop them, and do not edit the applied migrations; a later forward migration can remove them once nothing needs them.
3. Data written during the rollout stays: stored answers are plain keyed JSON. Old code does not know custom `q_…` keys, so it ignores them when a supplier re-confirms, which drops them from that supplier's saved answers. Prefer fixing forward over rolling back once suppliers have answered custom questions.
4. Hermes agents that already read `questions[]` get `undefined` after a code rollback; they must keep working from `answers` alone (the field is additive).

## Commit

The project folder is not a git repository (`fatal: not a git repository`), so there is nothing to `git add`. Back up the folder, or run `git init` first if you want history. The change set is exactly the Files table above (new files, edited files, and the regenerated `public/build/*`), excluding this plan file.

## Self-review

1. **Contradictions:** In scope says "add, edit, delete, reorder" while out of scope forbids drag-and-drop and a second services/areas/photos question. These do not conflict (up/down is reordering) but both narrow what was offered in chat, so they are open questions 2 and 3. Locked questions are consistently non-deletable in the Contract, Phase 4 and the page.
2. **Goal reachability:** Existing rows: the 1 live answer row has no snapshot, falls back to current questions (tested as the legacy row case); its keys are the seeded keys, so nothing is lost. Future rows: the only writer of answers is `WebsiteDetailsService.php:44`, which writes the snapshot, so every creation path is covered. Fixed in the draft: Hermes had no labels (added `questions[]`).
3. **Bypass:** Public routes cannot write questions. A supplier cannot store a key outside their type's questions (`ConfirmWebsiteAnswersRequest.php:55-60`, case "Direct supplier write"). The AI endpoint rejects keys not in the supplier's current set (`AiWordingController.php:42`). Locked-question protection is in `QuestionService`, not just the UI (cases "Edit locked", "Delete"). The Hermes token cannot reach any question route. Concurrent admins: row locks inside the transaction.
4. **Existence:** every file, line and identifier above was read or grepped in this session: `config/admin.php`, `ui.jsx` NAV, `admin.js` ACTION_LABELS, `AdminTest.php` helpers, `WebsiteChatTest.php` helpers, `HermesOrderController.php:58`, `PaymentService.php:41, :186`, `Settings.jsx:193`, `tests/Support`, `docs/ADMIN.md:44, :57`. Not yet existing by design: the new files in the Files table and the `max_questions` key. UNVERIFIED: a lint tool (none planned) and JSON-path SQL (avoided).
5. **Consistency:** Files table, phases and the Commit section list the same files. Tests go in `tests/Feature` (the folder `phpunit.xml` runs) and the golden fixture in the existing `tests/Support` folder. The seed count is 30 (6 + 12 x 2) in the Files table, Phase 1 and the post-deploy check.
6. **Duplication:** the answer formatter that existed in `SupplierController.php:187-205` moves into `WebsiteQuestions::readable()` and is reused by Hermes (one copy, not two). The constants `STYLES` and `EXTRAS` are deleted once seeded. The client-side `summarize.js` remains the only other formatter and is not changed (pre-existing).
7. **Placeholders:** none left. Test methods other than the two literal ones are named and tied to rows of the acceptance table, not left as "etc.".

Audit-checklist sections not applicable: C "live data" has no anomaly to root-cause (counts only), and D's database-policy item is replaced by finding 13 (no database roles in this stack).
