# Local and server setup

Complete install path from an empty machine through a working admin panel, media library, and optional AI/Mailgun credentials. Product behavior remains defined by [PRD.md](../PRD.md). Day-to-day commands stay summarized in [README.md](../README.md).

## Prerequisites

- Git
- PHP 8.3+ with extensions commonly required by Laravel (`mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` or `imagick` recommended for Curator image processing)
- Composer 2
- Node.js 22+ and npm
- SQLite for local development (default), or another database you configure in `.env`

## 1. Clone and install dependencies

```bash
git clone <repository-url> leadgenquiz
cd leadgenquiz

composer install
npm install
```

Use `npm ci` instead of `npm install` when you need a lockfile-exact install (CI or reproducible deploys).

## 2. Environment file and app key

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` at least for:

| Variable | Notes |
|---|---|
| `APP_NAME` | Display name |
| `APP_URL` | Public base URL (include subdirectory mount if used, e.g. `https://example.com/sites/lead-generation-quiz`) |
| `APP_DEBUG` | `true` locally; **`false` in production** |
| `DB_*` | SQLite steps below, or your production database |

Do **not** commit `.env`. Do **not** store AI/Mailgun secrets in the admin settings UI.

## 3. Database (local SQLite)

```bash
# Ensure these are set in .env (already typical in .env.example):
# DB_CONNECTION=sqlite
# # DB_DATABASE=  (leave blank to use database/database.sqlite)

touch database/database.sqlite
php artisan migrate
```

For MySQL/Postgres, set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`, then run `php artisan migrate` only (no `touch`).

## 4. Roles, storage link, Curator media token, and Livewire assets

Media uploads (Filament Curator / Glide) **require** a Glide signing token. Without it, media operations fail with an error telling you to run `php artisan curator:token`.

Filament login and admin pages need Livewire’s JavaScript. By default Livewire serves that file through a **PHP route** (for example `/livewire-…/livewire.js`). On many Nginx / subdirectory / static-fronted deploys that route returns **500** or is not rewritten to Laravel. Publishing the assets makes Nginx serve them as ordinary static files from `public/vendor/livewire` (Livewire auto-detects `public/vendor/livewire/manifest.json`).

```bash
php artisan db:seed --class=AdminRoleSeeder
php artisan storage:link
php artisan curator:token
php artisan livewire:publish --assets
php artisan filament:assets
php artisan optimize:clear
```

What these do:

| Command | Purpose |
|---|---|
| `AdminRoleSeeder` | Creates Shield roles/permissions (`super_admin`, `admin`, `quiz_manager`, `submission_manager`) |
| `storage:link` | Links `public/storage` → `storage/app/public` so uploaded media is reachable |
| `curator:token` | Writes a fresh `CURATOR_GLIDE_TOKEN=...` into `.env` (required for Curator/Glide) |
| `livewire:publish --assets` | Copies Livewire JS/CSS into `public/vendor/livewire` so Filament does not depend on the PHP asset route |
| `filament:assets` | Publishes Filament panel CSS/JS under `public/css/filament` and `public/js/filament` |
| `optimize:clear` | Clears config/route/view caches so the new token and env values are loaded |

Confirm `.env` contains a non-empty `CURATOR_GLIDE_TOKEN` and `CURATOR_DEFAULT_DISK=public`. Confirm `public/vendor/livewire/manifest.json` exists after publishing Livewire.

Optional demo quiz (publishes `business-readiness-check` with opening page + AI result mode when no active revision exists):

```bash
php artisan db:seed --class=LeadGenerationQuizSeeder
```

`php artisan db:seed` (full `DatabaseSeeder`) seeds roles, an `admin@example.test` user with the `admin` role (password `password` — change immediately), and the demo quiz.

## 5. Create an administrator

Choose a real unique email and a strong password. Do not put credentials in source control.

**Option A — tinker**

```bash
php artisan tinker
```

```php
$user = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@example.test',
    'password' => 'replace-this-password',
]);
$user->assignRole('admin');
// or: $user->assignRole('super_admin');
```

**Option B — provisioning API**

See [USER_PROVISIONING_API.md](USER_PROVISIONING_API.md). Requires `QUIZ_GENERATION_API_TOKEN` in `.env` and seeded roles.

```bash
# Generate a server-only Bearer secret if you will use /api/v1:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
# Put the value in .env as QUIZ_GENERATION_API_TOKEN=...
php artisan optimize:clear
```

## 6. Frontend assets

```bash
npm run build
```

For local Vite HMR during UI work: `npm run dev` (keep it running alongside `php artisan serve`).

## 7. Run the app

```bash
php artisan serve
```

Open `/admin/login`, sign in, and confirm:

- Dashboard loads
- **Media** library can upload a file (needs `storage:link` + `CURATOR_GLIDE_TOKEN`)
- Quizzes / Submissions resources are visible for your role

Queue workers are required for AI analysis and report email:

```bash
php artisan queue:work --queue=default,ai,mail --tries=1 --timeout=180
# optional local scheduler:
php artisan schedule:work
```

## 8. Configure AI providers (optional until you use AI)

Credentials and optional base URLs live in `.env`. Provider **names** and **models** are chosen in **Admin → Operational settings** (non-secret chains only).

### Environment credentials and URLs

Set at least one provider key, then `php artisan optimize:clear`:

```dotenv
# OpenAI
OPENAI_API_KEY=sk-...
# OPENAI_URL=https://api.openai.com/v1

