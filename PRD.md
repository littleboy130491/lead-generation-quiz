# Lead Generation Quiz Platform — Product Requirements Document

**Status:** Initial approved baseline
**Product phase:** Scaffold / pre-MVP implementation
**Last updated:** 2026-08-26
**Authority:** This document is the source of truth for product behavior and architecture.

## Document governance

Every product, behavior, architecture, data-model, workflow, dependency, configuration, security, UI, or operational change must update this document in the same change set.

A valid update must:

1. Modify the relevant normative section below so the current truth remains easy to find.
2. Add a dated entry to the Change Log describing the change and rationale.
3. Update `docs/IMPLEMENTATION_PLAN.md` when the delivery sequence or technical plan is affected.

Updating only the Change Log is not sufficient. Implementations that contradict this PRD are defects unless the PRD is updated deliberately.

---

## 1. Product summary

The product enables businesses to create interactive lead-generation quizzes that diagnose a respondent's problems or needs. Respondents move through page-based questions and informative content, provide an email after completing the questionnaire, and receive a professional AI-generated analysis by email.

Administrators use Filament to build and publish quizzes, review submissions, generate additional analyses, and send or resend reports. The system preserves historical accuracy through immutable published quiz revisions and append-only analysis and delivery records.

## 2. Product objectives

1. Convert quiz engagement into qualified email leads.
2. Give respondents useful, tailored, professional reports rather than generic result pages.
3. Let non-technical administrators build quizzes manually or with AI assistance.
4. Preserve exactly what each respondent saw and answered.
5. Make slow or unreliable AI and email operations observable, retryable, and auditable.
6. Protect AI spend and system integrity from spam, prompt injection, and duplicate processing.

## 3. Users

### 3.1 Administrator — MVP

An authenticated operator who can:

- Create, edit, preview, publish, archive, and duplicate quizzes.
- Use AI to draft a quiz definition for review.
- Configure quiz behavior, design, report instructions, and email presentation.
- View submission funnels and individual answer snapshots.
- Generate, cancel, retry, and inspect analyses.
- Send and resend reports individually or in bulk.
- Inspect every report-delivery attempt and provider event.
- Configure non-secret AI provider/model priority and application settings.

Filament Shield roles for this operator:

- `super_admin`: unrestricted administrator. It is a strict superset of `admin` and can access every administrator-only surface that `admin` can, including Branding & design.
- `admin`: full administrator for quizzes, submissions, users, and settings, including Branding & design.
- `quiz_manager`: quiz operations only.
- `submission_manager`: submission operations only.

Administrator-only UI, including Branding & design and its thank-you HTML field, is available to `super_admin` and `admin`. Role checks must not treat `admin` as higher than `super_admin`.

### 3.2 Respondent — MVP

An anonymous visitor who can:

- Open a published quiz by unique slug.
- Unlock a password-protected quiz when applicable.
- Answer visible questions page by page.
- Read decorative or informative content.
- Leave and resume later in the same browser.
- Complete the questionnaire, then provide at least an email address.
- Receive the completed report by email.

### 3.3 Respondent account holder — later version

A respondent who authenticates passwordlessly by an email OTP and can view quiz history, frozen answers, and analysis reports. This is explicitly out of MVP scope but the current submission model should not prevent it.

## 4. MVP scope

### Included

- Quiz CRUD with unique slug and `draft`, `published`, and `archived` states.
- Optional simple password protection using a password hash.
- JSON block builder with explicit page breaks.
- Multiple questions and content blocks per page.
- Single choice, multiple choice, yes/no, short text, and long text questions.
- Stable block, question, and option identifiers.
- Required/optional questions.
- Conditional block visibility.
- Decorative/informative Markdown content and Curator-managed media.
- Draft preview and immutable published revisions.
- Server-side progress saving and encrypted opaque resume cookie.
- Post-questionnaire email capture.
- Frozen submission and answer snapshots.
- Exactly one initial automatic analysis request for each accepted completed submission.
- Multiple additional manual analyses without replacing earlier results.
- Structured AI report output rendered through application-owned templates.
- Ordered AI provider/model failover configured by an administrator.
- Queued AI generation with scheduler reconciliation and retry handling.
- Mailgun report delivery.
- Append-only delivery attempt log and Mailgun webhook updates.
- Individual and bulk reanalysis and delivery actions.
- Rate limits, honeypot, Turnstile, validation, and basic spam controls.
- Configurable design tokens, additional CSS, email templates, and AI prompts.
- A server-to-server Bearer-token API for AI-assisted quiz-draft creation and optional immutable publication.
- A server-to-server Bearer-token API for provisioning administrator panel users with allowlisted Shield roles.
- Filament Shield role-based administration, user-role assignment, and filtered CSV/XLSX table exports for quizzes and submissions.

### Deferred

- Respondent account dashboard and email OTP authentication.
- Team workspaces and role/permission system beyond initial administration.
- CRM integrations, outbound webhooks, and embed widgets.
- PDF reports.
- Multi-language quizzes.
- A/B testing and scoring formulas beyond AI interpretation.
- Arbitrary administrator-supplied frontend JavaScript.
- AI tools, browsing, or cross-submission retrieval during report generation.

## 5. Core respondent journey

1. A respondent visits `/{quiz-slug}`.
2. The application resolves the quiz's active published revision.
3. If password-protected, the respondent unlocks the quiz for a time-bounded session.
4. The application creates or resumes an `in_progress` submission using an opaque cookie token.
5. The respondent navigates through pages separated by explicit page-break blocks.
6. Progress is validated and saved server-side on page navigation; the cookie stores no answers or PII.
7. Conditional visibility is evaluated in the browser for responsiveness and repeated on the server for authority.
8. After the last questionnaire page passes validation, the submission becomes `awaiting_contact` and `questionnaire_completed_at` is set.
9. The respondent sees a dedicated email-capture page and provides at least a valid email address. Name, company, and phone appear only when the quiz's corresponding lead-capture settings are enabled; disabled fields are omitted from the form and ignored if submitted.
10. Anti-spam checks run before acceptance.
11. An accepted submission becomes `completed` and receives exactly one initial automatic analysis record.
12. A generation job processes the analysis using the configured provider/model failover chain.
13. A successful structured result is stored and rendered through a controlled report template.
14. An automatic delivery record is created and sent through Mailgun.
15. Mailgun webhook events update the delivery lifecycle.

## 6. Quiz composition and pagination

A quiz revision stores an ordered linear `blocks` array. Supported MVP block types are:

- `question`
- `content`
- `page_break`

The renderer compiles the linear sequence into pages. Blocks before the first break form page one; each break closes the current page and starts another. Empty leading, trailing, or consecutive page breaks are invalid at publish time.

A page may contain:

- One or several questions.
- Content plus questions.
- Only informational/decorative content.

After conditional visibility evaluation, a page with no visible renderable blocks is skipped automatically. A required question is required only when visible under the server's evaluation.

### 6.1 Question types

MVP types:

- `single_choice` — radio control; one selected option
- `multiple_choice` — checkbox group; zero or more selected options (required means at least one when the question is visible)
- `yes_no`
- `short_text`
- `long_text`

Each question defines:

- Stable string ID.
- Type.
- Label and optional help text.
- Required flag.
- Validation constraints appropriate to its type.
- Options for choice-based types.
- Optional structured visibility expression.
- Optional presentation settings, including an optional public `image_url` (http/https, ≤2,048 characters) and/or a plain-text `icon` (emoji or short label, ≤32 characters, no HTML/markup). When both are present the public runner shows the image and may also show the icon; neither field is required.

Each choice option defines a stable ID, machine value, display label, optional integer `score`, and optional explanatory text or media. Yes/no questions may optionally define integer `yes_score` and `no_score`. Text questions do not contribute to scoring. Scores are optional; when omitted they contribute zero.

### 6.2 Results

Each quiz chooses a revision-frozen result mode via `result.mode`:

- `ai` (default): after contact capture the platform queues automatic AI report analysis subject to spam/`analysis_mode` policy. Predetermined score bands are not used.
- `score`: results are predetermined score bands. Automatic AI analysis is not queued for that revision. At least one `score_results` band is required.

