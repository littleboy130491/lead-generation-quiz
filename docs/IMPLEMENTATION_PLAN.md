# Lead Generation Quiz Platform Implementation Plan

> **For implementers:** Read `AGENTS.md` and `PRD.md` before every task. Every product-affecting change must update the normative PRD section and its Change Log in the same change set.

**Goal:** Deliver a production-ready Laravel/Filament platform for versioned lead-generation quizzes, resumable anonymous submissions, AI-generated structured reports, and auditable Mailgun delivery.

**Architecture:** Use relational operational models around immutable JSON quiz revisions. Public quiz interactions use Livewire and server-authoritative validation. External AI and email work runs on queues; scheduled reconciliation provides idempotent recovery. Laravel AI SDK and Mailgun are wrapped behind application services.

**Baseline stack:** PHP 8.3+, Laravel 13, Filament 5, Livewire 4, Tailwind 4, Laravel AI SDK 0.11, Curator 5, Laravel queues/scheduler, Mailgun, PHPUnit.

---

## 1. Delivery principles

- Implement vertical behavior slices with RED-GREEN-REFACTOR.
- Keep controllers, Livewire components, Filament resources, jobs, and commands thin.
- Prefer application actions for transactions and domain services for pure rules.
- Back lifecycle values with enums.
- Use public UUIDs for respondent-facing identifiers and numeric/UUID primary keys according to established project conventions.
- Make finalization, queue claims, reconciliation, and webhooks idempotent by design and database constraint.
- Do not implement deferred features unless the PRD is updated deliberately.
- Commit each independently green slice.

## 2. Proposed source layout

```text
app/
  Actions/
    Analyses/
    Deliveries/
    Quizzes/
    Submissions/
  Ai/
    Agents/
    Contracts/
    Data/
    LaravelAi/
  Console/Commands/
  Domain/Quiz/
    Conditions/
    Definition/
    Pagination/
    Validation/
  Enums/
  Filament/
    Pages/
    Resources/
  Http/Controllers/Webhooks/
  Jobs/
  Livewire/Quiz/
  Mail/
  Models/
  Policies/
  Providers/
  Settings/
config/
database/
  factories/
  migrations/
  seeders/
docs/
resources/
  css/
  js/
  views/
    livewire/quiz/
    mail/reports/
routes/
tests/
  Feature/
  Unit/
```

## 3. Phase 0 — Baseline scaffold

### 0.1 Framework and packages

Baseline already expected:

- Laravel application.
- Filament admin panel at `/admin`.
- Livewire through Filament.
- Curator media package and migration.
- Laravel AI SDK configuration.
- Symfony Mailgun and HTTP client transports.
- SQLite local database and default queue tables.

Verification:

```bash
composer validate --strict
php artisan about
php artisan route:list
php artisan migrate:fresh
php artisan test
npm install
npm run build
```

### 0.2 Baseline configuration

Files:

- `config/ai.php`
- `config/mail.php`
- `config/services.php`
- `.env.example`
- `app/Providers/Filament/AdminPanelProvider.php`

Tasks:

1. Register `CuratorPlugin` on the admin panel.
2. Add documented Mailgun and AI environment placeholders without secrets.
3. Ensure the database queue is the local default.
4. Document production cron and queue-worker requirements in `README.md` before deployment.
5. Add a CI workflow for Composer install, migrations, tests, Pint, and frontend build.

## 4. Phase 1 — Domain foundations and schema

### 1.1 Lifecycle enums

Create:

- `app/Enums/QuizStatus.php`
- `app/Enums/SubmissionStatus.php`
- `app/Enums/AnalysisStatus.php`
- `app/Enums/AnalysisTrigger.php`
- `app/Enums/DeliveryStatus.php`
- `app/Enums/DeliveryTrigger.php`
- `app/Enums/AnalysisMode.php`

Tests:

- Values exactly match PRD conventions.
- Model casts round-trip each enum.

### 1.2 Core migrations and models

