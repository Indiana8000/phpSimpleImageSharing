# Security policy

## Supported versions

Only the latest release and the `main` branch receive security fixes.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Report them privately through GitHub:
**Security tab > Report a vulnerability** (private vulnerability reporting).

Include:

- what you found and where (file, route or feature),
- steps to reproduce it, ideally against a throwaway installation,
- the impact you expect, for example access to another person's photos or to `config.php`.

Please never include real passwords, session IDs or share tokens in a report.

You will get a first answer within a few days. Fixes are published in a new release, and you are credited if you wish.

## Scope

Areas that matter most for this project:

- bypassing sign-in, roles (guests and members must not see photos they are not allowed to) or CSRF checks,
- reading `config.php`, the SQLite database or media files without authorization,
- guessing or leaking share tokens, or reaching other photos through a share link,
- unsafe handling of uploaded files.

Hosting problems that need server access, such as a public data directory or missing HTTPS, are configuration mistakes rather than vulnerabilities. The README explains the safe setup.
