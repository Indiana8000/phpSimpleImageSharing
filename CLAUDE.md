# phpSimpleImageSharing

Small self-hosted private media album for friends. PHP 8.4, PDO SQLite, Apache shared hosting. See `README.md` (install/ops) and `todo.md` (product plan and decisions).

## Layout
- `index.php` - request routing, HTML, CSS and JS in one file.
- `app/` - `Auth`, `Database`, `LoginRateLimiter`, `MediaCleanup`, `MediaStorage`.
- `.htaccess` - share-link rewrites and `config.php` protection.
- `config.php` is the live config. It is gitignored and must not be edited (a hook blocks it); change `config.example.php` instead.

## Rules
- No Composer, Node, Docker, queues or cron requirements; it must run on ordinary PHP hosting.
- All UI text and docs in English; dates displayed as `YYYY-MM-DD`.
- Flat, simple UI in the chosen "C - Album" direction (`design-directions.html`); light and dark themes via CSS variables; mobile-first.
- Never log or echo passwords, session IDs or share tokens. Share tokens are stored only as hashes.
- Gallery and original media stay behind sign-in; media is served through PHP after an authorization check. Invalid, revoked or expired share links redirect to the Rickroll video.
- When config options or features change, update `config.example.php`, `README.md` and the `todo.md` checkboxes together.

## Checks
- A hook runs `php -l` after every `.php` edit.
- `/dev-server` runs the app locally with share-link routing; `/release-check` runs the pre-release checklist; the `security-reviewer` agent audits security-sensitive changes.