Create migrations, models, factories, and relationships for:

- `Quiz`
- `QuizRevision`
- `Submission`
- `Analysis`
- `ReportDelivery`
- `EmailTemplate` or settings-backed templates after deciding the simplest MVP representation

Important constraints:

- Unique quiz slug.
- Unique `(quiz_id, version)` revision.
- Unique resume-token hash when present.
- One initial automatic analysis per submission, enforced by a nullable `(submission_id, automatic_key)` unique constraint; use the same scoped-key pattern as `(analysis_id, automatic_key)` for automatic delivery requests. Keep manual rows' markers null. Amend the clean bootstrap migrations rather than retaining globally unique marker indexes.
- Provider message ID indexed for webhook lookup.
- Foreign-key deletion rules preserve audit history.

TDD sequence per model:

1. Write relationship/cast/invariant test.
2. Run focused test and observe expected failure.
3. Add migration/model/factory minimum.
4. Run focused and full tests.
5. Commit.

### 1.3 Settings repository

Create an application settings abstraction rather than reading arbitrary database rows throughout the app.

Settings groups:

- AI quiz generation.
- AI report generation.
- Mail/report templates.
- Resume and retention.
- Spam controls.
- Design tokens and additional CSS.

Provider credentials remain in environment-backed `config/ai.php`.

## 5. Phase 2 — Quiz definition engine

### 2.1 Definition data objects and schema versioning

Create typed DTOs/value objects for:

- Quiz definition.
- Question block.
- Content block.
- Page-break block.
- Choice option.
- Visibility rule/group.

Add a `schema_version` dispatcher so future migrations can be explicit.

Tests:

- Valid examples deserialize and serialize without losing IDs.
- Unsupported schema versions fail clearly.
- Unknown executable block types are rejected.

### 2.2 Page compiler

Create a pure `QuizPageCompiler` that converts a linear block list into pages.

RED cases:

- Multiple questions before a break share page one.
- A content-only intermission remains a page.
- Leading, trailing, and consecutive breaks fail publish validation.
- Pages rendered empty after visibility filtering are skipped.

### 2.3 Condition evaluator

Create a server-authoritative pure evaluator supporting the PRD operators and nested `all`/`any` groups.

Tests cover:

- Missing answers.
- Scalar and multi-select answers.
- Numeric comparison.
- Empty/not-empty semantics.
- Type/operator incompatibility.
- Nested groups.
- Hidden required questions.

Implement the same JSON contract in a small frontend evaluator, then add contract fixtures consumed by both PHP and JavaScript tests to prevent divergence.

### 2.4 Publish validator

Validate:

- Unique stable block/question/option IDs.
- Supported block and question types.
- Valid page-break placement.
- Choice questions have valid options.
- Conditions reference existing earlier questions and valid option values.
- Required fields and maximum lengths.
- Sanitizable Markdown and valid Curator references.

## 6. Phase 3 — Quiz administration

### 3.1 Quiz Filament resource

Create pages for list, create, edit, and view. Include lifecycle badges, slug management, password configuration, active revision, and funnel counts.

Password behavior:

- Hash on set.
- Never display the stored hash.
- Blank edit field means unchanged unless a separate remove action is used.

### 3.2 Block builder

Use Filament builder/repeater components with stable IDs and drag ordering. The MVP form must expose name, unique non-reserved slug validation, draft default status, hash-only password input, and non-secret lead-capture settings. On edit, hydrate the stored definition into Builder state and omit `password_hash`; a blank password means unchanged.

Block editors:

- Question with type-specific fields, requiredness, help text, repeatable options, and structured visibility fields.
- Content with Markdown, optional continue label, and structured visibility fields.
- Visual page-break separator.

Transform Builder items to `{schema_version: 1, blocks: [...]}` before persistence and reverse that transform for editing. Do not save array positions as identity or add arbitrary raw script fields.

### 3.3 Conditions editor

