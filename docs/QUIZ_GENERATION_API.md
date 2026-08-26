# Quiz Generation API

## Purpose

The versioned API creates a new editable quiz draft from a constrained generation brief. It uses the same generation action, validator, immutable draft-generation audit record, and optional publish workflow as the administrator UI.

**Base URL (this deployment)**

```text
https://web.demo-ku.com/sites/lead-generation-quiz/api/v1
```

The API is server-to-server only. Do not call it from browser JavaScript, mobile apps, or any client where its bearer token could be extracted.

## Authentication

Every request needs an `Authorization: Bearer` header. Configure the secret only on the server (shared with user provisioning — see [USER_PROVISIONING_API.md](USER_PROVISIONING_API.md)):

```dotenv
QUIZ_GENERATION_API_TOKEN=<a-64-character-random-secret>
```

Generate a value locally on the server, store it in `.env`, and clear configuration cache:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php artisan optimize:clear
```

The endpoint fails closed with `401 unauthenticated` when the variable is absent, blank, or wrong. The application uses constant-time token comparison. Never place the token in source control, request URLs, analytics, a browser, or support tickets. Rotate it by replacing the environment value and clearing the configuration cache.

## Rate limiting

The endpoint permits **20 requests per minute per client/IP**. Exceeding the limit returns Laravel's standard `429 Too Many Requests` response. Generation can invoke a configured AI provider and may have provider costs; use a service-side job queue and conservative retries.

## Generate a quiz

```http
POST /api/v1/quizzes/generate
Authorization: Bearer <QUIZ_GENERATION_API_TOKEN>
Content-Type: application/json
Accept: application/json
```

### Request body

| Field | Required | Type / limit | Description |
|---|---:|---|---|
| `name` | yes | string, 255 | Administrator-facing quiz name. |
| `slug` | yes | `alpha_dash`, 255, unique | Public URL segment. Reserved application paths are rejected by the domain model. |
| `description` | no | string, 2,000 | Internal description. |
| `publish` | no | boolean, default `false` | When true, validates and publishes the generated draft as a new immutable revision. |
| `brief.business_context` | yes | string, 4,000 | Business context supplied to the generator as untrusted data. |
| `brief.target_audience` | no | string, 500 | Intended respondent audience. |
| `brief.objective` | no | string, 500 | What the quiz should achieve. |
| `brief.desired_insight` | no | string, 500 | Insight/report outcome sought. |
| `brief.question_count` | no | integer, 1–30 | Approximate number of questions. |
| `brief.tone` | no | string, 100 | Desired editorial tone. |

### Example: create a draft

```bash
curl --fail-with-body \
  --request POST 'https://web.demo-ku.com/sites/lead-generation-quiz/api/v1/quizzes/generate' \
  --header "Authorization: Bearer $QUIZ_GENERATION_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json' \
  --data ' {
    "name": "Operations Readiness Check",
    "slug": "operations-readiness-check",
    "description": "Qualifies operational-consulting leads.",
    "publish": false,
    "brief": {
      "business_context": "A consultancy helps growing professional-service firms improve operational delivery.",
      "target_audience": "Owners of 10–50 person service businesses",
      "objective": "Identify operational bottlenecks",
      "desired_insight": "The most valuable next operational action",
      "question_count": 8,
      "tone": "Clear, credible, practical"
    }
  }'
```

### Example: create and publish

Set `publish` to `true`. This creates a frozen revision and makes the public URL available immediately. Published revisions cannot be modified; a later change must create another revision.

### Successful response — `201 Created`

```json
{
  "data": {
    "id": 42,
    "name": "Operations Readiness Check",
    "slug": "operations-readiness-check",
    "status": "published",
    "definition": {
      "schema_version": 1,
      "blocks": []
    },
    "revision": { "id": 18, "version": 1 },
    "public_url": "https://web.demo-ku.com/sites/lead-generation-quiz/operations-readiness-check",
    "created_at": "2026-08-25T00:00:00+00:00"
  }
}
```

The real `definition.blocks` contains the validated generated linear definition. The response does **not** include credentials, provider keys, prompt secrets, raw provider payloads, or respondent data.

## Errors

| Status | `error.code` | Meaning / client action |
|---:|---|---|
| 401 | `unauthenticated` | Missing, blank, or invalid bearer token. Do not retry until authentication is fixed. |
| 422 | Laravel validation payload | Invalid/missing request field, duplicate slug, or invalid field size. Correct input before retrying. |
| 429 | Laravel throttle response | Rate limited. Respect `Retry-After` before retrying. |
| 502 | `quiz_generation_failed` | An unexpected generation failure was recorded. `error.quiz_id` identifies the retained draft/audit trail for an administrator. |
| 503 | provider-specific `GenerationException` code | No usable provider or a normalized provider failure. `error.quiz_id` identifies the retained draft/audit trail. Retry only with bounded backoff. |

Failed generations are **not silently deleted**. The draft and append-only audit record are retained for authorized administrator inspection without exposing secrets.

## Lifecycle and safety guarantees

1. The generation brief is treated as untrusted input; application-owned instructions require only a schema-version-1 quiz definition.
2. The generated definition is validated before it is saved. Unsupported blocks, unsafe schema keys, bad conditions, duplicate IDs, and invalid choice values are rejected.
3. `publish: false` returns an editable draft. `publish: true` validates then creates an immutable published revision.
4. Provider/model chain and the full resolved system prompt are snapshotted in the internal audit record at request time; changing runtime settings later does not alter that historical request.
5. The API accepts only the documented brief fields. It never accepts provider API keys, custom arbitrary prompts, raw definitions, user credentials, or frontend HTML/JavaScript.
6. A request has no idempotency key yet. Do not blindly retry after a network timeout: query the admin UI/audit trail first, or use a unique slug per caller attempt to avoid accidental duplicate drafts.

## Operations checklist

- Configure at least one supported AI provider credential in the server environment before using generation.
- Set `QUIZ_GENERATION_API_TOKEN` on the production host, then run `php artisan optimize:clear`.
- Store the token only in a server-side secret manager or CI secret store.
- Monitor `quiz_draft_generations` and Laravel logs for failed attempts.
- Review drafts in Filament before publishing if the caller uses `publish: false`.
- Keep `APP_DEBUG=false` in production so internal exceptions never become public API responses.

## Verification

```bash
php artisan route:list --path=api/v1/quizzes/generate
php artisan test --filter=QuizGenerationApiTest
```