`score_results` is an ordered list of predetermined outcome bands keyed by total score. Each band permits only stable `id`, display `title`, integer `min_score`, integer `max_score` (`min_score` ≤ `max_score`), and optional static `html` (same allowlist and limits as opening/thank-you HTML). Band IDs are unique. Inclusive score ranges must not overlap. Unsupported keys are rejected. When `result.mode` is `ai`, `score_results` must be omitted.

At questionnaire completion in `score` mode the server sums scores for visible answered choice and yes/no questions from the frozen revision (selected multiple-choice options sum), matches the first band whose inclusive range contains the total, and stores a non-secret scoring snapshot on the submission metadata. Matched result HTML is sanitized at render time and never evaluates Blade, PHP, JavaScript, or respondent data.

### 6.2.1 Thank-you page

Thank-you presentation is revision-frozen under optional `thank_you` with `enabled` (boolean) and optional override `html` (same static allowlist as Branding thank-you HTML, ≤40,000 characters). Blank override HTML means the global Branding & design thank-you HTML is used.

Rules:

- In `ai` mode the thank-you page is always enabled after completion.
- In `score` mode administrators may disable the thank-you page. When disabled, the completion surface shows the matched predetermined result instead of the thank-you HTML.
- When enabled, completion renders quiz override HTML if present, otherwise the global Branding thank-you HTML.

### 6.3 Opening page

A quiz may optionally define a revision-frozen opening page on the definition root. When present, opening permits only `html`, optional `start_button_label`, and optional boolean `hide_start_button`. Opening HTML is administrator-authored static markup (maximum 40,000 characters) rendered through the same server-side structural allowlist as Branding thank-you HTML: it never evaluates Blade, PHP, or JavaScript and never interpolates respondent data. Unsupported keys and executable markup patterns are rejected at publish validation.

Public behavior:

- When `hide_start_button` is false or omitted, the opening is a gated intro shown before questionnaire pages. The start button uses `start_button_label` (nonblank ≤200 characters; default `Start quiz`). Dismissing the opening is recorded on the in-progress submission metadata so resume continues into the questionnaire; Back from the first questionnaire page returns to the opening.
- When `hide_start_button` is true, the sanitized opening HTML renders above the first questionnaire page with no start button, so the first visible questions appear directly below the opening.
- When opening is omitted or has no HTML, the questionnaire begins as today.

### 6.4 Content blocks

Content blocks may contain sanitized Markdown, Curator media references, layout metadata, and a continue-button label when used as an informational intermission. Raw executable PHP, Blade, or JavaScript is prohibited.

### 6.5 Conditions

Conditions are structured data, never executable code. MVP logical groups support `all` and `any`. MVP operators should include:

- `equals`
- `not_equals`
- `in`
- `not_in`
- `contains`
- `empty`
- `not_empty`
- `greater_than`
- `less_than`

Conditions may reference only earlier questions in the effective flow. Publish validation rejects missing references, invalid values, incompatible operators, and circular/impossible dependency structures. Version-1 definitions are a closed persisted contract: the root permits only `schema_version`, optional `opening`, optional `result`, optional `score_results`, optional `thank_you`, and an ordered `blocks` list; `result` permits only `mode` (`ai`|`score`) and, for AI mode only, optional bounded `system_prompt`; `thank_you` permits only optional boolean `enabled` and optional `html`; opening permits only `html`, optional `start_button_label`, and optional boolean `hide_start_button`; score-result bands permit only `id`, `title`, `min_score`, `max_score`, and optional `html`; a question permits only `id`, `type`, `question_type`, `label`, nullable `help`, optional boolean `required`, text-only bounded integer `max_length`, `options`, optional integer `yes_score`/`no_score` for yes/no questions, optional `image_url`, optional `icon`, optional boolean `exclude_from_ai` (default false: include in AI context), and nullable `visibility`; content permits only `id`, `type`, `markdown`, nullable `continue_label`, and nullable `visibility`; and page breaks permit only `id` and `type`. Options permit only stable `id`, machine `value`, display `label`, and optional integer `score`. Stable IDs are 1–100 ASCII alphanumeric/underscore/hyphen characters beginning alphanumerically. Labels are nonblank ≤500 characters, help ≤2,000, content Markdown ≤10,000, continue labels ≤200, opening/score-result HTML ≤40,000, start button labels ≤200, titles ≤500, icons ≤32, image URLs ≤2,048 http/https only, choice options are ordered lists of 1–50, score-result bands are ordered lists of 1–50 when present, integer scores are −10,000–10,000, `short_text.max_length` is 1–1,000, and `long_text.max_length` is 1–10,000. Unsupported keys, non-boolean `required`/`hide_start_button`, non-list blocks/options/bands, and unsupported persisted presentation/media/script fields are rejected. Condition groups are recursive nonempty `all`/`any` objects, leaves have only `question_id`, `operator`, and compatible operands, and the editor serializes that contract rather than executable expressions.

## 7. Quiz lifecycle and revisioning

`Quiz` is the durable identity and administrative container. The editable draft definition belongs to the quiz. Publishing copies a validated draft into a new immutable `QuizRevision` and makes it active.

- A published revision must never be edited in place. The model/domain layer rejects updates to its identity or payload (`quiz_id`, version, definition, prompt snapshot, publisher, and publication time) and rejects deletion; `PublishQuizRevision` is the controlled creation path.
- New respondents use the active revision.
- In-progress respondents continue on the revision they started.
- Existing submissions continue to reference their original revision.
- Published revisions cannot be hard-deleted while referenced.
- Archiving prevents new starts but does not invalidate historical data.

## 8. Resume and progress behavior

The resume cookie contains only a cryptographically random opaque token. The database stores only its hash.

The cookie must be encrypted and configured with `Secure`, `HttpOnly`, and `SameSite=Lax` in production. The server record contains current page, answers, timestamps, revision relationship, and expiry.

Rules:

- Progress is saved at minimum on Next and Back navigation when answers changed.
- A configurable debounced text autosave may be added without changing the authoritative page-save behavior.
- The server computes the frozen-revision visible current page and accepts answer keys only from that page's visible question IDs. It rejects every unknown, hidden, off-page, or stale answer key before changing answers, status, current page, activity/touch context, events, or analysis state; a content-only page therefore accepts only an empty answer map.
- The server validates accepted answer values against the frozen revision.
- A returning browser resumes the most recent valid page for the original revision.
- A completed submission cannot be modified through the resume token.
- Default resume duration is 30 days; default abandoned-attempt retention is 90 days, both configurable.
- Clearing cookies loses automatic resume but does not delete server records.
- Cross-device resume is deferred until respondent authentication exists.

## 9. Email capture and consent

Email is collected after questionnaire completion on a dedicated contact page. At least a valid email is required before the submission becomes completed and analysis begins.

Optional quiz configuration may request name, company, or phone. Consent to receive the requested report and marketing consent are separate concepts. Marketing consent must never be preselected or inferred solely from requesting a report.

If an administrator corrects a delivery address later, the originally submitted address remains in the immutable submission snapshot or audit history, while a new delivery records its actual recipient.

## 10. Data model

### 10.1 `quizzes`

Key fields:

- `id`
- `name`
- `slug` unique
- `description` nullable
- `status`
- `draft_definition` JSON
- `active_revision_id` nullable
- `password_hash` nullable
- `settings` JSON
- `created_by`
- timestamps and soft deletion where appropriate

### 10.2 `quiz_draft_generations`

Every administrator AI-draft request appends one auditable record before provider invocation. It stores the quiz relationship; a SHA-256 hash of the sanitized brief (not the brief payload); the request-time ordered non-secret `ai.quiz` provider/model chain; `prompts.quiz_version`; the complete composed system-prompt snapshot (application safety instruction plus `prompts.quiz_template`); request/completion/failure timestamps; normalized non-secret failure metadata; and a SHA-256 hash of a validated result. It never stores provider credentials and is append-only: request-snapshot fields cannot be changed or deleted after creation. The editable `quizzes.draft_definition` stores only validated V1 quiz JSON and never embeds hidden AI/audit metadata.

### 10.3 `quiz_revisions`

Key fields:

- `id`
- `quiz_id`
- `version`
- `definition` JSON
- `report_prompt_snapshot` nullable
- `published_by`
- `published_at`
- timestamps

Unique: `(quiz_id, version)`.

### 10.4 `submissions`

Key fields:

- `id`
- public UUID
- `quiz_id`
- `quiz_revision_id`
- `resume_token_hash` nullable, unique where present
- `status`
- `email` nullable
- `name`, `company`, `phone` nullable
- `answers_snapshot` JSON
- optional `quiz_snapshot` JSON for maximum archival durability
- `current_page`
- `metadata` JSON
- `preferred_analysis_id` nullable
- `started_at`
- `last_activity_at`
- `questionnaire_completed_at` nullable
- `completed_at` nullable
- `expires_at` nullable
- timestamps

The answer snapshot stores stable IDs plus human-readable question and answer labels so historical reports remain interpretable independently of current draft content.

### 10.4.1 Submission attribution and event timeline

The first public quiz start creates a submission immediately with a public UUID, immutable `first_touch_context`, and an append-only `started` event. Context captures bounded landing URL/path, structured sanitized query parameters, sanitized referrer, request IP, user-agent, and conservative application-owned browser/device/platform classification. Sensitive-looking query keys (including token, secret, password, authorization, cookie, session, signature, and API-key variants) are redacted; answer/contact values and cookies are never copied into context or events.

Every meaningful public resume, progress save, questionnaire completion, and contact completion records a separate `submission_events` row with a deep immutable copy of the persisted `latest_touch_context` at record time and updates `latest_touch_context`. First-touch attribution is never overwritten: a later UTM/referrer (for example Facebook after Google Ads) remains a later event/latest touch. Analysis and delivery request/completion/failure lifecycle events are appended when they occur. Request IP must use Laravel's trusted-proxy-aware request resolution; production deployers must configure trusted proxies correctly.

### 10.5 `analyses`

Key fields:

- `id`
- public UUID
- `submission_id`
- `sequence`
- `status`
- `trigger`: `automatic`, `manual`, `bulk_manual`, or `retry`
- `created_manually`
- `requested_by` nullable
- requested provider chain JSON
- actual provider/model nullable
- `failover_occurred`
- provider attempts JSON with normalized errors
- prompt version and system-prompt snapshot
- input snapshot JSON
- structured result JSON nullable
- rendered report nullable
- normalized error code/message nullable
- attempt and recovery counters
- persisted `execution_generation`, opaque execution lease token, and lease expiry for fenced ownership
- `queued_at`, `started_at`, `heartbeat_at`, `completed_at`, `cancelled_at`
- timestamps

Only one initial `automatic` analysis may exist per submission. The nullable automatic marker is unique only as `(submission_id, automatic_key)`, so the shared initial marker cannot suppress another submission's automatic analysis. Manual reanalysis always appends another record with a null marker.

### 10.6 `report_deliveries`

Key fields:

- `id`
- `analysis_id`
- `submission_id`
- recipient email
- `status`
- `trigger`: `automatic`, `manual`, or `bulk_manual`
- `sent_manually`
- `requested_by` nullable
- provider name and message ID
- template identifier/version
- subject, HTML, and text snapshots
- normalized error fields
- persisted `execution_generation`, opaque execution lease token, and lease expiry for fenced ownership
- `queued_at`, `sent_at`, `delivered_at`, `failed_at`
- timestamps

Every send or resend appends a delivery record. Only one automatic delivery may exist per analysis: the nullable automatic marker is unique as `(analysis_id, automatic_key)`, while manual resend rows have a null marker. Delivery request/content fields are immutable from creation; terminal delivery rows cannot be deleted or rewritten. A matching signed webhook may perform only the valid lifecycle transition from `accepted` to `delivered`, `bounced`, `complained`, or `failed` and write its matching outcome timestamp; it cannot alter delivery content/history. Provider webhook events update the matching record idempotently.

### 10.7 Settings and templates

Application settings are a closed, validated non-secret key/value contract: `ai.quiz`, `ai.report`, `prompts`, `report.email`, `design`, `spam`, `operations`, and `notifications`. The Filament Operational settings page edits `ai.quiz`, `ai.report`, `prompts`, `spam`, `operations`, and `notifications` through structured fields (ordered provider/model repeaters, prompt inputs, Turnstile/analysis-mode controls, numeric resume/retention/retry/timeout fields, and a multi-value admin notification email list). It never accepts raw JSON, secrets, or provider credentials. Dedicated Branding & design and Report email templates pages edit public design and email presentation. Runtime consumers remain: quiz/report chains select SDK candidates; prompt templates/version labels are snapshotted with generated work; email subject/templates are substituted through a fixed escaped-placeholder renderer; validated design tokens/CSS are applied to public quiz pages; spam controls govern Turnstile and automatic-analysis policy; operations governs resume expiry/cookies, retention token scrubbing, recovery attempt limits, and job lease timeout; and `notifications.submission_emails` queues one administrator notice per configured address when a submission newly becomes `completed`. Provider credentials remain environment-backed Laravel configuration. Unknown keys, secret-like fields, executable template syntax, unsafe CSS, and unsafe token values are rejected.

## 11. Submission and analysis invariants

- Incomplete submissions have no automatic analysis.
- An accepted `completed` submission must eventually have exactly one initial automatic analysis.
- The submission-finalization transaction creates the automatic analysis or leaves reconciliation enough state to do so idempotently.
- An analysis result is never overwritten by reanalysis.
- `has_analysis` is not authoritative; existence/count queries or a carefully maintained counter are used.
- A preferred analysis may be selected explicitly; otherwise the latest completed analysis is used where a single report must be chosen.
- Cancellation is explicit and audited.

## 12. AI architecture

Use the official Laravel AI SDK behind application-owned interfaces:

- `QuizDefinitionGenerator`
- `QuizAnalysisGenerator`

Concrete Laravel AI implementations translate application settings into SDK calls and translate SDK failures into normalized domain outcomes.

### 12.1 Quiz-definition AI

The administrator supplies audience, objective, business context, desired insight, question count, and tone. Operational settings default `prompts.quiz_template` to the application-owned conversion-strategy instruction, which administrators may tailor. At the moment of each request, the application composes and snapshots that template with immutable safety instructions and an appended V1 output contract derived from the supported `QuizDefinitionValidator` fields: only a JSON object, required `schema_version`/ordered `blocks`, supported block/question types, option/score/visibility constraints, and no executable content. Administrators cannot remove the appended contract. The application snapshots the persisted `ai.quiz` chain and complete prompt with `prompts.quiz_version`, creates its append-only audit record, then invokes only those snapshots. Settings changes after request cannot alter that invocation. AI returns a structured proposed definition. The application validates schema, identifiers, options, page breaks, and conditions. The proposal remains a draft until an administrator reviews, previews, and publishes it; audit data is not embedded in the definition.

When no `ai.quiz` chain entry has a matching environment provider key, quiz-definition generation does **not** fail closed. The same `QuizDefinitionGenerator` path falls back to an application-owned structural scaffold built from the sanitized brief (stable IDs, supported question types, opening copy, `result.mode=ai`). Brief fields remain untrusted plain text and are never treated as instructions. The Generate AI draft header action remains available on quiz create and edit; when credentials are missing it explains that a structural scaffold will be used and still allows Confirm. Programmatic generation (`POST /api/v1/quizzes/generate` and `GenerateQuizDraft`) likewise returns a validated scaffold instead of `ai_unavailable`. Missing quiz AI credentials therefore must not block draft creation for administrators or server-to-server agents. Report/analysis generation is unchanged: it still raises `GenerationException` with `ai_unavailable` when the report chain has no usable credentials. When a quiz chain has usable credentials but every provider attempt fails, quiz generation raises `ai_generation_failed` (not the credential-missing path).

### 12.1a AI quiz discovery interview

Before creating a quiz draft, an administrator can open **AI quiz discovery** in the admin panel. The interview persists append-only user/assistant messages and a mutable, reviewed structured brief owned by the administrator. It asks one focused question at a time for missing business context, target audience, objective, and desired respondent insight; the administrator can edit the resulting brief directly. The interview uses the configured quiz AI provider/model chain when credentials are available and safely falls back to the same deterministic guided questions otherwise. Raw chat is untrusted reference material and is never supplied to `GenerateQuizDraft`; only the reviewed allowlisted brief is passed to generation after explicit confirmation. Each session snapshots the configurable discovery system prompt; generation retains its own immutable quiz-definition prompt/audit contract.

