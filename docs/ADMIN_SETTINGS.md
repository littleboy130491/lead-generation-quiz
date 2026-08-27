# Admin branding and email settings

The Filament admin panel provides database-backed Spatie Settings pages:

- **Branding & design** — `/admin/manage-branding-settings`
- **Report email templates** — `/admin/manage-report-email-settings`
- **Operational settings** — `/admin/operational-settings` (Filament form for quiz/report provider chains, AI system prompts for quiz creation and analysis results, Turnstile/analysis mode, resume/retention/retry/timeout policy, and admin submission notification emails; not JSON)

Branding & design and Report email templates use a single full-width column (`Width::Full`, form `columns(1)`).

## Branding & design

The settings apply to public quiz pages at runtime without a deploy:

- site label, eyebrow text, optional HTTPS logo URL;
- primary, secondary, background, and text colors;
- border radius;
- additional CSS (maximum 20,000 characters); and
- additional JavaScript (maximum 20,000 characters); and
- a static **Thank-you page HTML** field (maximum 40,000 characters).

Public questionnaire, unlock, contact, and completion pages share one respondent shell: atmospheric background, brand header, progress treatment, and interactive option tiles driven by these tokens.

CSS rejects HTML, `@import`, external `url()`, `javascript:`, and legacy CSS expression syntax. JavaScript is a **trusted administrator** capability and is placed only on public quiz pages. Never use it for secrets, credentials, analytics keys, payment logic, or untrusted respondent data. Do not include `<script>` or `<style>` tags; enter the JavaScript body only.

### Thank-you page HTML

This setting is available to users with the `super_admin` or `admin` role. `super_admin` is a strict superset of `admin`. It accepts static content HTML such as headings, paragraphs, lists, emphasis, links, images, divs, and spans. Content is sanitized immediately before it is displayed: scripts, styles, forms, iframes/embedded content, inline CSS, event attributes, unsafe URLs, and unsupported markup are removed. Stored HTML is **never** evaluated as Blade or PHP and has no placeholder support, so respondent data cannot be injected into the page.

## Report email templates

Settings include sender name, optional reply-to address, subject, HTML template, and text fallback. Report data is inserted through these placeholders only:

- `{{email}}`
- `{{report.executive_summary}}`
- `{{report.profile}}`
- `{{report.disclaimer}}`

Report values are escaped when rendered into HTML. Email templates should not contain PHP, Blade execution directives, JavaScript, or secrets.

## Operational settings — Admin submission notifications

Under **Operational settings → Admin submission notifications**, add one or more email addresses. When a respondent completes a submission (contact email accepted), each address receives a queued notification with the quiz name, lead email, and an admin link. Leave the list empty to disable notices. Addresses are validated, lowercased, and de-duplicated (maximum 20).

## Operational settings — AI system prompts

Under **Operational settings**, administrators configure:

- **Quiz creation system prompt** (`prompts.quiz_template`) — used when the AI interview or `POST /api/v1/quizzes/generate` creates a quiz draft. Combined with fixed draft-only safety instructions, the V1 structured-output contract, and snapshotted per request with `prompts.quiz_version`.
- **Quiz discovery interview system prompt** (`prompts.discovery_template`) — used by the guided AI interview. When the interview is complete or the administrator says to create the quiz now, generation uses the allowlisted brief and the quiz-creation prompt above.
- **Analysis result system prompt** (`prompts.report_template`) — used when generating AI analysis/report results for a submission. Combined with fixed report-schema safety instructions and snapshotted per analysis with `prompts.report_version`. Optional variable: `{{questions_and_answers}}` (all questions and answers except those marked **Exclude from AI context** on the quiz). Per-question `{{question.ID}}` / `{{answer.ID}}` are not allowed here; use a quiz **AI system prompt** override instead.

Provider credentials remain environment-only. Prompts are non-secret bounded text and must not contain PHP open tags.

## Operational settings — AI provider chains

Under **Operational settings**, ordered **Quiz AI provider chain** and **Report AI provider chain** repeaters use a **Provider** select (from `config/ai.php`) plus a **Model** field. Choose **Custom (OpenAI-compatible)** to reveal an **Endpoint URL** field for an OpenAI-compatible gateway. Credentials remain environment-only (`OPENAI_API_KEY`, `OPENAI_COMPATIBLE_API_KEY`, etc.).

### Environment (secrets and URLs)

Configure in `.env` (see `config/ai.php`), then run `php artisan optimize:clear`:

```dotenv
OPENAI_API_KEY=sk-...
# OPENAI_URL=https://api.openai.com/v1

ANTHROPIC_API_KEY=...
# ANTHROPIC_URL=https://api.anthropic.com/v1

GEMINI_API_KEY=...
# GEMINI_URL=https://generativelanguage.googleapis.com/v1beta/

OPENROUTER_API_KEY=...

OPENAI_COMPATIBLE_API_KEY=...
OPENAI_COMPATIBLE_URL=https://your-gateway.example/v1
```

The `provider` select must match a key under `config/ai.php` (for example `openai`, `anthropic`, `gemini`, `openrouter`). **Custom (OpenAI-compatible)** stores provider `openai-compatible` with a required endpoint URL and uses `OPENAI_COMPATIBLE_API_KEY` from the environment. Entries without a usable key (or custom entries without a URL) are skipped. If the quiz chain has no usable credentials, the AI quiz interview and `POST /api/v1/quizzes/generate` still produce a validated structural scaffold from the brief; only report/analysis generation requires usable report-chain credentials.

### Models

Enter the model id your account supports (for example `gpt-4.1` for OpenAI). Quiz chain drives the AI quiz interview and `POST /api/v1/quizzes/generate`; report chain drives submission analysis.

Full install path including Curator media token: [SETUP.md](SETUP.md).

### Quiz AI system prompt override

On the quiz **Result** tab (AI mode only), an optional **AI system prompt** overrides the global analysis template for that quiz’s revisions. It may use:

- `{{questions_and_answers}}`
- `{{question.<id>}}` — question label for a quiz question ID
- `{{answer.<id>}}` — respondent answer for that ID

Questions marked **Exclude from AI context** are omitted from variables and from the frozen AI input snapshot. Substituted values are treated as untrusted respondent data.

## Data and deployment

Settings are managed by `spatie/laravel-settings`, stored in the `settings` database table, and seeded by a versioned settings migration. They are separate from the existing application operational/AI settings, which retain their closed non-secret validation boundary.

Run after deploying code (see [SETUP.md](SETUP.md) for the full checklist):

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan curator:token
php artisan livewire:publish --assets
php artisan filament:assets
php artisan optimize:clear
```

Verify the pages and the settings migration:

```bash
php artisan route:list --path=admin/manage-
php artisan migrate:status
```

Media library uploads require a non-empty `CURATOR_GLIDE_TOKEN` (from `php artisan curator:token`) and `php artisan storage:link`. Filament login requires published Livewire assets (`public/vendor/livewire`); otherwise the default PHP-served Livewire JS route often returns 500 behind Nginx or a subdirectory mount.