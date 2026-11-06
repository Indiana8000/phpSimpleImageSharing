<?php
// PreToolUse hook: block edits to the live config.php (gitignored, holds secrets). exit 2 blocks the call.
$input = json_decode((string)stream_get_contents(STDIN), true);
$file = str_replace('\\', '/', (string)($input['tool_input']['file_path'] ?? ''));
if (strtolower(basename($file)) === 'config.php') {
    fwrite(STDERR, "Blocked: config.php holds live settings and secrets and is not tracked by Git. Edit config.example.php instead, or ask the user to change config.php.\n");
    exit(2);
}