### 12.1a Server-to-server quiz-generation API

`POST /api/v1/quizzes/generate` is a narrow server-to-server interface over the same draft-generation and optional publication actions. It accepts only allowlisted quiz metadata plus the documented structured brief; it does not accept arbitrary prompts, raw definitions, credentials, or frontend code. Authentication is a single environment-only `QUIZ_GENERATION_API_TOKEN` Bearer secret, compared in constant time and required even when the value is absent (fail closed). The route is rate-limited to 20 requests/minute. `publish: false` returns an editable draft; `publish: true` creates a new immutable active revision. When no usable quiz AI credentials exist, the endpoint still succeeds with a validated structural scaffold from the brief. Failed provider attempts (credentials present but every attempt failed) retain their draft and append-only audit row for authorized inspection, return only normalized non-secret errors, and are never silently deleted. The comprehensive request/response, error, authentication, rotation, and operational contract is normative in `docs/QUIZ_GENERATION_API.md`.

### 12.1b Server-to-server user provisioning API

`POST /api/v1/users` creates an administrator panel user with one or more allowlisted Filament Shield roles (`super_admin`, `admin`, `quiz_manager`, `submission_manager`). It accepts only name, email, password, roles, and optional `email_verified`; it never accepts arbitrary permissions, secrets beyond the password being hashed, or panel configuration. Authentication uses the same environment-only `QUIZ_GENERATION_API_TOKEN` Bearer secret and fail-closed constant-time comparison as the quiz-generation API, and shares the 20 requests/minute throttle. Passwords are hashed before storage and never returned. Roles must already exist from `AdminRoleSeeder`. The comprehensive contract is normative in `docs/USER_PROVISIONING_API.md`.

### 12.2 Analysis AI

The analysis agent receives one frozen quiz revision and one frozen answer snapshot, after omitting questions marked `exclude_from_ai` (and their answers) from AI context. By default every question is included. Operational settings default `prompts.report_template` to an evidence-based business-advisor instruction that requires grounded, calibrated, practical guidance and complete report fields. The composed analysis system prompt is the fixed report-schema safety instruction plus either the quiz `result.system_prompt` override (when nonblank in AI mode) or this global template. Templates may use `{{questions_and_answers}}` for included Q&A only. Quiz overrides may also use `{{question.<id>}}` and `{{answer.<id>}}` for IDs in the revision; excluded IDs resolve empty. Substituted values are wrapped as untrusted prompt data. Unknown placeholders are rejected at settings save or publish validation. Analysis `input_snapshot` stores the filtered revision/answers sent to the model; the submission still retains the full answer snapshot. It has no tools, browsing, cross-submission retrieval, or conversation memory. It returns structured report data such as:

- Executive summary
- Profile
- Strengths
- Challenges and severity
- Prioritized recommendations
- Next steps or a 30-day plan
- Disclaimer

Application Blade templates produce report HTML/text from validated structured fields. Model-generated executable content or unsanitized HTML is never rendered.

### 12.3 Provider failover

Quiz generation and report generation have independent ordered provider/model chains. Runtime database settings select from providers already enabled in `config/ai.php` via a Filament select; credentials remain in environment variables. Model identifiers allow safe OpenRouter-style namespace/model syntax (including `/`), while retaining a bounded allowlist of identifier characters. A **Custom (OpenAI-compatible)** option stores provider `openai-compatible` with a required non-secret `endpoint_url` on the chain entry; the matching API key remains environment-only (`OPENAI_COMPATIBLE_API_KEY`).

Each analysis records the requested chain, each normalized attempt, actual completing provider/model, and whether failover occurred. Failover is intended for eligible transient/provider conditions such as rate limits, overload, unavailability, or insufficient credits, not invalid application requests.

### 12.4 Prompt-injection defense

- Respondent answers are explicitly labeled and delimited as untrusted data.
- System instructions state that instructions inside respondent data must be ignored.
- Structured output schemas are mandatory.
- Inputs are minimized to data required for that report.
- Secrets and other submissions are excluded.
- Output is schema-validated, escaped, and rendered by controlled templates.
- Suspicious instruction-like text may be flagged, but filtering is supplemental rather than the primary defense.

## 13. Queue and scheduler design

Laravel queues are the primary executor. The scheduler is the recovery and reconciliation layer.

Primary pipeline:

1. Finalize submission and create queued automatic analysis atomically.
2. Dispatch `GenerateAnalysisJob` after commit.
3. Atomically claim analysis and mark it processing.
4. Invoke the provider failover chain.
5. Store completed structured output or normalized failure.
6. Create/dispatch automatic delivery according to policy.
7. Send through Mailgun and update the delivery record.
8. Consume verified Mailgun webhooks.

Scheduled reconciliation runs at least every minute and:

- Dispatches eligible queued analyses missing a live job.
- Requeues a `processing` analysis only when its `heartbeat_at` is absent or older than the configured stale threshold. The atomic status claim increments `recovery_count` and dispatches only the winning claimant.
- Requeues a `failed` analysis only after configured backoff and while `attempt_count` remains below the configured maximum. It never appends a replacement analysis for automatic recovery.
- Ensures completed automatic analyses have an automatic delivery request.
- Requeues only stale `sending` deliveries or backoff-eligible failed deliveries below their configured attempt limit. `accepted`, `delivered`, `bounced`, and `complained` records are terminal and are never redispatched by recovery.
- Marks expired in-progress submissions abandoned.
- Prunes operational records according to retention policy without deleting required audit history.

All job claims and reconciliation transitions use conditional atomic updates plus a persisted opaque lease and monotonically increasing execution generation. A worker may renew only its own lease and may complete/fail only while its lease and generation still match; recovery clears/advances the fence before dispatching one winner. This prevents stale workers from clobbering recovered state or invoking a provider after losing ownership. Recovery cannot make a non-idempotent third-party call exactly once, so provider-level idempotency remains a production integration requirement.

## 14. Email delivery

Mailgun is accessed through Laravel Mail and the Symfony Mailgun transport. Email templates support subject, HTML/Markdown content, text fallback, branding, and structured report fields. A dedicated application-owned delivery-transport boundary sends the mailable and returns the Symfony transport message ID when available; the send job persists that ID before webhook correlation. The Mailgun Symfony transport supplies its API response ID through this boundary in the production path, while tests bind a fake transport and require no provider credentials.

Mailgun webhook processing must:

- Be reachable without a browser CSRF token only at `POST /webhooks/mailgun`; its Mailgun HMAC signature remains mandatory and is verified before reconciliation.
- Verify signatures.
- Match the provider message ID.
- Be idempotent.
- Record accepted, delivered, failed, bounced, complained, and unsubscribe-related outcomes as applicable.
- Avoid logging sensitive payload content unnecessarily.

Automatic send eligibility in MVP is: completed automatic analysis with no successful automatic delivery request. Manual analyses are not sent unless the admin explicitly requests delivery.

## 15. Spam, abuse, and security

MVP controls:

- Separate rate limits for quiz start, progress save, final questionnaire submission, contact capture, and password attempts.
- Honeypot.
- Cloudflare Turnstile before final acceptance.
- Server-side visibility, requiredness, option-membership, and payload validation, including a pre-write exact allowlist of frozen visible current-page question IDs; content-only page saves require an empty answer map.
- Minimum plausible completion-time signals.
- Maximum free-text and payload lengths.
- Normalized email validation.
- Duplicate-submission risk signal.
- Per-quiz, per-IP, per-email, and global AI-generation budgets/emergency limits.
- Hashed quiz passwords and resume tokens.
- Secure cookie settings.
- Escaped/sanitized content rendering.
- Verified Mailgun webhooks.
- Authorization on all Filament actions and bulk actions.
- No arbitrary custom JavaScript in MVP.

Current MVP policy is `analysis_mode = always` for every submission accepted as completed. Malformed, rate-limited, Turnstile-failed, or otherwise rejected requests never become completed submissions and therefore do not consume analysis credits.

