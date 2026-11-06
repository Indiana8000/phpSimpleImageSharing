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
4. Generate a password hash on your own computer with `php -r "echo password_hash('YOUR_LONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` and set `password_hash` in `config.php`. This does not require shell access on the host. Use a long, unique password.
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
- Search image filenames, sort by upload date, browse a paginated gallery, and open an image detail view with previous/next navigation, private-link copying, and original download.
- Select several images to copy their private links or delete them in one action; deletion permissions are checked per image.
- Administrators can check PHP extensions, HTTPS detection, configured upload limits, and stored media usage under **Manage users**.
- JPEG, PNG, GIF, and WebP uploads, limited to 12 MiB by default.
- Dates are displayed as `YYYY-MM-DD`.
- Video support is planned.

Public share URLs use Apache `mod_rewrite` through the included `.htaccess`. If using another web server, configure `/s/<26-character-token>` to reach `index.php?share=<token>` and route other `/s/` paths to `index.php?invalid_share=1`.

## Upload limits and maintenance

The PHP `upload_max_filesize` limit must be at least as large as `max_upload_bytes` in `config.php`. Set `post_max_size` a little higher to allow multipart form overhead. The upload request has a 120-second browser timeout; PHP and any host proxy must allow enough time to transfer a file at your expected connection speed. Keep enough free disk space for uploads and backups.

Before upgrades, back up the data directory as described below. Upload the new application files while preserving `config.php` and the private data directory. Do not replace the data directory when updating code. Database schema upgrades run automatically when the updated app starts; a database created by newer code cannot be opened by an older release. Keep PHP and Apache updated as well.

### Manual backup

There is no in-app backup or restore feature yet. A backup must include the database and all user media from the same point in time.

1. Ask users to stop uploading and wait for any active uploads to finish.
2. Temporarily block the application's public directory (for example, add `Require all denied` to its `.htaccess`) so no new requests can write data. Wait for requests already in progress to finish.
3. Use the hosting control panel or file manager to copy the **entire** configured `data_dir` to a private backup location. Include `phpSimpleImageSharing.sqlite`, the complete `users/` tree, and any SQLite journal files present. Do not publish the backup or place it under the public web root.
4. Remove the temporary access block and confirm the app is available again.

### Manual restore

1. Block access to the app and wait for active requests to finish, as above.
2. Restore the complete data directory from one backup set at the path configured by `data_dir`. Restore both the database and the complete `users/` tree together; do not mix files from different backup dates.
3. Ensure the PHP process can access the restored directory and files, then remove the temporary access block and sign in to check the gallery.

Automatic retention deletes expired files from the live data directory. It does not remove copies in host backups or external backups, so set a separate retention period for those backups.

The HTTPS status check reads PHP's `HTTPS` server variable. A reverse proxy that terminates TLS must pass the original request scheme to PHP for that check to report HTTPS correctly.