# Anthropic
ANTHROPIC_API_KEY=...
# ANTHROPIC_URL=https://api.anthropic.com/v1

# Gemini
GEMINI_API_KEY=...
# GEMINI_URL=https://generativelanguage.googleapis.com/v1beta/

# OpenRouter
OPENROUTER_API_KEY=...

# OpenAI-compatible gateway (custom endpoint)
OPENAI_COMPATIBLE_API_KEY=...
OPENAI_COMPATIBLE_URL=https://your-gateway.example/v1
```

Provider registry and defaults are in `config/ai.php` (`openai`, `anthropic`, `gemini`, `openrouter`, `openai-compatible`, `azure`, `ollama`, and others).

### Operational settings chains

Under **Operational settings**:

1. **Quiz AI provider chain** — used by the AI quiz interview and `POST /api/v1/quizzes/generate`  
2. **Report AI provider chain** — used by submission analysis  

Add ordered rows such as:

| Provider | Model |
|---|---|
| `openai` | `gpt-4.1` |

The `provider` string must match a key in `config/ai.php`, and that provider’s env key must be set. Entries without a usable key are skipped. The AI quiz interview still creates a structural draft from the brief when no quiz chain entry is usable.

More detail: [ADMIN_SETTINGS.md](ADMIN_SETTINGS.md).

### Request and worker timeouts for AI generation

AI quiz interview turns and `POST /api/v1/quizzes/generate` call the provider inside the web request. Administrator quiz-draft generation is queued on `ai`; keep a supervised worker running or the chat will remain in `generating`. Each attempt is allowed `operations.timeout_seconds` (Operational settings, default 60), and a chain is tried in order, so the worst case is `timeout_seconds × chain length + 15` seconds. Web-server and PHP-FPM limits must cover synchronous paths, while the queue worker timeout must exceed that worst case.

With a default 60-second timeout and a two-entry chain, allow at least 135 seconds:

```nginx
# nginx server block, inside location ~ \.php$
fastcgi_read_timeout 180;
```

```ini
; /etc/php/8.4/fpm/pool.d/www.conf
request_terminate_timeout = 180
```

```ini
; /etc/php/8.4/fpm/php.ini — must be at least timeout_seconds × chain length + 15
max_execution_time = 180
```

Some managed hosts list `set_time_limit` in `disable_functions`. The application skips the call there, so `max_execution_time` in `php.ini` becomes the only limit that matters and must be raised to the full value above. Check with `php -i | grep disable_functions`.

Reload after editing: `sudo systemctl reload php8.4-fpm nginx`.

Run the AI worker with a timeout above the provider-chain maximum. For the two-entry, 60-second example:

```bash
php artisan queue:work --queue=ai,default,mail --tries=1 --timeout=180
```

For the database queue, `DB_QUEUE_RETRY_AFTER` must be greater than the worker timeout. The repository default is 195 seconds for the command above. Increase both values together if the quiz chain or per-attempt timeout grows.

### Diagnosing a slow or failing generation

Turn on the AI debug log to see each provider call:

```dotenv
AI_DEBUG_LOG=true
AI_DEBUG_LOG_CONTENT=true
```

Then `php artisan optimize:clear` and reproduce the problem. Each step is written to `storage/logs/ai-YYYY-MM-DD.log` with its provider, model, wall time in milliseconds, prompt/completion/reasoning token counts, finish reason, and normalized failure. Tail it while you use the chat:

```bash
tail -f storage/logs/ai-$(date +%F).log
```

Watch `reasoning_tokens` too. Reasoning models spend hidden tokens before writing anything, and that time counts against the request timeout. `AI_DISABLE_REASONING=true` (the default) asks providers that support the toggle to turn it off for these schema-constrained calls; set it to `false` only if output quality demands it, and raise `operations.timeout_seconds` and the server timeouts to match.

Read `finish_reason` first. `length` means the model hit its output ceiling and the response was truncated — usually a schema or prompt that asks for too much, not a network problem. `stop` with a large `duration_ms` and high `completion_tokens` means the model genuinely generated that long. A `AI step failed` entry carries the provider's own error text.

`AI_DEBUG_LOG_CONTENT` also records prompt and response bodies. Analysis calls include respondent answers, so enable it only for a deliberate debugging window, then set both flags back to `false`, run `php artisan optimize:clear`, and delete the `storage/logs/ai-*.log` files. API keys are never logged. Lowering `operations.timeout_seconds` or shortening the chain reduces the required floor.

## 9. Other common environment settings

```dotenv
QUEUE_CONNECTION=database
QUIZ_RESUME_DAYS=30
TURNSTILE_REQUIRED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=