Future policy modes are reserved as:

- `always`
- `manual`
- `eligible_only`

## 16. Admin experience

### Quiz resource

- Overview and lifecycle state.
- The quiz create/edit form is split into tabs: **Settings** (identity, access/lead capture, opening), **Quiz** (block builder; questions may exclude themselves from AI context), **Result** (AI vs predetermined score mode, optional AI system prompt override, and score-result builder), and **Thank you** (enabled flag for score mode, optional HTML override of the global Branding thank-you). Unchecked lead-capture fields are not shown on the public contact form. Password input is hashed on save and blanked on edit.
- Create and edit quiz pages are a single full-width column so the builder uses the available panel width rather than Filament's default two-column, 7xl-capped form.
- The ordered JSON builder exposes only `question`, sanitized-Markdown `content`, and `page_break` blocks. Question blocks provide stable ID, type, label, help, requiredness, optional image URL/icon, repeatable choice options with optional scores, optional yes/no scores, and structured visibility fields; content provides stable ID, Markdown, continue label, and structured visibility fields. Optional score-result bands map total scores to predetermined titles/HTML. It intentionally has no arbitrary script field.
- Block builder with drag-and-drop questions, content, and page breaks; builder items are collapsible so long drafts stay scannable.
- Conditions editor.
- Design and CSS configuration.
- Report and email configuration.
- Draft preview: quiz edit header Preview opens the live public slug in a new tab. Authenticated panel users can open draft quizzes at that URL with a sticky draft banner; guests still receive 404. Draft live preview is session-only (no submissions) and ends on a draft-complete screen instead of contact capture.
- Publish action creating a revision.
- Revision history.
- Submission and funnel analytics.
- AI draft-generation action on quiz create and edit. The header action is always visible. When no `ai.quiz` chain entry has usable environment credentials, the modal explains that and disables Confirm; it does not hide the action.

### Submission resource

- Submissions are created only by the public quiz flow. The admin Submission resource does not offer a create action or create page.
- Quiz/revision, email, timestamps, status, analysis count/state, and delivery state.
- Frozen questions & answers viewer.
- First/latest attribution context, device/browser fields, filters, and append-only event timeline viewer; this operational UI must not expose raw cookies, secrets, or answer/contact data beyond the existing authorized frozen viewer.
- Individual generate, generate-and-send, send-latest, mark-spam, hold-review, export, and anonymize actions.
- Bulk reanalysis, generate-and-send, send-latest, spam, hold-review, anonymize, and export actions.
- Analysis/delivery history is visible from the frozen submission record. Analysis actions append manual work, support retry/cancel/preferred selection, and delivery actions append retries/resends or record cancellation without overwriting historical attempts. Anonymization removes current direct contact/resume access only; frozen answers, analysis/delivery history, and append-only events remain intact. Exports are authorized and contain only the explicitly safe operational fields listed by the export action.

### Analysis manager

- Sequence, status, trigger, requester, provider/model, failover, timestamps, errors, structured output, and delivery history.
- Cancel, retry, generate-another, choose-preferred, and send actions.

### Settings

- Branding & design and Report email templates are single full-width columns (`Width::Full`, form `columns(1)`) so fields use the available panel width rather than Filament's default two-column, 7xl-capped form.
- Structured design tokens and additional CSS on Branding & design.
- Email templates on Report email templates.
- Operational settings as a Filament form: ordered quiz/report provider/model repeaters; AI system prompts for quiz creation (`prompts.quiz_template`) and analysis results (`prompts.report_template`, optional `{{questions_and_answers}}`) with version labels; Turnstile/analysis mode; resume/retention/retry/timeout numeric fields; and one or more admin notification email addresses for completed submissions. Administrators do not edit these as raw JSON.
- Separate system prompts/provider chains for quiz creation and report generation.

Provider keys are not displayed or stored in ordinary settings.

## 17. Design system

Structured theme tokens are the primary customization mechanism:

- Brand colors and semantic colors.
- Heading/body typography.
- Radius and shape.
- Content width and spacing.
- Button and progress styles.
- Optional advanced additional CSS.

Tailwind-facing CSS custom properties should translate stored tokens into the respondent experience. Arbitrary additional JavaScript is deferred because of XSS and data-exfiltration risk.

## 18. Analytics

MVP metrics should distinguish:

- Quiz views/starts where reliably measurable.
- In-progress attempts.
- Questionnaire completions (`awaiting_contact`).
- Completed submissions with email.
- Analysis queued/completed/failed.
- Email accepted/delivered/failed/bounced.

Historical submissions remain attributed to their original revision. Funnel reporting should support quiz and revision filters.

## 19. Non-functional requirements

- Queue-bound external calls must not block respondent requests.
- Finalization, automatic analysis creation, and delivery creation must be idempotent.
- Operational failures must be visible in Filament with normalized errors.
- Historical quiz context, answers, analyses, and delivery attempts must remain auditable.
- Public endpoints must be responsive and accessible by keyboard.
- Pages must work on mobile and desktop.
- Tests must cover condition evaluation, page compilation, revision immutability, resume security, finalization invariants, AI schema validation, reconciliation, and webhook idempotency.
- Logs must exclude secrets and minimize PII.

## 20. Success criteria for MVP

MVP is acceptable when an administrator can:

1. Build and preview a multi-page quiz containing multiple questions per page and content-only breaks.
2. Publish an immutable revision at a unique slug with optional password protection.
3. Start the quiz anonymously, leave, and resume in the same browser without answers being stored in the cookie.
4. Complete all visible required questions and provide an email afterward.
5. Observe one automatic queued analysis and receive a validated professional report through Mailgun.
6. Trigger a second analysis without replacing the first.
7. Resend a report and inspect each separate delivery attempt.
8. Configure a provider/model fallback chain without exposing credentials.
9. Recover a simulated stale analysis through the scheduler without duplicate analysis or delivery records.
10. Reject manipulated, invalid, or spam-gated final submissions before AI generation.

## 21. Open implementation decisions

The following are not yet product changes and should be resolved during implementation with corresponding PRD updates:

- Production relational database engine.
- Production queue driver and whether Laravel Horizon is adopted.
- Exact Turnstile package versus a small direct integration.
- Whether full `quiz_snapshot` is stored in addition to immutable `quiz_revision_id`.
- Exact report schema fields and email visual design.
- Whether administrator roles are needed before initial release.
- Precise data-retention and anonymization policies for production jurisdiction.

## 22. MVP implementation decisions

The initial executable MVP stores lifecycle values as backed PHP enums and uses database uniqueness for quiz slugs, revision versions, resume-token hashes, and automatic analysis triggers. A `QuizRevision` is created only through the publish action, which validates linear JSON blocks and page-break placement in a transaction before switching the active revision.

The public implementation stores a SHA-256 hash of a random resume token server-side; the raw token is sent only in an encrypted, HTTP-only, SameSite=Lax cookie. The current-page progress endpoint is the only public questionnaire mutation path: it validates unknown IDs, server-side visibility/current page, question types, option values, and text bounds against the frozen revision before it can invoke questionnaire completion and contact finalization. Contact finalization normalizes email, invokes an application-owned Turnstile verifier, locks the submission, and atomically appends a single queued automatic analysis. The direct Cloudflare verifier is enabled only when its environment secret exists; the null verifier accepts local/test requests unless `TURNSTILE_REQUIRED=true`, in which case missing verification rejects finalization.

Report generation uses an application-owned Laravel AI SDK adapter and versioned structured schema (`executive_summary`, `profile`, strengths, challenges, recommendations, action plan, disclaimer). The prompt builder delimits frozen revision/answer data as untrusted, directs the model to ignore embedded instructions, and excludes tools, secrets, and unrelated submissions. Runtime chains contain provider/model names only; adapters skip providers without configured credentials, normalize failures, are container-bound through the `QuizAnalysisGenerator` contract, and are replaceable in tests. No external provider is called by credential-free test paths.

