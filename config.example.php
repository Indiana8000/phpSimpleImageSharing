<?php
// Copy this file to config.php and replace both values.
return [
    // Use an absolute directory outside the website's public document root.
    'data_dir' => '/home/your-user/private/phpSimpleImageSharing-data',
    // Generate with: php -r "echo password_hash('your-long-password', PASSWORD_DEFAULT), PHP_EOL;"
    'password_hash' => 'replace-with-a-password-hash',
    'max_upload_bytes' => 12 * 1024 * 1024,
];
