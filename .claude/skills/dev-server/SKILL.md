---
name: dev-server
description: Run phpSimpleImageSharing locally with PHP's built-in server, a throwaway data directory and the .htaccess share-link rewrites. Use to view the app, test share URLs, or check UI changes with Playwright.
---

# Local dev server

The built-in PHP server ignores `.htaccess`, so `.claude/skills/dev-server/router.php` reproduces its rules (`/<26-char token>`, `/s/<token>`, blocked `config.php`).

The live `config.php` is never edited or used here. The app reads `config.php` from the project root, so use a temporary copy of the project instead of changing it:

1. Create a scratch dir under the scratchpad (never `/tmp`), e.g. `<scratch>/app` and `<scratch>/data`.
2. Copy `index.php`, `.htaccess` and `app/` into `<scratch>/app` (re-copy after code changes, or symlink).
3. Write `<scratch>/app/config.php` with `data_dir` set to `<scratch>/data`, `admin_username` `admin`, and a `password_hash` made by `php -r "echo password_hash('dev-password-123', PASSWORD_DEFAULT);"`.
4. Copy `router.php` next to it and start (in the background):
   `php -S localhost:8000 router.php` from `<scratch>/app`
   (router.php resolves the root as three levels up from itself, so place it at `<scratch>/app/.claude/skills/dev-server/router.php`, or edit the `$root` line when copying).
5. Sign in as `admin` / `dev-password-123`.

## Checks
- `http://localhost:8000/` shows the sign-in page.
- A made-up share URL such as `http://localhost:8000/00000000000000000000000000` redirects to the Rickroll video.
- `/s/anything-else` also redirects there. `/config.php` returns 403.
- With the Playwright tools, check both themes and a narrow (about 390px) viewport.

Stop the server and delete the scratch dir when done.