Report rendering uses controlled Blade HTML/text templates and escaped structured fields. `RequestReportDelivery` appends delivery attempts; its automatic key is unique only within its analysis while manual resend paths always append. Send jobs claim queued records, use an application-owned Laravel Mail transport adapter, persist its provider message ID when available, and normalize failures; `reports:dispatch-unsent` reconciles queued records without resending accepted/delivered records. Mailgun webhook handling remains credential-gated: it HMAC-verifies the timestamp/token signature, looks up only that persisted provider message ID, and does not regress terminal delivery states.

The public respondent runner is a server-authoritative Blade flow. It compiles the frozen linear revision on each render, filters blocks with the condition evaluator, and skips pages left empty by filtering. When a gated opening is present, the runner shows sanitized opening HTML and the configured start label before questionnaire pages and records dismissal on submission metadata; when hide-start-button is set, opening HTML renders above the first page. Required questionnaire questions, the contact email field, and the unlock password field show a visible `*` marker beside their labels. It validates typed values only for questions on the current server-tracked page; Back and Next persist answers and `current_page` server-side, then a completed questionnaire redirects to the dedicated contact page. The obsolete direct questionnaire-completion POST is intentionally not routed, so it cannot supply arbitrary answer snapshots outside the current-page validator. The encrypted opaque resume cookie contains only the resume token. Password unlock state is time-bounded in Laravel session state, while protected submission routes require session ownership or the matching resume token. Quiz Markdown is rendered through a controlled renderer that strips raw HTML, and reserved public-route slugs plus start, unlock, progress, and contact limits are enforced server-side. All public questionnaire, unlock, contact-capture, and completion pages share one non-executable quiz layout and stylesheet: branding tokens (logo, site name, eyebrow, colors, radius, additional CSS/JS) apply on every step; the shell uses atmospheric background layers, distinctive display/body type, staged entrance motion (honoring `prefers-reduced-motion`), large interactive option tiles, and a visible progress treatment so the respondent experience feels kinetic rather than a static form. Validated design settings may still provide only the existing constrained token/CSS overrides—no arbitrary executable frontend beyond the trusted additional JavaScript field. The idempotent `LeadGenerationQuizSeeder` supplies a published `business-readiness-check` example with an immutable active revision for local/demo verification; it never rewrites an existing published revision.

The administrator-only Branding & design settings page is available to `super_admin` and `admin`. It includes a database-backed static completion/thank-you HTML field. The completion view renders only a server-sanitized, fixed allowlist of structural and text HTML (headings, paragraphs, lists, emphasis, links, images, divs, and spans). It never evaluates stored content as Blade/PHP/JavaScript and never interpolates respondent data into it. Scripts, styles, forms, embedded content, event handlers, inline styles, unsafe URLs, and unrecognized elements/attributes are removed at render time.

## 23. Change Log

### 2026-08-26 — Provider select with custom endpoint URL

- Operational settings AI chain rows use a Provider select from `config/ai.php`. Choosing Custom (OpenAI-compatible) reveals a required Endpoint URL field stored on the chain entry; API keys remain environment-only.

### 2026-08-26 — Admin email notifications for completed submissions

- Operational settings stores `notifications.submission_emails` as a validated list of one or more administrator addresses (empty disables notices).
- When a submission newly becomes `completed`, the application queues one notification email per configured address with quiz name, lead email, and an admin link. Idempotent re-finalization does not re-notify.

### 2026-08-26 — AI quiz discovery interview

- Added authenticated **AI quiz discovery** at `/admin/quiz-discovery`: a persisted, append-only interview transcript with a directly editable reviewed brief. The AI adapter uses the configured quiz chain with a schema-constrained response when credentials exist and falls back to focused deterministic questions otherwise. Only the reviewed allowlisted brief can create a quiz draft.
- Operational settings now provides a versioned **Quiz discovery interview system prompt**, snapshotted for each session.

### 2026-08-26 — Default analysis-result prompt

- Added and persisted the default **Analysis result system prompt**: an evidence-based business-advisor instruction that requires grounded, calibrated, practical reports with complete structured fields. It remains editable in Operational settings while the existing report-schema safety envelope stays application-owned.

### 2026-08-26 — Default quiz-generation prompt and immutable output contract

- Added a default conversion-strategy **Quiz creation system prompt** for Operational settings. `GenerateQuizDraft` now composes the saved administrator template with non-removable safety instructions and a V1 quiz-definition output contract derived from `QuizDefinitionValidator`; the full prompt is snapshotted before every AI invocation and audit record.

### 2026-08-26 — Live quiz Preview for drafts

- Quiz edit header includes a Preview action that opens the public quiz URL in a new tab.
- Authenticated panel users can live-preview draft quizzes at the public slug with a sticky draft banner; guests still get 404. Draft preview uses session state only and never creates submissions.
- Quiz list Preview opens the same live URL.

### 2026-08-26 — OpenRouter model identifier validation

- Operational settings and the closed `ApplicationSettings` validation boundary accept safe slash-delimited provider/model identifiers required by OpenRouter (for example, `deepseek/deepseek-v4-flash-0731`), while preserving the 120-character bound and restricted identifier character allowlist.

### 2026-08-26 — Engaging public quiz respondent UI

- Rebuilt the shared public quiz shell for questionnaire, unlock, contact, and completion: branding tokens apply on every step; atmospheric layers, distinctive typography, staged motion (with reduced-motion respect), larger option tiles, and clearer progress replace the flat purple/Inter card form. Behavior and server authority are unchanged.

### 2026-08-26 — Quiz draft scaffold when AI credentials are missing

- Missing quiz AI provider credentials no longer block draft creation. `QuizDefinitionGenerator` falls back to a validated structural V1 scaffold from the sanitized brief for both the admin Generate AI draft action and `POST /api/v1/quizzes/generate`. Report/analysis generation still requires usable report-chain credentials. Provider failures after credentials are present still surface as `ai_generation_failed`.

### 2026-08-26 — Publish Livewire assets for Filament login

- Documented and automated `php artisan livewire:publish --assets` so Filament/Livewire JS is served as static files under `public/vendor/livewire` instead of the default PHP asset route (which commonly 500s behind Nginx or subdirectory mounts). Composer post-autoload-dump/post-update republish the assets; setup docs and deploy checklists include the step.

### 2026-08-26 — Demo quiz seeder matches current V1 schema

- `LeadGenerationQuizSeeder` now publishes a definition with `opening`, `result.mode=ai`, scored choice/yes-no fields, checkbox multi-select, and an `exclude_from_ai` question. Unpublished drafts are refreshed on re-seed; existing active revisions stay immutable. `DatabaseSeeder` creates an `admin@example.test` admin role user before the demo quiz.

### 2026-08-26 — Complete setup guide and Curator media prerequisites

- Added `docs/SETUP.md` covering clone through `composer`/`npm`, `.env`, `key:generate`, migrate, `storage:link`, `curator:token`, role seeding, admin bootstrap, AI provider env + Operational settings chains, and production checklist.
- README quick start now requires Curator token and storage link; points to the full setup guide. Admin settings docs document AI credential/URL/model configuration.

### 2026-08-26 — Server-to-server user provisioning API

- Added `POST /api/v1/users` for programmatic administrator user creation with allowlisted Shield roles, sharing the existing fail-closed Bearer token and throttle with quiz generation. Documented in `docs/USER_PROVISIONING_API.md`.

### 2026-08-26 — AI context exclusion and prompt variables

- Questions may set `exclude_from_ai` so they and their answers are omitted from analysis context (default: include all).
- Global Analysis result system prompt supports `{{questions_and_answers}}` only.
- Optional per-quiz `result.system_prompt` (AI mode) overrides the global template and may also use `{{question.ID}}` / `{{answer.ID}}`.

### 2026-08-26 — Generate AI draft header always visible

- Generate AI draft is shown on quiz create and edit whether or not AI credentials exist. Confirm stays disabled without usable provider keys; the brief form remains available so administrators can see the action.

### 2026-08-26 — Admin cannot create submissions

- Hidden the Filament Submission create action and removed the unused create page. Submissions remain created only through the public quiz flow.

### 2026-08-26 — Contact form shows only enabled lead-capture fields

- Public contact capture always requires email. Name, company, and phone render only when the matching quiz setting is enabled. Server-side finalization ignores those fields when the setting is off, even if they are posted.

### 2026-08-26 — Generate AI draft modal when credentials are missing

