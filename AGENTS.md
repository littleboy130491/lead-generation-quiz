# AGENTS.md

## Project mission

Build and maintain a Laravel application for creating interactive lead-generation quizzes, capturing resumable submissions, generating professional AI analyses, and delivering reports by email.

## Authoritative product specification

`PRD.md` is the authoritative product and architecture specification for this repository.

### Mandatory PRD synchronization rule

**Every product, behavior, architecture, data-model, workflow, dependency, configuration, security, UI, or operational change must be recorded in `PRD.md` in the same change set.**

Before changing code:

1. Read `PRD.md` and the relevant section of `docs/IMPLEMENTATION_PLAN.md`.
2. Identify whether the requested change modifies an existing requirement or adds a decision.
3. Update the relevant normative section of `PRD.md`; do not update only the changelog.
4. Add a dated entry to the `PRD.md` Change Log describing what changed and why.
5. Implement the change and its tests.
6. Update `docs/IMPLEMENTATION_PLAN.md` when sequencing, file paths, milestones, dependencies, or verification steps change.

A code change that affects the product but leaves `PRD.md` stale is incomplete and must not be merged.

## Current stack

- PHP 8.3+
- Laravel 13
- Filament 5 admin panel
- Livewire 4
- Tailwind CSS 4 via the Laravel/Vite scaffold
- Laravel AI SDK (`laravel/ai`)
- Curator (`awcodes/filament-curator`) for media
- Laravel queues and scheduler
- Mailgun through Laravel Mail / Symfony Mailgun transport
- SQLite for local development; production database selection remains an infrastructure decision

Do not replace a major dependency without recording the decision and migration impact in `PRD.md`.

## Architecture rules

- Keep product/domain logic outside Filament resources and Livewire components.
- Use application actions/services for use cases and queued jobs only as orchestration boundaries.
- Treat published `QuizRevision` records, completed submission snapshots, completed analyses, and delivery attempts as immutable or append-only.
- Reanalysis creates a new `Analysis`; it never overwrites an earlier analysis.
- Resending creates a new `ReportDelivery`; it never erases an earlier attempt.
- Store quiz definitions as versioned JSON, while operational entities and relationships remain relational.
- A resume cookie contains only an opaque token. Answers and personal information stay server-side.
- AI provider credentials belong in environment-backed configuration, not ordinary database settings.
- Runtime settings may select and order enabled provider/model pairs but must never expose secrets.
- Treat respondent content as untrusted data, not AI instructions.
- Do not render model-generated PHP, Blade, JavaScript, or unsanitized HTML.

## Development workflow

1. Work in vertical slices.
2. For behavior changes, follow RED-GREEN-REFACTOR:
   - Write a focused failing test.
   - Run it and confirm the expected failure.
   - Add the minimum implementation.
   - Run the focused test and then the full suite.
3. Use database factories for domain fixtures.
4. Prefer feature tests for workflows and unit tests for pure compilers, validators, and condition evaluators.
5. Run formatting after implementation, not instead of tests.
6. Never claim completion until tests, migrations, and the relevant operational command have been exercised.

## Required verification before completion

Run, as applicable:

```bash
php artisan test
vendor/bin/pint --test
php artisan migrate:fresh --env=testing
npm run build
php artisan route:list
```

For queue/scheduler changes, also test the job or command directly and verify idempotency.

## Naming and status conventions

Use PHP backed enums for important lifecycle values.

Preferred statuses:

- Quiz: `draft`, `published`, `archived`
- Submission: `in_progress`, `awaiting_contact`, `completed`, `held_for_review`, `spam`, `abandoned`
- Analysis: `queued`, `processing`, `completed`, `failed`, `cancelled`
- Delivery: `queued`, `sending`, `accepted`, `delivered`, `failed`, `bounced`, `complained`

Do not introduce near-synonyms such as `finished` versus `completed` without updating the PRD and migrating all consumers.

## Security baseline

- Validate quiz visibility and required answers on the server using the frozen revision.
- Rate-limit quiz start, progress save, finalization, and email capture endpoints separately.
- Use a honeypot and Cloudflare Turnstile before accepting a completed submission.
- Hash quiz passwords and resume tokens.
- Normalize and validate email addresses before completion.
- Verify Mailgun webhook signatures and make webhook processing idempotent.
- Prevent duplicate automatic analyses and duplicate automatic delivery requests with database uniqueness and atomic claims.
- Escape report fields and render only through controlled templates.
- Never log API keys, raw secrets, or unnecessarily sensitive respondent content.

## Documentation locations

- `PRD.md`: authoritative requirements and decisions
- `docs/IMPLEMENTATION_PLAN.md`: phased, executable delivery plan
- `AGENTS.md`: contributor and AI-agent operating rules
- `README.md`: setup and day-to-day commands; update when onboarding steps change
