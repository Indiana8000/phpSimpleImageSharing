---
name: release-check
description: Pre-release checklist for phpSimpleImageSharing - lint, config/README consistency, secret scan, release notes draft.
disable-model-invocation: true
---

# Release check

Run these checks and report a pass/fail list. Fix nothing without asking.

1. **Lint**: `php -l` on `index.php` and every file in `app/`.
2. **Config sync**: list every `$config[...]` key read in `index.php`; each must appear in `config.example.php` and the README install steps.
3. **Docs vs features**: compare the README "Current features and limits" with `todo.md` checkboxes and the code; flag drift. Docs must be English with `YYYY-MM-DD` dates.
4. **Secrets**: `git ls-files` must not include `config.php`, `*.sqlite` or data dirs; `.gitignore` still ignores `config.php`; grep tracked files for password hashes (`$2y$`, `$argon`).
5. **Logging**: grep `error_log` calls to confirm none include passwords, session IDs or share tokens.
6. **Hosting rules**: `.htaccess` still denies `config.php` and disables indexes; the README requirements match the extension checks in `index.php`.
7. **Release notes**: draft from `git log <last-tag>..HEAD` (or the last 20 commits if no tag), grouped as Added / Changed / Fixed.
8. **Open release items** in `todo.md` section 6 (screenshots, issue template): list what is still missing.