- Superseded by the 2026-08-26 scaffold fallback: missing quiz credentials no longer disable Confirm or fail programmatic quiz draft generation. Report generation still raises `ai_unavailable` without report-chain credentials.

### 2026-08-26 — Operational settings expose named AI system prompts

- Operational settings labels the persisted `prompts.quiz_template` and `prompts.report_template` fields as **Quiz creation system prompt** and **Analysis result system prompt**, with version labels and helper text describing quiz-draft vs analysis snapshotting.

### 2026-08-26 — Quiz editor tabs, result modes, and thank-you overrides

- Quiz create/edit uses Settings / Quiz / Result / Thank you tabs.
- `result.mode` chooses AI analysis vs predetermined score bands; score mode requires a score-result builder and skips automatic AI analysis.
- Thank-you is always on for AI results; score mode may disable it. Optional per-quiz thank-you HTML overrides the global Branding thank-you when enabled.

### 2026-08-26 — Checkbox multi-answer questions clarified in the builder

- The existing `multiple_choice` question type is labeled **Checkbox (multiple answers)** in the quiz builder. It already renders a public checkbox group that accepts multiple selected options and stores an answer list.

### 2026-08-26 — Required public fields show an asterisk

- Required questionnaire question labels, the contact email label, and the unlock password label display a visible `*` marker.

### 2026-08-26 — Tidy submission viewer layout

- The admin Submission edit form is now a one-column, full-width layout and renders the frozen snapshot as a readable “Frozen questions & answers” view derived from the revision definition + `answers_snapshot` (instead of showing raw revision/answers blobs as-is).

### 2026-08-26 — Quiz builder blocks are collapsible

- Filament quiz builder items (`question`, `content`, `page_break`) are collapsible so administrators can collapse blocks while reordering drafts.

### 2026-08-26 — Questions may include an optional image or icon

- Question blocks accept optional `image_url` (http/https) and optional plain-text `icon` (emoji or short label). Both are revision-frozen, validated at publish, and rendered on the public questionnaire.

### 2026-08-26 — Optional answer scoring and predetermined score results

- Choice options may include an optional integer `score`; yes/no questions may include optional `yes_score`/`no_score`.
- Definitions may include optional non-overlapping `score_results` bands (`id`, `title`, `min_score`, `max_score`, optional `html`).
- Questionnaire completion sums visible scored answers, matches a band when configured, and stores a scoring snapshot on submission metadata for contact/admin use.

### 2026-08-26 — Optional quiz opening page with start button or inline questions

- Version-1 definitions may include an optional revision-frozen `opening` object (`html`, `start_button_label`, `hide_start_button`) edited on the quiz form.
- Opening HTML uses the same static allowlist sanitizer as thank-you HTML. With the start button visible, the opening gates the questionnaire until dismissed; with the button hidden, opening HTML appears above the first questionnaire page.

### 2026-08-26 — Branding and report email settings use a full-width single column

- Branding & design and Report email templates now use Filament `Width::Full` and a one-column form so identity, theme, thank-you HTML, sender, and template fields stack at the available panel width instead of the default two-column 7xl layout.

### 2026-08-26 — Quiz create and edit use a full-width single column

- Create Quiz and Edit Quiz now use Filament `Width::Full` and a one-column form so name, slug, access settings, and the block builder stack at the available panel width instead of the default two-column 7xl layout.

### 2026-08-26 — Operational settings use Filament fields

- Replaced the Operational settings JSON textareas with a Filament form: ordered quiz/report provider-chain repeaters, prompt version/template fields, Turnstile toggle and analysis-mode select, and numeric resume/retention/retry/timeout inputs.
- Save goes through the existing `ApplicationSettings` closed validation boundary. Provider credentials remain environment-only. Branding/CSS and report email templates stay on their dedicated pages.

### 2026-08-26 — Super admin is a strict superset of admin

- Filament Shield `super_admin` is now an unrestricted administrator role: a strict superset of `admin`. It can access every administrator-only surface that `admin` can, including Branding & design.
- Idempotent role seeding now creates `super_admin` with every permission assigned to `admin`.
- Administrator-only authorization uses an `isAdministrator()` check that accepts `super_admin` or `admin`, so `hasRole('admin')` can no longer hide settings from a super admin.

### 2026-08-25 — Cycle-11 audit-safe quiz AI snapshots and lifecycle-compatible immutability

- Added append-only `quiz_draft_generations` audit records before quiz-AI provider invocation. Each records only non-secret request-time provider/model chain, prompt version/full composed prompt, brief/result hashes, outcome/timestamps, and bounded failure metadata; the editable draft keeps only validated V1 definition JSON. Quiz draft generation now invokes its explicitly captured chain/prompt so later settings changes cannot affect an in-flight/requested result.
- Tightened frozen submission, analysis, and delivery payload guards while preserving lifecycle execution: questionnaire completion may freeze its answers, queue/recovery claims remain query-fenced, anonymization may erase live PII, and signed webhook reconciliation may move an accepted delivery to a valid terminal outcome without changing its content/history. Focused regressions cover prohibited direct writes/deletions and the allowed accepted-to-delivered transition.
- Bound `QuizAnalysisGenerator` to the Laravel AI implementation in the application container and added a container-resolution regression, so queued automatic/manual analysis jobs can resolve the required generator without a test-only manual injection.
- Added idempotent `LeadGenerationQuizSeeder` and its regression coverage. It creates and publishes the safe `business-readiness-check` example only when no active revision exists, preserving any existing published history.
- Added the shared public quiz stylesheet to the questionnaire, unlock, contact-capture, and completion views, with regression coverage that the contact step includes the stylesheet.
- Preserved the HTTPS scheme through the Cloudflare/Nginx FastCGI boundary so Filament/Livewire generates an HTTPS update endpoint under the public subdirectory rather than a browser-blocked HTTP request.
- Corrected the Filament quiz-builder schema to flatten reusable visibility components rather than passing a nested array; an authenticated edit-route regression now protects existing authored quizzes and Livewire builder actions.
- Added the versioned, rate-limited, server-to-server quiz-generation API with fail-closed environment Bearer-token authentication, allowlisted request validation, immutable generation auditing, optional publication, normalized provider errors, regression tests, and comprehensive operational reference documentation.
- Added Spatie Laravel Settings and Filament settings pages for public-quiz branding/design (logo, colors, CSS, trusted JavaScript) and report email templates; settings are database-backed, migrated, rendered at runtime, and documented as non-secret administrator controls.
- Added a true-administrator-only static thank-you-page HTML setting. Its output is sanitized through a strict server-side allowlist and cannot execute Blade, PHP, JavaScript, forms, embedded content, inline event/style attributes, or unsafe URLs.
- Added Filament Shield/Spatie Permission, Shield-generated policies, a User resource with role assignment, and idempotent `super_admin`, `admin`, `quiz_manager`, and `submission_manager` role seeding. `super_admin` is a strict superset of `admin`. Added Filament Excel filtered/selected quiz and submission exports with administrator-selected CSV/XLSX output.

### 2026-08-25 — Cycle-10 runtime settings and operational workflows

- Made persistent settings executable rather than decorative: report finalization snapshots the persisted report provider chain and prompt label/instruction; controlled delivery rendering uses the persisted escaped subject/template placeholders; public quiz responses apply validated design tokens/CSS; spam policy governs Turnstile and automatic-analysis mode; and operations settings govern resume lifetime/cookie age, retention token scrubbing, recovery bounds, and execution lease timeout.
- Closed the `ApplicationSetting` model/repository boundary to approved structured non-secret keys and safe values. Email templates prohibit PHP/Blade/script syntax and accept only fixed placeholders; design settings prohibit executable/external CSS constructs.
- Added append-only administrative workflows and actual submission UI actions for hold/spam/anonymize, individual/bulk analysis and generate-and-send, latest resend, retry/cancel/preferred selection services, delivery cancellation/retry services, safe CSV export, and history inspection. Current anonymization deliberately retains protected frozen snapshots and audit records while removing direct contact/resume identifiers.

### 2026-08-25 — Cycle-9 administration and inbound webhook completion

