# Lead Generation Quiz Platform

Laravel 13 / Filament 5 application for versioned lead-generation quizzes, resumable anonymous responses, queued AI analysis, and auditable report delivery. Product behavior is defined by [PRD.md](PRD.md); implementation sequencing is in [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md); the server-to-server quiz generation contract is in [docs/QUIZ_GENERATION_API.md](docs/QUIZ_GENERATION_API.md).

## Prerequisites

- PHP 8.3+ with SQLite (local default), `mbstring`, `openssl`, and `pdo` extensions
- Composer 2
- Node.js 22+ and npm
- A supported production database/queue backend chosen and operated by the deployer

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Set DB_CONNECTION=sqlite and DB_DATABASE=/absolute/path/to/database/database.sqlite if needed.
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
```

Start the application with `php artisan serve`, then visit `/admin`.

### Bootstrap an administrator

This scaffold uses Laravel's `users` table. Create the first administrator in an interactive shell (choose a real, unique email and a strong password; do not put it in source control):

```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => \Illuminate\Support\Facades\Hash::make('replace-this-password')]);
```

Sign in at `/admin/login`. The password field in Quiz editing is transient: blank leaves the existing hash unchanged.

## Configuration

Copy `.env.example` and set ordinary Laravel values such as `APP_URL`, database, session, cache, queue, and mail configuration. Do **not** store provider keys in database settings or commit them.

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
```

Mailgun and AI credentials are environment-backed configuration only (for example `MAILGUN_*`, `MAILGUN_WEBHOOK_SIGNING_KEY`, and enabled AI-provider variables). A credential-free local/test setup intentionally exercises fakes/contracts and does not call external providers.

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

Before production: use a supervised queue process, durable queue/database, TLS, a configured `APP_URL`, secure cookies, key rotation/backups, secret management, Mailgun webhook verification, monitoring for failed jobs and recovery counters, and retention/PII policies appropriate to your jurisdiction. Do not rely on `php artisan serve`, SQLite, or the database queue for high-volume production without an explicit capacity/backup decision.

### Administrator workflows and persisted settings

Authenticated administrators can duplicate a quiz into an independent unpublished draft, create/edit/publish revisions, inspect histories, and generate an editable AI draft from a brief. Each AI-draft request appends a credential-free audit record containing request-time provider/model chain, prompt version/full prompt, brief/result hashes, and outcome metadata; it never inserts audit fields into the quiz definition, and later Operational Settings changes cannot alter the captured invocation. Submission operations include individual/bulk reanalysis and generate-and-send, latest resend, spam/hold, anonymization, and safe operational CSV export. Analysis/delivery history remains append-only: retries/resends append records, cancellation is recorded, an accepted delivery may advance by signed webhook to its terminal outcome without content rewriting, and anonymization removes current contact/resume identifiers without rewriting frozen answers or operational history.

The Operational Settings page persists only a closed non-secret configuration contract. It is live: quiz/report provider chains drive new work, report prompt labels/instructions are snapshotted, report email templates use only fixed escaped placeholders, sanitized design tokens/CSS are applied to public quizzes, spam controls govern Turnstile/automatic analysis, and operations policy controls resume age, retention token scrubbing, recovery limits, and execution timeout. Provider keys, webhook signing keys, and other credentials remain environment-only.

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
