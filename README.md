# phpSimpleImageSharing

A small, password-protected image-sharing app for PHP 8.4 and basic web hosting. The gallery and image links require sign-in.

## Requirements

- PHP 8.4 with the `fileinfo` and `session` extensions
- HTTPS
- A writable data directory **outside** the public web root

## Installation

1. Upload this repository to a directory served by your host with PHP 8.4 enabled.
2. Create a directory for media outside the public web root, for example `~/private/phpSimpleImageSharing-data`, and grant the PHP process write access.
3. Copy `config.example.php` to `config.php` and set `data_dir` to the absolute path of that directory.
4. Generate a password hash locally with `php -r "echo password_hash('YOUR_LONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` and set `password_hash` in `config.php`. Use a long, unique password.
5. Open the website over HTTPS and sign in with that password.

`config.php` is ignored by Git and must not be published. If your host does not allow storage outside the web root, contact its support; direct web access to uploaded files must be reliably blocked. phpSimpleImageSharing serves images only through an authenticated PHP endpoint.

## Usage and maintenance

JPEG, PNG, GIF, and WebP are supported. Uploads are limited to 12 MiB by default. The PHP `upload_max_filesize` and `post_max_size` limits must be at least as large as the configured phpSimpleImageSharing limit. Larger files and videos are not enabled yet.

Keep PHP and the web server updated. Back up the data directory; it contains images and metadata. Anyone who knows the shared password can view, upload, and delete all images, so only share it with trusted people.