Provide structured rule groups and restrict selectable dependencies to valid earlier questions. Server validation remains authoritative even if the UI prevents most invalid combinations.

### 3.4 Preview and publish

Create:

- Draft preview route accessible only to authorized admins with signed/authorized access.
- `PublishQuizRevision` action inside a database transaction.
- Revision viewer and comparison metadata.

Tests:

- Publishing invalid draft fails without a revision.
- Publishing creates a new version and updates active revision atomically.
- Existing revisions cannot be changed or deleted; enforce this in `QuizRevision` model events for identity/payload fields, not only through the admin UI.
- In-progress submissions remain on their original revision.

## 7. Phase 4 — Public quiz and resume

### 4.1 Public routing and protection

Routes conceptually:

```text
GET  /{quiz:slug}
POST /{quiz:slug}/unlock
GET  /{quiz:slug}/contact
GET  /{quiz:slug}/complete
```

Use reserved-path protection so slugs cannot shadow `/admin`, webhooks, assets, or health routes.

### 4.2 Start/resume action

`StartOrResumeSubmission`:

1. Resolve active published revision.
2. Read encrypted resume cookie.
3. Hash token and locate eligible submission for this quiz.
4. Resume original revision or create a new `in_progress` submission.
5. Queue the raw token in a secure cookie; store only its hash.
6. Create the submission immediately with first-touch attribution/context and append a `started` event; on resume retain first touch, update latest touch, and append `resumed`.

Tests include invalid, expired, cross-quiz, completed, and rotated tokens.

Attribution data is bounded and privacy-minimized: retain structured sanitized query parameters, landing URL, referrer, trusted-proxy-aware IP, user-agent, and a conservative app-owned device/browser/platform parser. Redact secret-looking query keys and never copy cookies, answers, or contact values to events. Every meaningful public transition and analysis/delivery lifecycle transition appends an immutable context snapshot in `submission_events`; expose it in authorized Filament inspection with attribution filters.

### 4.3 Livewire runner

Create page rendering and navigation with:

- Progress indicator based on effective visible pages.
- Back/next controls.
- Per-page validation.
- Multiple questions on a page.
- Content-only pages.
- Mobile and keyboard accessibility.

The current-page server-side save action is the sole public questionnaire mutation path. Before any write, it derives the exact allowed ID set from the frozen server-evaluated visible current page and rejects every unknown, hidden, off-page, or stale answer key; content-only pages accept only an empty answer map. It then validates question types, requiredness, option values, and text bounds against the frozen revision; it never trusts client-supplied labels or visibility. Rejection must leave answers, page/status, activity/touch context, events, completion, and analysis state unchanged. Do not add a direct questionnaire-completion endpoint. Feature coverage must submit valid visible answers plus an unknown ID and prove rejection with no mutation.

### 4.4 Questionnaire completion and contact capture

`CompleteQuestionnaire` is an internal continuation of the validated current-page save flow. It verifies all visible required answers across the revision before setting `awaiting_contact`; it is not exposed as a public arbitrary-answer endpoint.

`FinalizeSubmission`:

1. Locks the submission row.
2. Verifies `awaiting_contact` and valid email/contact fields.
3. Runs anti-spam acceptance checks.
4. Freezes the final human-readable answer snapshot.
5. Marks completed.
6. Creates exactly one initial automatic queued analysis.
7. Dispatches after commit.

Tests exercise repeated requests and concurrent/idempotent behavior.

## 8. Phase 5 — Spam and abuse controls

### 5.1 Rate limits

Define named limiters for:

- Quiz starts.
- Unlock attempts.
- Progress saves.
- Contact finalization.

### 5.2 Honeypot and Turnstile

Implement a small contract around Turnstile so feature tests can fake verification. Do not couple form components directly to the external HTTP client.

### 5.3 Risk signals and budgets

Record normalized signals in submission metadata without exposing them to the AI prompt. Add configurable AI generation budgets and an operational emergency stop. Under MVP `always` mode, all accepted completed submissions receive analysis; rejected requests never become completed.

