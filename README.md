# phpSimpleImageSharing

A small, self-hosted media album for friends, designed for PHP 8.4 shared hosting. Each person has an individual account. The gallery and image delivery require sign-in.

## Requirements

- PHP 8.4 with `fileinfo`, `session`, and `pdo_sqlite`
- Apache 2.4 with `.htaccess` enabled, or equivalent web-server protection for `config.php`
- HTTPS
- A writable data directory **outside** the public web root

## Installation

1. Upload the repository files to the directory served by your host.
2. Create a private directory outside the public web root, for example `~/private/phpSimpleImageSharing-data`, set its permissions to `700`, and grant the PHP process write access.
3. Copy `config.example.php` to `config.php`. Set `data_dir` to the absolute path of the private directory and choose the first administrator's username and display name.
4. Generate a password hash locally with `php -r "echo password_hash('YOUR_LONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` and set `password_hash` in `config.php`. Use a long, unique password.
5. Open the website over HTTPS and sign in with the configured admin username and password.
6. Remove the `password_hash` setting from `config.php` after the first admin account is created; the database now owns account credentials.
7. Use **Manage users** to create an individual account for each friend.

On first start, phpSimpleImageSharing creates `phpSimpleImageSharing.sqlite` in the data directory. Media files are stored in separate `users/<internal-user-id>/` subdirectories. Existing images from the earlier flat-file version are copied into the first administrator's folder on first start; the originals are left untouched.

`config.php` is ignored by Git and blocked by the included Apache `.htaccess`. Do not publish it. Keep the data directory outside the public web root so the database, uploaded media, and journal files cannot be requested directly.

## Current features and limits

- Individual administrator and member accounts; public self-registration is disabled.
- All signed-in users can view the group gallery. Members can delete their own uploads; administrators can delete any upload and manage accounts.
- Drag-and-drop and multi-image upload with per-file progress and retry for failures.
- Generate a public, unguessable share link for one image. Generating another link revokes the previous one; links expire when the image expires.
- Invalid, revoked, and expired share links redirect to the Rick Astley video. The private gallery and ordinary image routes still require sign-in.
- Choose whether each upload is kept forever or deleted after 1, 7, or 30 days. Expired files are removed in small batches during signed-in requests; no cron job is required.
- JPEG, PNG, GIF, and WebP uploads, limited to 12 MiB by default.
- Dates are displayed as `YYYY-MM-DD`.
- Video support is planned.

Public share URLs use Apache `mod_rewrite` through the included `.htaccess`. If using another web server, configure `/s/<26-character-token>` to reach `index.php?share=<token>` and route other `/s/` paths to `index.php?invalid_share=1`.

The PHP `upload_max_filesize` and `post_max_size` limits must be at least as large as the configured phpSimpleImageSharing limit. Keep PHP and the web server updated. Back up the entire data directory, including the SQLite database and all media folders, as one set.
