<?php
// Copy this file to config.php and replace both values.
return [
    // Use an absolute directory outside the website's public document root.
    'data_dir' => '/home/your-user/private/phpSimpleImageSharing-data',
    // The first administrator account is created from this configured password hash.
    'admin_username' => 'admin',
    'admin_display_name' => 'Administrator',
    // Generate with: php -r "echo password_hash('your-long-password', PASSWORD_DEFAULT), PHP_EOL;"
    'password_hash' => 'replace-with-a-password-hash',
    'max_upload_bytes' => 12 * 1024 * 1024,
    // Maximum size of one video (MP4, WebM or MOV). PHP's upload_max_filesize must be at least
    // the larger of both limits and post_max_size 1 MiB above it; many hosts default to 64-256 MiB.
    'max_video_upload_bytes' => 200 * 1024 * 1024,
];