## 9. Phase 6 — AI quiz helper

### 6.1 Application contract

Create `QuizDefinitionGenerator` and a Laravel AI implementation. Define a structured output schema matching the supported draft-definition subset.

### 6.2 Prompt construction

System instructions must specify:

- Output schema.
- Stable ID requirements.
- Supported question and condition types.
- No executable content.
- Earlier-question-only condition references.
- Draft status and requirement for human review.

### 6.3 Admin generation flow

Filament action collects business context, target audience, objective, desired insight, count, and tone. Generation runs in a queue if response time can exceed a normal admin request. Validate returned definition and present a diff/preview before applying it to the draft.

Tests fake the Laravel AI SDK and prove invalid output cannot overwrite a draft.

## 10. Phase 7 — AI report pipeline

### 7.1 Structured report schema

Finalize and document a versioned schema for executive summary, profile, strengths, challenges, prioritized recommendations, action plan, and disclaimer.

### 7.2 Prompt safety

Create a prompt builder that:

- Includes only frozen revision context and frozen answers.
- Clearly delimits respondent data as untrusted.
- Instructs the model never to follow respondent instructions.
- Includes no secrets or unrelated submissions.
- Captures exact system-prompt and input snapshots on `Analysis`.

Unit tests assert malicious respondent text remains inside the untrusted-data envelope and cannot alter system instructions structurally.

### 7.3 Generation job

`GenerateAnalysisJob`:

1. Atomically claims queued/eligible analysis.
2. Records processing timestamps.
3. Builds the configured provider/model chain.
4. Calls `QuizAnalysisGenerator`.
5. Validates structured output.
6. Records actual provider/model and normalized failover attempts.
7. Marks completed or failed.
8. Requests automatic delivery after commit when eligible.

Add retries/backoff for infrastructure failures while avoiding multiplicative retries between the SDK, Laravel job, and scheduler.

### 7.4 Manual and bulk analyses

Filament actions append manual analyses. Manual analyses default to no automatic email; an explicit “generate and send” option requests delivery after completion.

## 11. Phase 8 — Report rendering and Mailgun

### 8.1 Controlled renderer

Create versioned Blade templates for HTML and text. Render only validated report fields. Sanitize any permitted Markdown before conversion.

### 8.2 Delivery request and job

`RequestReportDelivery` appends a delivery record and dispatches `SendReportDeliveryJob`. The job atomically claims the record, sends through an application-owned `ReportDeliveryTransport`, persists the returned Symfony/Mailgun provider message ID where available, and records normalized failure. Bind the Laravel adapter in production and a fake adapter in tests; never require real Mailgun credentials for correlation coverage.

### 8.3 Mailgun webhook

Create a dedicated controller and signature-verification service. Persist/process only required event data. Tests prove:

- Invalid signatures are rejected.
- Repeated events are idempotent.
- Out-of-order events do not incorrectly regress terminal state.
- Unknown message IDs are handled safely and observably.

### 8.4 Admin send/resend

Individual and bulk actions always append delivery attempts. UI distinguishes requested, accepted, delivered, failed, bounced, and complained outcomes.

## 12. Phase 9 — Reconciliation and operations

Create commands such as:

- `analyses:dispatch-pending`
- `analyses:recover-stale` — atomically requeues heartbeat-stale `processing` and backoff-eligible `failed` rows while `attempt_count` is below a configured limit, incrementing `recovery_count` only for the winner.
- `reports:dispatch-unsent`
- `reports:recover-stale` — atomically requeues only stale `sending` or bounded/backoff-eligible `failed` delivery records; successful/terminal states are not redispatched.
- `submissions:mark-abandoned`

Register schedules in `routes/console.php` with overlap prevention where appropriate. Each command and each job claim must be safe when two processes race; recovery reuses existing rows and must not create duplicate analyses/deliveries.

Operational tests simulate:

- Completed submission with no automatic analysis.
- Queued analysis whose dispatch was lost.
- Stale processing analysis.
- Completed automatic analysis with no delivery request.
- Existing successful delivery that must not resend.

Document production requirements:

```text
* * * * * php artisan schedule:run
php artisan queue:work --queue=default,ai,mail
```

Exact process supervision and queue backend are deployment decisions to record when selected.

## 13. Phase 10 — Analytics, settings, and polish

### 10.1 Funnel analytics

Provide quiz/revision-filtered counts and conversion rates for starts, questionnaire completions, completed leads, analyses, and delivery outcomes. Avoid storing redundant booleans unless profiling proves a need.

### 10.2 Settings UI

Implement:

- Ordered provider/model chains separately for quiz and report generation.
- Cheap connection and structured-output tests with redacted failures.
- Design token editor and additional CSS.
- Administrator-only static thank-you HTML with a server-side structural allowlist; it must not evaluate Blade/PHP/JavaScript or interpolate respondent data.
- Email templates and preview.
- Prompt templates/version labels.
- Resume, retention, retry, timeout, and spam settings.

Completion requirement: settings are not merely persisted. Keep `ApplicationSettings` as the closed validation boundary and feature-test each runtime consumer: `ai.quiz` and `ai.report` chains, prompt snapshots, controlled email renderer, public design CSS/tokens, Turnstile/analysis mode, resume/retention, and recovery/lease timeout. Reject secrets, unknown nested fields, executable template syntax, and unsafe CSS.

### 10.2.1 Operational administration completion

Submission resource/edit views must provide authorized individual and bulk reanalysis/generate-and-send/latest-send, spam/hold, anonymization, and safe export. Provide concrete analysis retry/cancel/preferred-selection and delivery retry/cancel/history paths. All operations append history or lifecycle rows; never overwrite frozen answer/input/report/delivery snapshots. Test both authenticated and denied routes/actions, export field allowlisting, and anonymization preservation of protected history.

### 10.3 Accessibility and responsive QA

Verify keyboard navigation, focus movement on page changes, labels/errors, contrast, touch targets, mobile layout, reduced motion, and content-only page semantics.

## 14. Phase 11 — Release hardening

- Add authorization policies for every resource/action.
- Add production database and queue integration tests.
- Add backup/restore and retention documentation.
- Add security headers and CSP compatible with Filament and public quiz pages.
- Perform dependency audit and static analysis.
- Exercise Mailgun sandbox/webhooks.
- Exercise primary-provider failure and fallback success.
- Load test progress saving and finalization idempotency.
- Verify logs redact secrets and minimize PII.
- Run complete acceptance criteria from `PRD.md` section 20.

## 15. Testing matrix

### Unit

- Definition parser/schema versioning.
- Page compiler.
- Condition evaluator.
- Publish validator.
- Prompt builder/untrusted-data envelope.
- Provider-chain builder.
- Report schema validation and renderer mapping.
- Lifecycle transition policies.

### Feature

- Quiz admin CRUD and publishing.
- Password unlock.
- Start/resume/expiry behavior.
- Multi-question page saves.
- Conditional requiredness.
- Contact finalization and duplicate requests.
- Automatic and manual analysis creation.
- Queue job completion/failure/cancellation.
- Delivery and resend logs.
- Mailgun webhook verification/idempotency.
- Scheduled recovery.
- Authorization and rate limiting.

### Browser/end-to-end

- Build and publish a quiz.
- Complete and resume a quiz on desktop/mobile sizes.
- Complete contact capture and observe report state.
- Admin reanalysis and resend.
- Content-only pages and skipped conditional pages.

## 16. Definition of done for each slice

- Behavior has a focused test that was observed failing first.
- Minimal implementation passes focused and full tests.
- Relevant authorization, validation, idempotency, and security cases are covered.
- `PRD.md` normative content and Change Log are updated for any changed decision.
- This plan is updated if task ordering or architecture changed.
- Pint passes.
- Migrations can run from a clean database and roll back where practical.
- No secrets or generated local state are committed.
- Operational behavior is exercised rather than merely described.