MAILGUN_DOMAIN=
MAILGUN_SECRET=
MAILGUN_ENDPOINT=api.mailgun.net
MAILGUN_WEBHOOK_SIGNING_KEY=

QUIZ_GENERATION_API_TOKEN=
```

Mailgun and Turnstile remain unused until credentials/flags are set. Local/test defaults can stay credential-free.

## 10. Production checklist (summary)

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # only on first deploy; then edit secrets on the host
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=AdminRoleSeeder
php artisan storage:link
php artisan curator:token
php artisan livewire:publish --assets
php artisan filament:assets
php artisan optimize:clear
npm ci
npm run build
```

Then: set `APP_DEBUG=false`, configure HTTPS/`APP_URL`, durable database/queue, supervised `queue:work` and cron `schedule:run`, Mailgun webhook verification, AI keys, and `QUIZ_GENERATION_API_TOKEN` if you use `/api/v1`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Filament login Livewire JS **500** / script fails to load | Run `php artisan livewire:publish --assets` (and `php artisan filament:assets`). Confirm `public/vendor/livewire/manifest.json` is web-reachable. Default Livewire serves JS via a PHP route that often breaks under Nginx/subdirectory mounts. |
| Media upload / Glide error about missing token | Run `php artisan curator:token` then `php artisan optimize:clear`. Confirm `CURATOR_GLIDE_TOKEN` in `.env`. |
| Uploaded files 404 | Run `php artisan storage:link`. Confirm `CURATOR_DEFAULT_DISK=public`. |
| AI quiz interview uses a basic scaffold | Optional: set a provider key in `.env` and add a matching Quiz AI provider/model row under Operational settings for model-written drafts. |
| AI interview or generation API hangs then returns a gateway error (502/503/504) while the provider dashboard shows the tokens were billed | The synchronous request was killed before the provider replied. Raise `fastcgi_read_timeout`, `request_terminate_timeout`, and `max_execution_time` as described in [request and worker timeouts](#request-and-worker-timeouts-for-ai-generation), or lower `operations.timeout_seconds`. |
| Quiz chat remains on “Generating your quiz draft” | Start or inspect the `ai` queue worker and failed-jobs table. Ensure the worker timeout exceeds `timeout_seconds × chain length + 15`. |
| Quiz draft generation reports a provider or contract error | The chat appends a normalized failure message and offers retry. The same normalized message is stored on the `quiz_draft_generations` audit row. |
| `/api/v1/*` returns `401 unauthenticated` | Set `QUIZ_GENERATION_API_TOKEN` and send `Authorization: Bearer …`. |
| Cannot access Branding & design | Sign in as `admin` or `super_admin` after `AdminRoleSeeder`. |
| Stale config after editing `.env` | `php artisan optimize:clear` |

## Verification

The repository's tracked `.env.testing` pins testing commands to in-memory SQLite. Do not remove that isolation or point it at a development/production database.

```bash
php artisan route:list
php artisan migrate:status
php artisan test
vendor/bin/pint --test
npm run build
```

Related docs:

- [ADMIN_SETTINGS.md](ADMIN_SETTINGS.md) — branding, email templates, prompts, AI chains  
- [QUIZ_GENERATION_API.md](QUIZ_GENERATION_API.md) — server-to-server quiz generation  
- [USER_PROVISIONING_API.md](USER_PROVISIONING_API.md) — server-to-server user creation  
