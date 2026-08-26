# User Provisioning API

## Purpose

The versioned API creates an administrator panel user with Filament Shield roles. It is intended for server-to-server provisioning (CI, identity/ops tooling, bootstrap scripts). It does **not** replace interactive Filament user management for day-to-day edits.

**Base URL (this deployment)**

```text
https://web.demo-ku.com/sites/lead-generation-quiz/api/v1
```

The API is server-to-server only. Do not call it from browser JavaScript, mobile apps, or any client where its bearer token could be extracted.

## Authentication

Every request needs an `Authorization: Bearer` header. This endpoint shares the same fail-closed environment secret as the quiz-generation API:

```dotenv
QUIZ_GENERATION_API_TOKEN=<a-64-character-random-secret>
```

Generate a value locally on the server, store it in `.env`, and clear configuration cache:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php artisan optimize:clear
```

The endpoint returns `401 unauthenticated` when the variable is absent, blank, or wrong. Comparison is constant-time. Never place the token in source control, request URLs, analytics, a browser, or support tickets. Rotate it by replacing the environment value and clearing the configuration cache.

Before first use, seed roles/permissions:

```bash
php artisan db:seed --class=AdminRoleSeeder
```

## Rate limiting

The endpoint permits **20 requests per minute per client/IP** (shared throttle group with other `/api/v1` routes). Exceeding the limit returns Laravel's standard `429 Too Many Requests` response.

## Create a user

```http
POST /api/v1/users
Authorization: Bearer <QUIZ_GENERATION_API_TOKEN>
Content-Type: application/json
Accept: application/json
```

### Request body

| Field | Required | Type / limit | Description |
|---|---:|---|---|
| `name` | yes | string, 255 | Display name. |
| `email` | yes | email, 255, unique | Login email (stored lowercase). |
| `password` | yes | string | Plain password; hashed before storage. Must satisfy Laravel `Password::defaults()`. |
| `roles` | yes | array of strings, min 1 | One or more allowlisted roles (see below). |
| `email_verified` | no | boolean, default `true` | When true, sets `email_verified_at` so the user can sign in immediately. |

### Allowlisted roles

Only these seeded role names are accepted:

| Role | Typical access |
|---|---|
| `super_admin` | Unrestricted administrator; strict superset of `admin`. |
| `admin` | Full administrator for quizzes, submissions, users, and settings. |
| `quiz_manager` | Quiz-scoped permissions only. |
| `submission_manager` | Submission-scoped permissions only. |

Unknown role names and unseeded roles are rejected with `422`. The API never invents permissions or accepts arbitrary role strings.

### Example

```bash
curl --fail-with-body \
  --request POST 'https://web.demo-ku.com/sites/lead-generation-quiz/api/v1/users' \
  --header "Authorization: Bearer $QUIZ_GENERATION_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json' \
  --data '{
    "name": "Ops Admin",
    "email": "ops-admin@example.test",
    "password": "replace-with-a-strong-password",
    "roles": ["admin"],
    "email_verified": true
  }'
```

### Successful response — `201 Created`

```json
{
  "data": {
    "id": 7,
    "name": "Ops Admin",
    "email": "ops-admin@example.test",
    "roles": ["admin"],
    "email_verified_at": "2026-08-26T00:00:00+00:00",
    "created_at": "2026-08-26T00:00:00+00:00"
  }
}
```

The response never includes the password, password hash, remember token, or API secrets.

## Errors

| Status | `error.code` / payload | Meaning / client action |
|---:|---|---|
| 401 | `unauthenticated` | Missing, blank, or invalid bearer token. Fix authentication before retrying. |
| 422 | Laravel validation payload | Invalid email, weak/missing password, duplicate email, empty roles, or disallowed/unseeded role. Correct input before retrying. |
| 429 | Laravel throttle response | Rate limited. Respect `Retry-After` before retrying. |

## Lifecycle and safety guarantees

1. Passwords are hashed by the `User` model cast; plaintext is never stored or returned.
2. Only allowlisted, already-seeded Spatie/Filament Shield roles may be assigned.
3. The API accepts only the documented fields. It never accepts arbitrary permission names, panel configuration, or other users’ credentials.
4. There is no idempotency key. Duplicate emails fail validation (`unique:users,email`). Do not blindly retry after a network timeout without checking whether the user already exists.
5. Prefer provisioning least-privilege roles (`quiz_manager` / `submission_manager`) unless a true administrator is required.

## Operations checklist

- Set `QUIZ_GENERATION_API_TOKEN` and run `php artisan optimize:clear`.
- Run `php artisan db:seed --class=AdminRoleSeeder` so roles exist.
- Store the token only in a server-side secret manager or CI secret store.
- Deliver the initial password out of band; force a password change through your org’s normal process when possible.
- Keep `APP_DEBUG=false` in production so internal exceptions never become public API responses.

## Verification

```bash
php artisan route:list --path=api/v1/users
php artisan test --filter=UserProvisioningApiTest
```
