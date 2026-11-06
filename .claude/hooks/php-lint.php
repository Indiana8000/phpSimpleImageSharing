<?php
// PostToolUse hook: run `php -l` on edited .php files; exit 2 feeds errors back to Claude.
$input = json_decode((string)stream_get_contents(STDIN), true);
$file = (string)($input['tool_input']['file_path'] ?? '');
if (!str_ends_with(strtolower($file), '.php') || !is_file($file)) exit(0);
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "PHP syntax error in $file:\n" . implode("\n", $output) . "\n");
    exit(2);
}
