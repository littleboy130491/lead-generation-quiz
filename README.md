# Lead Generation Quiz Platform

Laravel 13 / Filament 5 application for versioned lead-generation quizzes, resumable anonymous responses, queued AI analysis, and auditable report delivery.

| Document | Contents |
|---|---|
| [PRD.md](PRD.md) | Authoritative product and architecture specification |
| [docs/SETUP.md](docs/SETUP.md) | **Full install from clone through media, admin, AI, and production checklist** |
| [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) | Phased delivery plan |
| [docs/ADMIN_SETTINGS.md](docs/ADMIN_SETTINGS.md) | Branding, email templates, Operational settings, AI providers |
| [docs/QUIZ_GENERATION_API.md](docs/QUIZ_GENERATION_API.md) | Server-to-server quiz generation API |
| [docs/USER_PROVISIONING_API.md](docs/USER_PROVISIONING_API.md) | Server-to-server user provisioning API |

## Prerequisites

- PHP 8.3+ (`mbstring`, `openssl`, `pdo`, plus `gd` or `imagick` recommended for media)
- Composer 2
- Node.js 22+ and npm
- Git
- SQLite for local development, or another configured database

## Quick start

For the complete step-by-step (including `storage:link`, `curator:token`, roles, AI keys, and troubleshooting), follow **[docs/SETUP.md](docs/SETUP.md)**. Condensed local path:

```bash
git clone <repository-url> leadgenquiz
cd leadgenquiz

composer install
npm install

cp .env.example .env
php artisan key:generate

# Local SQLite (adjust DB_* in .env for MySQL/Postgres)
touch database/database.sqlite
php artisan migrate

php artisan db:seed --class=AdminRoleSeeder
php artisan storage:link
php artisan curator:token
php artisan livewire:publish --assets
php artisan filament:assets
php artisan optimize:clear

npm run build
php artisan serve
```

Then create an administrator (see [docs/SETUP.md](docs/SETUP.md#5-create-an-administrator)) and sign in at `/admin/login`.

**Media uploads require** `php artisan storage:link` and `php artisan curator:token` (sets `CURATOR_GLIDE_TOKEN` in `.env`). Skipping either causes Curator/Glide failures.

**Filament/Livewire JS:** publish static assets with `php artisan livewire:publish --assets` (and `filament:assets`). Without that, Livewire tries to serve JS through a PHP route that often returns 500 behind Nginx or a subdirectory mount. Composer `post-autoload-dump` / `post-update-cmd` also republish Livewire assets.

## Configuration highlights

- **Never** store provider API keys in admin settings. Put them in `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, …) and choose provider/model pairs under **Operational settings**. Details: [docs/SETUP.md](docs/SETUP.md#8-configure-ai-providers-optional-until-you-use-ai) and [docs/ADMIN_SETTINGS.md](docs/ADMIN_SETTINGS.md).
- Server-to-server APIs share `QUIZ_GENERATION_API_TOKEN`.
- Mailgun / Turnstile remain environment-backed and optional for local credential-free work.

Relevant non-secret operational variables include:

```dotenv
QUEUE_CONNECTION=database
QUIZ_RESUME_DAYS=30
QUIZ_UNLOCK_MINUTES=480
QUIZ_EXECUTION_LEASE_MINUTES=5
QUIZ_ANALYSIS_STALE_AFTER_MINUTES=15
QUIZ_ANALYSIS_MAX_ATTEMPTS=3
QUIZ_ANALYSIS_RETRY_BACKOFF_MINUTES=5
QUIZ_DELIVERY_STALE_AFTER_MINUTES=15
QUIZ_DELIVERY_MAX_ATTEMPTS=3
QUIZ_DELIVERY_RETRY_BACKOFF_MINUTES=5
TURNSTILE_REQUIRED=false
CURATOR_DEFAULT_DISK=public
```

### Attribution and privacy

Starting a public quiz immediately creates a UUID-backed submission and records first-touch context: landing URL, structured attribution parameters, referrer, request IP, user agent, and a conservative local browser/device/platform classification. Later meaningful requests update latest-touch context and append an immutable timeline event; first touch is never overwritten. Query capture uses a strict attribution allowlist (supported UTM, click-ID, and campaign fields); every other parameter—including nested answers, contact data, form payloads, resume cookies, sessions, and secret-looking keys—is dropped rather than stored.

`Request::ip()` honors Laravel trusted-proxy configuration. In production, configure the platform/load-balancer proxy addresses and forwarded-header behavior before relying on attribution IPs; do not trust arbitrary client-supplied forwarding headers. Administrators can inspect authorized first/latest context and timeline entries in the Submission resource. Set retention/anonymization policy according to the deployment jurisdiction before collecting production traffic.

## Daily operations

Queue workers perform AI generation and email sends; scheduler commands reconcile lost, stale, and bounded-retry work:

```bash
php artisan queue:work --queue=default,ai,mail --tries=1
php artisan schedule:work
# or cron every minute in production:
* * * * * cd /path/to/lead-generation-quiz && php artisan schedule:run >> /dev/null 2>&1
```

The recovery commands use persisted execution leases and monotonically increasing generations. Do not run a worker with a lease shorter than the expected provider call without configuring a suitable heartbeat/lease policy. A recovered job fences stale completion writes; external providers are not generally exactly-once, so production providers should also use their own supported idempotency mechanism where available.

Before production: use a supervised queue process, durable queue/database, TLS, a configured `APP_URL`, secure cookies, key rotation/backups, secret management, Mailgun webhook verification, monitoring for failed jobs and recovery counters, and retention/PII policies appropriate to your jurisdiction. Do not rely on `php artisan serve`, SQLite, or the database queue for high-volume production without an explicit capacity/backup decision. Full production checklist: [docs/SETUP.md](docs/SETUP.md#10-production-checklist-summary).

### Administrator workflows and persisted settings

Authenticated administrators can duplicate a quiz into an independent unpublished draft, create/edit/publish revisions, inspect histories, and generate an editable AI draft from a brief. Each AI-draft request appends a credential-free audit record containing request-time provider/model chain, prompt version/full prompt, brief/result hashes, and outcome metadata; it never inserts audit fields into the quiz definition, and later Operational Settings changes cannot alter the captured invocation. Submission operations include individual/bulk reanalysis and generate-and-send, latest resend, spam/hold, anonymization, and safe operational CSV export. Analysis/delivery history remains append-only: retries/resends append records, cancellation is recorded, an accepted delivery may advance by signed webhook to its terminal outcome without content rewriting, and anonymization removes current contact/resume identifiers without rewriting frozen answers or operational history.

The Operational Settings page is a Filament form over a closed non-secret configuration contract. It is live: quiz/report provider-chain repeaters drive new work; quiz-creation and analysis-result system prompts (plus version labels) are snapshotted with generated work; spam controls govern Turnstile/automatic analysis; and operations policy controls resume age, retention token scrubbing, recovery limits, and execution timeout. Report email templates and public design tokens/CSS are edited on their dedicated settings pages. Provider keys, webhook signing keys, and other credentials remain environment-only.

## Verification

```bash
composer validate --strict
php artisan migrate:fresh --env=testing
php artisan test
vendor/bin/pint --test
npm run build
php artisan route:list
```

## External integration limitation

The repository includes credential-safe AI and report-delivery boundaries plus fake-backed regression coverage. AI providers and Mailgun are **not** exercised by the test suite without configured credentials; complete sandbox/provider and webhook testing is a required deployment validation step.