## 17. MVP implementation status (2026-08-25)

Implemented as a cohesive baseline: Phase 1 schema/models/enums/factories; Phase 2 linear page compiler, structured condition evaluator, definition validator; immutable publish action; public start/resume, questionnaire/contact finalization, and automatic-analysis invariant; provider-safe AI contracts/job boundary; Mailgun signature/idempotency boundary; and scheduler commands for queued analyses and expired submissions. Review repair scopes automatic uniqueness to each submission/analysis in clean migrations, technically prevents published-revision mutation/deletion, and adds a fakeable mail transport that persists provider message IDs for signed webhook correlation.

Cycle-4 repair tightens the V1 publish boundary with regression tests for missing labels/content, choice option completeness/uniqueness, nested logical groups, earlier-only dependencies, typed operands, and invalid page breaks. It persists execution lease/generation fencing on analyses and deliveries; jobs renew and conditionally complete only their owned generation while recovery invalidates stale owners before redispatch. Operational Settings is now an authenticated persistent non-secret configuration surface for separated quiz/report provider chains, prompt/version labels, report email templates, design overrides, spam policy, and resume/retention/retry/timeout policy; provider credentials remain environment-only. Quiz duplication produces a detached editable draft, and AI draft generation is fakeable, validates generated V1 JSON, treats the brief as untrusted, and never publishes. The signed Mailgun webhook is narrowly CSRF-exempt while retaining signature validation. Submission attribution is implemented with immediate first touch, latest touch, technically append-only context snapshots/events, and Filament inspection/filtering. Query capture is attribution-only: an explicit UTM/click-ID/campaign allowlist drops all non-attribution keys recursively, preventing answers, contact data, cookies, sessions, form payloads, and secrets from entering context snapshots. The V1 definition validator is closed to unsupported persisted keys and enforces declared field types and bounds.

Cycle-10 makes the persisted settings surface operational: strict non-secret structured validation feeds AI chains, prompt snapshots, escaped report templates, public safe design CSS/tokens, spam policy, resume/retention, recovery limits, and lease timeout. The Submission resource now exposes operational actions and bulk actions; management services preserve frozen history while appending analysis/delivery/event records. Focused tests cover setting consumers, hostile setting values, scheduler/runtime policy, and authorized operational service/history behavior. Independent review remains required; external provider activation still needs deployment credentials.

Cycle-11 makes quiz-definition generation audit-safe: `quiz_draft_generations` appends a credential-free request record before provider invocation with a hashed sanitized brief/result, captured `ai.quiz` chain, `prompts.quiz_version`, and complete composed quiz prompt. The generator receives those captured values rather than rereading settings, and draft JSON remains only validated V1 definition content. Model guards distinguish frozen payload/history writes from lifecycle transitions: questionnaire completion can freeze answers, query-fenced queue/recovery work remains operational, live PII anonymization remains permitted, and an accepted delivery may transition via signed webhook to a terminal outcome without content mutation. Regression coverage proves request-time snapshot isolation, audit immutability, prohibited direct payload/deletion writes, and allowed delivery transition. Independent review remains required.

## 18. Recommended first implementation sequence

1. Enums and core migrations.
2. Models, factories, constraints, and lifecycle tests.
3. Quiz definition DTOs and publish validator.
4. Page compiler and condition evaluator.
5. Quiz Filament resource and builder.
6. Immutable publish action and preview.
7. Public start/resume flow.
8. Livewire page runner and progress saves.
9. Questionnaire completion and contact finalization.
10. Automatic analysis invariant and generation pipeline.
11. Report renderer and Mailgun deliveries.
12. Reconciliation commands.
13. Manual/bulk administration.
14. AI quiz helper.
15. Settings, analytics, accessibility, and release hardening.

This ordering proves the core frozen-revision and submission workflow before adding costly external AI/email integrations.