- Exempted only the signed `webhooks/mailgun` inbound endpoint from browser CSRF verification, while retaining mandatory Mailgun HMAC verification. A real-middleware feature test proves a signed provider-style POST without a session CSRF token reaches idempotent delivery reconciliation.
- Added safe draft duplication and an AI-assisted draft-generation action. Draft generation uses an application-owned, fakeable `QuizDefinitionGenerator`, treats the administrator brief as delimited untrusted input, validates generated V1 JSON, and can update only a mutable draft—not a published revision.
- Replaced read-only operational inspection with persistent, authenticated non-secret application settings for separated quiz/report provider chains, prompt/version labels, report templates, design overrides, spam policy, and resume/retention/retry/timeout policy. Credentials remain environment-only and secret-like setting keys are rejected.

### 2026-08-25 — Cycle-7 public validation and event-context repair

- Removed the redundant public direct questionnaire-completion POST. Questionnaire state can now mutate only through the current-page server validator, preventing arbitrary, unknown, invisible, mistyped, invalid-option, or overlong answer payloads from bypassing frozen-revision validation; hostile endpoint regression coverage also proves rejected requests leave status, answers, context, and events unchanged.
- Corrected submission-event context capture to read and deep-snapshot persisted `latest_touch_context` at event record time. Google first touch remains immutable while Facebook resume, progress, completion, and analysis-request events retain Facebook snapshots.

### 2026-08-25 — Cycle-4 validation and fenced recovery repair

- Defined and enforced the full persisted schema-version 1 quiz-definition contract: required/bounded labels and content, identifier and option uniqueness, valid choice options, page-break placement, and recursive `all`/`any` conditions with earlier-question-only compatible typed operands. The Quiz builder now stores structured nested condition groups instead of a single raw leaf rule.
- Added persisted execution generation/lease/expiry fields to analyses and deliveries. Queue claims, lease renewal, completion/failure updates, and stale recovery are fence-conditional so an original recovered worker cannot invoke the provider after losing its lease or overwrite the new owner's state. External providers still require their own idempotency feature for true cross-process exactly-once effects.
- Replaced the framework README with setup, configuration, admin bootstrap, queue/scheduler, production safety, verification, and credential-free external-integration limitations.

### 2026-08-25 — Submission attribution, context, and event tracking

- Added immediate UUID-backed start records, immutable first-touch and latest-touch request context, conservative local user-agent classification, secret-query redaction/bounds, and trusted-proxy-aware IP capture. First touch remains unchanged across later campaign/referrer resumes.
- Added append-only submission event records for public start/resume/progress/completion and analysis/delivery lifecycle transitions, each retaining its context snapshot.
- Added Filament submission attribution/timeline inspection and device/browser/status filtering, focused Google-first/Facebook-resume completion and redaction regression coverage, and operational privacy guidance.

### 2026-08-25 — Attribution privacy, audit immutability, and closed V1 repair

- Replaced generic query-string persistence with a strict attribution-only allowlist: documented UTM fields and supported advertising/campaign click identifiers. All non-attribution keys, including nested answer, contact, form, cookie, session, and secret-like data, are dropped rather than retained or merely masked; referrer query strings follow the same rule.
- Enforced submission-event append-only behavior in the model: event rows have a narrow creation-only mass-assignment surface and reject persisted updates or deletion.
- Closed the persisted schema-version 1 quiz-definition contract by rejecting unknown keys and enforcing field types, identifier formats, bounded collection sizes, and applicable string/numeric limits. Regression tests cover malformed/extra data as well as valid builder output.

### 2026-08-25 — Cycle-8 current-page answer-key authority repair

- Hardened the sole public page-save mutation path so it derives allowed answer IDs from the frozen, server-evaluated visible current page and rejects every unknown/off-page/hidden key before any answer, status, page, activity/touch, event, completion, or analysis write. Content-only pages remain valid only with an empty answer map.
- Added public-flow regression coverage proving a valid visible answer submitted alongside an unknown key is rejected without mutating the submission or creating analysis work.

### 2026-08-25 — Cycle-3 builder and async recovery completion

- Replaced the empty Quiz Filament schema with a draft-default CRUD builder for name, unique/reserved-safe slug, hash-only password protection, non-secret lead settings, and ordered question/content/page-break JSON blocks. The builder has repeatable options and structured visibility fields, transforms to the current schema-versioned validator contract, and exposes a transactional publish action without any arbitrary script field.
- Added `analyses:recover-stale` and `reports:recover-stale`, scheduled every minute with overlap prevention. They atomically requeue stale/eligible failed work only within configured backoff and attempt bounds; job claims are conditional, recovery reuses existing rows, and accepted/delivered/terminal deliveries are never redispatched.
- Added credential-free feature coverage for builder-to-publish-to-public multi-page flow, password non-disclosure, stale processing recovery, failed retry limits, delivery recovery, and repeated-reconciliation no-duplicate behavior.

### 2026-08-25 — Review-blocker data integrity and Mailgun correlation repair

- Scoped automatic analysis uniqueness to `(submission_id, automatic_key)` and automatic delivery uniqueness to `(analysis_id, automatic_key)` in the clean bootstrap migrations, preserving one initial record per parent while allowing independent submissions and analyses to use the `initial` marker.
- Enforced published `QuizRevision` immutability at the model layer for payload/identity updates and deletion, with publishing retained as the controlled revision-creation path.
- Added an application-owned report-delivery transport boundary. The Laravel/Symfony adapter returns and persists the Mailgun transport message ID when available; credential-free tests use a fake adapter to prove signed webhook correlation, duplicate-event idempotency, and terminal-state non-regression.

### 2026-08-25 — Public respondent multi-page runner

- Replaced the unsafe one-page public form with a server-authoritative, page-oriented Blade runner that supports typed question controls, visible-page progress, Back/Next persistence, content-only intermissions, and conditional empty-page skipping.
- Added time-bounded password unlock sessions, protected submission-route ownership checks, controlled Markdown rendering, reserved public-slug protection, and separate named public endpoint rate limits.
- Added feature coverage for page grouping/visibility, typed validation and resume, password authorization, content/contact sequencing, and completion; resume cookies remain opaque-token-only.

### 2026-08-25 — AI, report delivery, and Turnstile MVP slice

- Added the credential-safe Laravel AI report adapter behind `QuizAnalysisGenerator`, schema validation, untrusted-data prompt delimiting, ordered provider/model handling, and normalized failure persistence.
- Added controlled Blade HTML/text report rendering, append-only request/send jobs, automatic-delivery uniqueness, Laravel Mail fake coverage, and scheduled `reports:dispatch-unsent` reconciliation.
- Added direct Cloudflare Turnstile and null-safe verifier implementations; production can require verification while local/test defaults remain credential-free.
- Added thin Filament publish, reanalysis, and resend actions that delegate only to application actions.
- External AI providers and Mailgun were not exercised in this credential-free change set.

### 2026-08-25 — MVP foundation implementation

- Implemented relational quiz, revision, submission, analysis, and append-only delivery schema with factories and backed lifecycle enums.
- Implemented JSON page compilation, structured condition evaluation, validation, immutable revision publishing, opaque resume tokens, questionnaire completion, and idempotent contact finalization.
- Added public quiz/contact routes and controlled templates, queue job boundary, generated Filament Quiz/Submission resources, signed Mailgun webhook boundary, and scheduled analysis/abandonment commands.
- Provider invocation and real Mailgun sending remain deliberately unexercised without credentials; contracts and queue boundaries keep them safe to configure later.

### 2026-08-25 — Initial baseline

- Established hybrid relational plus versioned-JSON quiz architecture.
- Added explicit page-break blocks so multiple questions can share a page.
- Established post-questionnaire email capture.
- Established server-side resumable progress using an opaque encrypted cookie token.
- Established immutable revisions and frozen submission snapshots.
- Established one automatic analysis per accepted completion, append-only manual reanalysis, and append-only report deliveries.
- Selected Laravel AI SDK with separately configurable provider/model failover chains for quiz generation and report generation.
- Selected Filament, Livewire, Tailwind, Curator, Laravel queues/scheduler, and Mailgun.
- Added prompt-injection and spam-defense requirements.
- Added mandatory PRD synchronization governance for every future change.
