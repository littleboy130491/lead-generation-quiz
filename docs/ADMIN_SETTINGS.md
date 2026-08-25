# Admin branding and email settings

The Filament admin panel provides database-backed Spatie Settings pages:

- **Branding & design** — `/admin/manage-branding-settings`
- **Report email templates** — `/admin/manage-report-email-settings`

## Branding & design

The settings apply to public quiz pages at runtime without a deploy:

- site label, eyebrow text, optional HTTPS logo URL;
- primary, secondary, background, and text colors;
- border radius;
- additional CSS (maximum 20,000 characters); and
- additional JavaScript (maximum 20,000 characters); and
- a static **Thank-you page HTML** field (maximum 40,000 characters).

CSS rejects HTML, `@import`, external `url()`, `javascript:`, and legacy CSS expression syntax. JavaScript is a **trusted administrator** capability and is placed only on public quiz pages. Never use it for secrets, credentials, analytics keys, payment logic, or untrusted respondent data. Do not include `<script>` or `<style>` tags; enter the JavaScript body only.

### Thank-you page HTML

This setting is available only to users with the `admin` role. It accepts static content HTML such as headings, paragraphs, lists, emphasis, links, images, divs, and spans. Content is sanitized immediately before it is displayed: scripts, styles, forms, iframes/embedded content, inline CSS, event attributes, unsafe URLs, and unsupported markup are removed. Stored HTML is **never** evaluated as Blade or PHP and has no placeholder support, so respondent data cannot be injected into the page.

## Report email templates

Settings include sender name, optional reply-to address, subject, HTML template, and text fallback. Report data is inserted through these placeholders only:

- `{{email}}`
- `{{report.executive_summary}}`
- `{{report.profile}}`
- `{{report.disclaimer}}`

Report values are escaped when rendered into HTML. Email templates should not contain PHP, Blade execution directives, JavaScript, or secrets.

## Data and deployment

Settings are managed by `spatie/laravel-settings`, stored in the `settings` database table, and seeded by a versioned settings migration. They are separate from the existing application operational/AI settings, which retain their closed non-secret validation boundary.

Run after deploying code:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

Verify the pages and the settings migration:

```bash
php artisan route:list --path=admin/manage-
php artisan migrate:status
```
