<?php
declare(strict_types=1);

// Private media sharing for PHP 8.4 shared hosting.
// Copy config.example.php to config.php and configure the private data directory.
$runtimeIssues = [];
if (version_compare(PHP_VERSION, '8.4.0', '<')) $runtimeIssues[] = 'PHP 8.4 or later is required. The host is running ' . PHP_VERSION . '.';
foreach (['pdo_sqlite', 'fileinfo', 'session'] as $requiredExtension) {
    if (!extension_loaded($requiredExtension)) $runtimeIssues[] = 'Enable the PHP extension: ' . $requiredExtension . '.';
}
if ($runtimeIssues !== []) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Setup requirements</title><h1>Setup requirements are missing</h1><ul>';
    foreach ($runtimeIssues as $runtimeIssue) echo '<li>' . htmlspecialchars($runtimeIssue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    echo '</ul><p>Enable the listed items in the hosting control panel or ask the hosting provider for help.</p></html>';
    exit;
}
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo 'Not configured yet. See README.md.';
    exit;
}
$config = require $configFile;
$dataDir = rtrim((string)($config['data_dir'] ?? ''), "\\/");
$initialPasswordHash = (string)($config['password_hash'] ?? '');
$initialUsername = (string)($config['admin_username'] ?? 'admin');
$initialDisplayName = (string)($config['admin_display_name'] ?? 'Administrator');
$maxBytes = (int)($config['max_upload_bytes'] ?? 12 * 1024 * 1024);
$resolvedDataDir = $dataDir === '' ? false : realpath($dataDir);
$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($documentRoot === false) $documentRoot = realpath(__DIR__);
$normalizedDataDir = $resolvedDataDir === false ? '' : rtrim(str_replace('\\', '/', $resolvedDataDir), '/');
$normalizedDocumentRoot = $documentRoot === false ? '' : rtrim(str_replace('\\', '/', $documentRoot), '/');
if (DIRECTORY_SEPARATOR === '\\') {
    $normalizedDataDir = strtolower($normalizedDataDir);
    $normalizedDocumentRoot = strtolower($normalizedDocumentRoot);
}
$dataDirIsPublic = $normalizedDocumentRoot !== '' && ($normalizedDataDir === $normalizedDocumentRoot || str_starts_with($normalizedDataDir, $normalizedDocumentRoot . '/'));
if ($dataDir === '' || $resolvedDataDir === false || !is_dir($resolvedDataDir) || !is_writable($resolvedDataDir) || $dataDirIsPublic) {
    http_response_code(503);
    echo 'Configuration error. Set data_dir to a writable directory outside the public web root.';
    exit;
}
$dataDir = $resolvedDataDir;

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/MediaStorage.php';
require_once __DIR__ . '/app/MediaCleanup.php';
require_once __DIR__ . '/app/LoginRateLimiter.php';

try {
    $pdo = Database::connect($dataDir);
    Auth::bootstrapInitialAdmin($pdo, $initialPasswordHash, $initialUsername, $initialDisplayName);
    $mediaStorage = new MediaStorage($dataDir);
} catch (Throwable $exception) {
    error_log('phpSimpleImageSharing startup error: ' . $exception->getMessage());
    http_response_code(503);
    echo 'The application could not start. Check PHP extensions, the initial admin password hash, and data directory permissions.';
    exit;
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_lifetime', '0');
ini_set('session.gc_maxlifetime', '43200');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; connect-src 'self'; img-src 'self' blob:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (@inet_pton(trim($remoteAddress)) === false) $remoteAddress = '127.0.0.1';
$loginThrottleKey = LoginRateLimiter::key($remoteAddress);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function token(): string { return bin2hex(random_bytes(32)); }
function iniBytes(string $setting): int {
    $setting = trim($setting);
    if ($setting === '-1') return PHP_INT_MAX;
    if (!preg_match('/\A([0-9]+(?:\.[0-9]+)?)\s*([KMG]?)B?\z/i', $setting, $matches)) return 0;
    $power = match (strtoupper($matches[2])) { 'K' => 1, 'M' => 2, 'G' => 3, default => 0 };
    return (int)((float)$matches[1] * (1024 ** $power));
}
function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KiB', 'MiB', 'GiB', 'TiB'];
    $size = (float)$bytes;
    foreach ($units as $unit) {
        $size /= 1024;
        if ($size < 1024 || $unit === 'TiB') return number_format($size, 1) . ' ' . $unit;
    }
    return $bytes . ' B';
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function checkCsrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Invalid request token. Please reload and try again.');
    }
}

$rawAction = $_GET['action'] ?? '';
$action = is_string($rawAction) ? $rawAction : '';
$error = '';
$notice = (string)($_SESSION['flash_notice'] ?? '');
$error = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_notice'], $_SESSION['flash_error']);
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ./'); exit;
}
if (array_key_exists('share', $_GET)) {
    $shareValue = $_GET['share'];
    servePublicShare($pdo, $mediaStorage, is_string($shareValue) ? $shareValue : '');
}
$currentUser = null;
$sessionUserId = (string)($_SESSION['user_id'] ?? '');
if ($sessionUserId !== '') {
    $sessionExpired = time() - (int)($_SESSION['last_activity'] ?? 0) > 43200;
    if (!$sessionExpired) $currentUser = Auth::findById($pdo, $sessionUserId);
    if ($currentUser === null || (int)$currentUser['active'] !== 1 || (int)($currentUser['auth_version'] ?? 0) !== (int)($_SESSION['auth_version'] ?? -1)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $currentUser = null;
        $error = $sessionExpired ? 'Your session expired. Please sign in again.' : 'Your account is no longer active. Contact an administrator.';
    }
}
if ($currentUser !== null) $_SESSION['last_activity'] = time();

if ($currentUser === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
        checkCsrf();
        if (LoginRateLimiter::isBlocked($pdo, $loginThrottleKey)) {
            $error = 'Too many failed sign-in attempts. Try again in 15 minutes.';
        } else {
            $user = Auth::login($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            if ($user !== null) {
                LoginRateLimiter::clear($pdo, $loginThrottleKey);
                session_regenerate_id(true);
                $_SESSION = ['user_id' => $user['id'], 'auth_version' => (int)$user['auth_version'], 'last_activity' => time(), 'csrf' => bin2hex(random_bytes(32))];
                header('Location: ./'); exit;
            }
            $failures = LoginRateLimiter::recordFailure($pdo, $loginThrottleKey);
            $error = $failures >= 5 ? 'Too many failed sign-in attempts. Try again in 15 minutes.' : 'Incorrect username or password.';
        }
    }
    pageStart('Sign in', 'plain');
    ?>
<div class="login">
  <form class="login-form" method="post" action="?action=login">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <div><h1>Sign in</h1><p class="sub">Photos shared among friends. Ask an administrator if you need an account.</p></div>
    <?php if ($error): ?><p class="banner error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <label>Username<input name="username" required autocomplete="username" autofocus></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Sign in</button>
  </form>
</div>
<?php
    pageEnd(); exit;
}

try {
    MediaCleanup::run($pdo, $mediaStorage, 10);
} catch (Throwable $exception) {
    error_log('phpSimpleImageSharing cleanup error: ' . $exception->getMessage());
}

if ($action === 'image') {
    $rawId = $_GET['id'] ?? '';
    $id = is_string($rawId) ? $rawId : '';
    if (!preg_match('/\A[a-f0-9]{32,64}\z/', $id)) { http_response_code(404); exit; }
    $statement = $pdo->prepare('SELECT media.owner_id, media.storage_name, media.mime, media.expires_at, users.role AS owner_role FROM media JOIN users ON users.id = media.owner_id WHERE media.id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $media = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media) || !mayViewMedia($currentUser, (string)$media['owner_id'], (string)$media['owner_role'])) { http_response_code(404); exit; }
    if ($media['expires_at'] !== null && (int)$media['expires_at'] <= time()) { http_response_code(410); exit('This image has expired.'); }
    $file = $mediaStorage->locate((string)$media['owner_id'], (string)$media['storage_name']);
    if ($file === null || !in_array($media['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . $media['mime']);
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: private, no-store');
    readfile($file); exit;
}

if ($action === 'download') {
    $rawId = $_GET['id'] ?? '';
    $id = is_string($rawId) ? $rawId : '';
    if (!preg_match('/\A[a-f0-9]{32,64}\z/', $id)) { http_response_code(404); exit; }
    $statement = $pdo->prepare('SELECT media.owner_id, media.storage_name, media.original_name, media.mime, media.expires_at, users.role AS owner_role FROM media JOIN users ON users.id = media.owner_id WHERE media.id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $media = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media) || !mayViewMedia($currentUser, (string)$media['owner_id'], (string)$media['owner_role'])
        || ($media['expires_at'] !== null && (int)$media['expires_at'] <= time())) { http_response_code(404); exit; }
    $file = $mediaStorage->locate((string)$media['owner_id'], (string)$media['storage_name']);
    if ($file === null || !in_array($media['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . $media['mime']);
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode((string)$media['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($file); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        if ($action === 'bulk') {
            $bulkAction = is_string($_POST['bulk_action'] ?? null) ? (string)$_POST['bulk_action'] : '';
            $selectedIds = $_POST['ids'] ?? [];
            if (!is_array($selectedIds) || count($selectedIds) > 100) throw new InvalidArgumentException('Select up to 100 photos at a time.');
            $selectedIds = array_values(array_unique(array_filter($selectedIds, static fn(mixed $id): bool => is_string($id) && preg_match('/\A[a-f0-9]{32,64}\z/', $id))));
            if (!in_array($bulkAction, ['copy', 'delete'], true) || $selectedIds === []) throw new InvalidArgumentException('Select at least one photo and choose an action.');
            if ($bulkAction === 'copy') {
                $paths = [];
                $find = $pdo->prepare('SELECT id FROM media WHERE id = :id AND (expires_at IS NULL OR expires_at > :now) LIMIT 1');
                foreach ($selectedIds as $selectedId) {
                    $find->execute([':id' => $selectedId, ':now' => time()]);
                    if ($find->fetchColumn() !== false) {
                        $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
                        $paths[] = $scriptPath . '?action=image&id=' . rawurlencode($selectedId);
                    }
                }
                header('Content-Type: text/plain; charset=utf-8');
                echo implode("\n", $paths);
                exit;
            }
            $find = $pdo->prepare('SELECT owner_id, storage_name FROM media WHERE id = :id LIMIT 1');
            $delete = $pdo->prepare('DELETE FROM media WHERE id = :id');
            $deletedCount = 0;
            foreach ($selectedIds as $selectedId) {
                $find->execute([':id' => $selectedId]);
                $media = $find->fetch(PDO::FETCH_ASSOC);
                if (!is_array($media) || ($currentUser['role'] !== 'admin' && $media['owner_id'] !== $currentUser['id'])) continue;
                $mediaStorage->delete((string)$media['owner_id'], (string)$media['storage_name']);
                $delete->execute([':id' => $selectedId]);
                $deletedCount += $delete->rowCount();
            }
            if ($deletedCount > 0) $_SESSION['flash_notice'] = 'Deleted ' . $deletedCount . ' ' . ($deletedCount === 1 ? 'photo.' : 'photos.');
            else $_SESSION['flash_error'] = 'No selected photos could be deleted.';
            $returnQuery = [
                'q' => is_string($_POST['return_q'] ?? null) ? substr((string)$_POST['return_q'], 0, 120) : '',
                'sort' => is_string($_POST['return_sort'] ?? null) && $_POST['return_sort'] === 'oldest' ? 'oldest' : 'newest',
                'filter' => is_string($_POST['return_filter'] ?? null) && in_array($_POST['return_filter'], ['mine', 'expiring'], true) ? $_POST['return_filter'] : 'all',
                'page' => is_scalar($_POST['return_page'] ?? null) ? max(1, (int)$_POST['return_page']) : 1,
            ];
            $returnString = cleanQuery($returnQuery);
            header('Location: ./' . ($returnString !== '' ? '?' . $returnString : ''));
            exit;
        } elseif ($action === 'upload') {
            $retentionDays = (string)($_POST['retention'] ?? 'never');
            if (!array_key_exists($retentionDays, retentionOptions())) {
                throw new InvalidArgumentException('Choose a valid retention period.');
            }
            $expiresAt = $retentionDays === 'never' ? null : time() + ((int)$retentionDays * 86400);
            if (($_POST['remember_default'] ?? '') === '1') {
                $pdo->prepare('UPDATE users SET default_retention = :value WHERE id = :id')->execute([':value' => $retentionDays, ':id' => $currentUser['id']]);
            }
            $uploads = normalizeUploads($_FILES['images'] ?? ($_FILES['image'] ?? null));
            if ($uploads === []) $uploads[] = ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => ''];
            $uploadResults = [];
            $insert = $pdo->prepare(
                'INSERT INTO media (id, owner_id, storage_name, original_name, mime, size_bytes, created_at, expires_at)
                 VALUES (:id, :owner_id, :storage_name, :original_name, :mime, :size_bytes, :created_at, :expires_at)'
            );
            foreach ($uploads as $upload) {
                try {
                    $stored = $mediaStorage->storeUploadedFile($currentUser['id'], $upload, $maxBytes);
                    $createdAt = time();
                    try {
                        $insert->execute([
                            ':id' => $stored['id'], ':owner_id' => $currentUser['id'], ':storage_name' => $stored['storage_name'],
                            ':original_name' => $stored['original_name'], ':mime' => $stored['mime'],
                            ':size_bytes' => $stored['size_bytes'], ':created_at' => $createdAt, ':expires_at' => $expiresAt,
                        ]);
                    } catch (Throwable $exception) {
                        $mediaStorage->delete($currentUser['id'], $stored['storage_name']);
                        throw $exception;
                    }
                    $uploadResults[] = ['success' => true, 'id' => $stored['id'], 'name' => $stored['original_name']];
                } catch (InvalidArgumentException | RuntimeException $exception) {
                    $uploadResults[] = ['success' => false, 'error' => $exception->getMessage()];
                } catch (Throwable $exception) {
                    error_log('phpSimpleImageSharing upload error: ' . $exception->getMessage());
                    $uploadResults[] = ['success' => false, 'error' => 'The file could not be saved.'];
                }
            }
            $expectsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['items' => $uploadResults], JSON_THROW_ON_ERROR);
                exit;
            }
            $successCount = count(array_filter($uploadResults, static fn(array $item): bool => $item['success']));
            $failureCount = count($uploadResults) - $successCount;
            if ($successCount > 0) $notice = $successCount . ' ' . ($successCount === 1 ? 'photo uploaded.' : 'photos uploaded.');
            if ($failureCount > 0) $error = $failureCount . ' ' . ($failureCount === 1 ? 'file could not be uploaded.' : 'files could not be uploaded. Check their format and size.');
        } elseif ($action === 'quick_share' || $action === 'quick_share_bulk') {
            // Gallery shortcuts: return the active share link of each photo, creating one only where none exists.
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            $rawIds = $action === 'quick_share' ? [$_POST['id'] ?? ''] : ($_POST['ids'] ?? []);
            $quickIds = is_array($rawIds) ? array_slice(array_values(array_unique(array_filter($rawIds, 'is_string'))), 0, 200) : [];
            $quickLinks = [];
            $quickSkipped = 0;
            try {
                foreach ($quickIds as $quickId) {
                    $link = ensureShareLink($pdo, $currentUser, $quickId, time());
                    if ($link === null) { $quickSkipped++; continue; }
                    $quickLinks[] = $link + ['id' => $quickId];
                }
            } catch (Throwable $exception) {
                error_log('phpSimpleImageSharing quick share error: ' . $exception->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'The share link could not be created.']); exit;
            }
            if ($quickLinks === []) {
                http_response_code(403);
                echo json_encode(['error' => 'You cannot share this photo.']); exit;
            }
            echo json_encode($action === 'quick_share'
                ? ['path' => $quickLinks[0]['path'], 'created' => $quickLinks[0]['created']]
                : ['links' => $quickLinks, 'skipped' => $quickSkipped]);
            exit;
        } elseif ($action === 'create_share' || $action === 'revoke_share' || $action === 'set_retention') {
            $mediaId = (string)($_POST['id'] ?? '');
            if (!preg_match('/\A[a-f0-9]{32,64}\z/', $mediaId)) throw new InvalidArgumentException('Invalid media ID.');
            $findMedia = $pdo->prepare('SELECT id, owner_id, expires_at FROM media WHERE id = :id LIMIT 1');
            $findMedia->execute([':id' => $mediaId]);
            $sharedMedia = $findMedia->fetch(PDO::FETCH_ASSOC);
            if (!is_array($sharedMedia) || ($currentUser['role'] !== 'admin' && $sharedMedia['owner_id'] !== $currentUser['id'])) {
                throw new RuntimeException('You cannot change this photo.');
            }
            if ($action === 'revoke_share') {
                $revoke = $pdo->prepare('UPDATE share_links SET revoked_at = :revoked_at WHERE media_id = :media_id AND revoked_at IS NULL');
                $revoke->execute([':revoked_at' => time(), ':media_id' => $mediaId]);
                $_SESSION['flash_notice'] = 'Sharing stopped. The link no longer works.';
            } elseif ($action === 'set_retention' && ($_POST['retention'] ?? '') === 'keep') {
                $_SESSION['flash_notice'] = 'Nothing changed.';
            } elseif ($action === 'set_retention') {
                $retentionDays = (string)($_POST['retention'] ?? '');
                if (!array_key_exists($retentionDays, retentionOptions())) throw new InvalidArgumentException('Choose a valid retention period.');
                $newExpiry = $retentionDays === 'never' ? null : time() + ((int)$retentionDays * 86400);
                $pdo->beginTransaction();
                try {
                    // The active share link always expires together with the photo.
                    $pdo->prepare('UPDATE media SET expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => $newExpiry, ':id' => $mediaId]);
                    $pdo->prepare('UPDATE share_links SET expires_at = :expires_at WHERE media_id = :id AND revoked_at IS NULL')->execute([':expires_at' => $newExpiry, ':id' => $mediaId]);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
                $_SESSION['flash_notice'] = $newExpiry === null ? 'This photo is now kept until someone deletes it.' : 'This photo will be deleted on ' . date('Y-m-d', $newExpiry) . '.';
            } else {
                $tokenValue = generateShareToken();
                $now = time();
                $pdo->beginTransaction();
                try {
                    $revoke = $pdo->prepare('UPDATE share_links SET revoked_at = :revoked_at WHERE media_id = :media_id AND revoked_at IS NULL');
                    $revoke->execute([':revoked_at' => $now, ':media_id' => $mediaId]);
                    $insert = $pdo->prepare(
                        'INSERT INTO share_links (id, media_id, token_hash, token, created_at, expires_at, revoked_at)
                         VALUES (:id, :media_id, :token_hash, :token, :created_at, :expires_at, NULL)'
                    );
                    $insert->execute([
                        ':id' => bin2hex(random_bytes(16)), ':media_id' => $mediaId,
                        ':token_hash' => hash('sha256', $tokenValue), ':token' => $tokenValue, ':created_at' => $now,
                        ':expires_at' => $sharedMedia['expires_at'],
                    ]);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
                $_SESSION['flash_notice'] = 'New share link created. The old link no longer works.';
            }
            $returnQuery = [
                'action' => 'detail',
                'id' => $mediaId,
                'q' => is_string($_POST['return_q'] ?? null) ? substr((string)$_POST['return_q'], 0, 120) : '',
                'sort' => is_string($_POST['return_sort'] ?? null) && $_POST['return_sort'] === 'oldest' ? 'oldest' : 'newest',
                'filter' => is_string($_POST['return_filter'] ?? null) && in_array($_POST['return_filter'], ['mine', 'expiring'], true) ? $_POST['return_filter'] : 'all',
                'page' => is_scalar($_POST['return_page'] ?? null) ? max(1, (int)$_POST['return_page']) : 1,
            ];
            header('Location: ./?' . cleanQuery($returnQuery));
            exit;
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $statement = $pdo->prepare('SELECT owner_id, storage_name FROM media WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $id]);
            $media = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($media) && ($currentUser['role'] === 'admin' || $media['owner_id'] === $currentUser['id'])) {
                $mediaStorage->delete((string)$media['owner_id'], (string)$media['storage_name']);
                $delete = $pdo->prepare('DELETE FROM media WHERE id = :id');
                $delete->execute([':id' => $id]);
                $_SESSION['flash_notice'] = 'Photo deleted.';
            } else {
                $_SESSION['flash_error'] = 'This photo could not be deleted.';
            }
            header('Location: ./'); exit;
        } elseif ($action === 'create_user') {
            Auth::createUser($pdo, $currentUser, (string)($_POST['username'] ?? ''), (string)($_POST['display_name'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['role'] ?? 'member'));
            $notice = 'Account created.';
            $action = 'users';
        } elseif ($action === 'reset_user_password') {
            Auth::resetPassword($pdo, $currentUser, (string)($_POST['user_id'] ?? ''), (string)($_POST['new_password'] ?? ''));
            $notice = 'Password updated.';
            $action = 'users';
        } elseif ($action === 'set_user_active') {
            Auth::setActive($pdo, $currentUser, (string)($_POST['user_id'] ?? ''), (string)($_POST['active'] ?? '') === '1');
            $notice = 'Account status updated.';
            $action = 'users';
        }
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $error = $exception->getMessage();
        if (in_array($action, ['create_share', 'revoke_share', 'set_retention'], true) && is_string($_POST['id'] ?? null)) { $action = 'detail'; $_GET['id'] = $_POST['id']; }
        if (in_array($action, ['create_user', 'reset_user_password', 'set_user_active'], true)) $action = 'users';
    } catch (Throwable $exception) {
        error_log('phpSimpleImageSharing request error: ' . $exception->getMessage());
        $error = 'The request could not be completed. Check the server log for details.';
        if (in_array($action, ['create_user', 'reset_user_password', 'set_user_active'], true)) $action = 'users';
    }
}

$images = [];
$sharedIds = [];
try {
    $query = $pdo->prepare(
        'SELECT media.id, media.owner_id, media.storage_name, media.original_name, media.mime, media.size_bytes, media.created_at, media.expires_at,
                users.display_name, users.username, users.role AS owner_role
         FROM media JOIN users ON users.id = media.owner_id
         WHERE media.expires_at IS NULL OR media.expires_at > :now
         ORDER BY media.created_at DESC, media.id DESC'
    );
    $query->execute([':now' => time()]);
    $images = array_values(array_filter(
        $query->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row): bool => mayViewMedia($currentUser, (string)$row['owner_id'], (string)$row['owner_role'])
    ));
    // Only the ids of photos with an active share link; tokens never go into the gallery page.
    $activeShares = $pdo->prepare('SELECT DISTINCT media_id FROM share_links WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)');
    $activeShares->execute([':now' => time()]);
    $sharedIds = array_flip($activeShares->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $exception) {
    $error = 'The gallery could not be loaded.';
    error_log('phpSimpleImageSharing gallery error: ' . $exception->getMessage());
}
$rawSort = $_GET['sort'] ?? 'newest';
$sortOrder = is_string($rawSort) && $rawSort === 'oldest' ? 'oldest' : 'newest';
$rawSearch = $_GET['q'] ?? '';
$searchTerm = is_string($rawSearch) ? trim(substr($rawSearch, 0, 120)) : '';
if ($searchTerm !== '') {
    $images = array_values(array_filter($images, static fn(array $image): bool => stripos((string)$image['original_name'], $searchTerm) !== false));
}
$rawFilter = $_GET['filter'] ?? 'all';
$filter = is_string($rawFilter) && in_array($rawFilter, ['mine', 'expiring'], true) ? $rawFilter : 'all';
if ($filter === 'mine') {
    $images = array_values(array_filter($images, static fn(array $image): bool => $image['owner_id'] === $currentUser['id']));
} elseif ($filter === 'expiring') {
    $images = array_values(array_filter($images, static fn(array $image): bool => $image['expires_at'] !== null && (int)$image['expires_at'] - time() < 3 * 86400));
}
usort($images, static function (array $left, array $right) use ($sortOrder): int {
    $comparison = ((int)$left['created_at'] <=> (int)$right['created_at']) ?: strcmp((string)$left['id'], (string)$right['id']);
    return $sortOrder === 'newest' ? -$comparison : $comparison;
});
$totalImageCount = count($images);
$imagesPerPage = 36;
$pageCount = max(1, (int)ceil($totalImageCount / $imagesPerPage));
$rawPage = $_GET['page'] ?? 1;
$galleryPage = min($pageCount, max(1, is_scalar($rawPage) ? (int)$rawPage : 1));
$allGalleryImages = $images;
$listParams = ['q' => $searchTerm, 'sort' => $sortOrder, 'filter' => $filter, 'page' => $galleryPage];
$images = array_slice($images, ($galleryPage - 1) * $imagesPerPage, $imagesPerPage);
if ($action === 'users' && $currentUser['role'] === 'admin') {
    $users = Auth::listUsers($pdo, $currentUser);
    $storageStats = $pdo->query('SELECT COUNT(*) AS media_count, COALESCE(SUM(size_bytes), 0) AS media_bytes FROM media')->fetch(PDO::FETCH_ASSOC);
    $uploadLimit = iniBytes((string)ini_get('upload_max_filesize'));
    $postLimitValue = (string)ini_get('post_max_size');
    $postLimit = trim($postLimitValue) === '0' ? PHP_INT_MAX : iniBytes($postLimitValue);
    $postLimitLabel = in_array(trim($postLimitValue), ['0', '-1'], true) ? 'unlimited' : $postLimitValue;
    $httpsActive = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $uploadLimitsOkay = $maxBytes > 0 && $uploadLimit >= $maxBytes && $postLimit >= $maxBytes + 1048576;
    $systemChecks = [
        ['ok' => version_compare(PHP_VERSION, '8.4.0', '>='), 'label' => 'PHP version', 'detail' => PHP_VERSION . ' · requires 8.4+'],
        ['ok' => extension_loaded('pdo_sqlite'), 'label' => 'PDO SQLite', 'detail' => extension_loaded('pdo_sqlite') ? 'Available' : 'Enable pdo_sqlite in the hosting control panel'],
        ['ok' => extension_loaded('fileinfo'), 'label' => 'File inspection', 'detail' => extension_loaded('fileinfo') ? 'Available' : 'Enable fileinfo in the hosting control panel'],
        ['ok' => extension_loaded('session'), 'label' => 'Sessions', 'detail' => extension_loaded('session') ? 'Available' : 'Enable the session extension in the hosting control panel'],
        ['ok' => $httpsActive, 'label' => 'HTTPS', 'detail' => $httpsActive ? 'Connection is encrypted' : 'Enable HTTPS for this domain'],
        ['ok' => $uploadLimitsOkay, 'label' => 'Upload limits', 'detail' => ($maxBytes > 0 ? 'Maximum image size: ' . formatBytes($maxBytes) . ' per file. PHP upload_max_filesize: ' . ini_get('upload_max_filesize') . '; post_max_size: ' . $postLimitLabel . ' (allow 1 MiB above the file limit for form data).' : 'Set max_upload_bytes to a positive value in config.php')],
        ['ok' => true, 'label' => 'Private storage', 'detail' => 'Writable and outside the public web root'],
    ];
    $createFailed = ($_GET['action'] ?? '') === 'create_user' && $error !== '';
    pageStart('People');
    if ($error) echo '<p class="banner error" role="alert">' . h($error) . '</p>';
    if ($notice) echo '<p class="banner" role="status">' . h($notice) . '</p>';
    ?>
<div class="title-row">
  <div><h1>People</h1><p class="sub">Members see all photos except those of guests. Guests see only their own photos. Only administrators see everything.</p></div>
  <a class="btn" href="./"><?= icon('back') ?>Back to photos</a>
</div>
<div class="cols">
  <div>
    <details class="add-person"<?= $createFailed ? ' open' : '' ?>>
      <summary class="btn primary"><?= icon('plus') ?>Add person</summary>
      <form method="post" action="?action=create_user">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <div class="form-grid">
          <label>Username<input name="username" required minlength="3" maxlength="32" autocomplete="off"></label>
          <label>Display name<input name="display_name" required maxlength="80"></label>
          <label>Temporary password<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
          <label>Role<select name="role"><option value="member">Member</option><option value="guest">Guest (sees only own photos)</option><option value="admin">Administrator</option></select></label>
        </div>
        <p class="hint">Passwords need at least 12 characters.</p>
        <div class="foot" style="justify-content:flex-start"><button class="btn primary">Create account</button></div>
      </form>
    </details>
    <div class="list">
<?php foreach ($users as $account):
        $isSelf = (string)$account['id'] === (string)$currentUser['id'];
        $isActive = (int)$account['active'] === 1; ?>
      <div class="person">
        <?= avatar((string)$account['id'], (string)$account['display_name']) ?>
        <div class="n"><b><?= h((string)$account['display_name']) ?></b><span>@<?= h((string)$account['username']) ?></span></div>
        <span class="pill<?= $account['role'] === 'admin' ? ' admin' : '' ?>"><?= h(roleLabel((string)$account['role'])) ?></span>
        <?php if (!$isActive): ?><span class="pill off">Disabled</span><?php endif; ?>
        <details class="reset">
          <summary class="btn sm">Reset password</summary>
          <form class="reset-form" method="post" action="?action=reset_user_password">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="user_id" value="<?= h((string)$account['id']) ?>">
            <input type="password" name="new_password" required minlength="12" autocomplete="new-password" placeholder="New password, 12+ characters" aria-label="New password for <?= h((string)$account['display_name']) ?>">
            <button class="btn primary sm">Save password</button>
          </form>
        </details>
        <?php if (!$isSelf): ?>
        <form method="post" action="?action=set_user_active">
          <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
          <input type="hidden" name="user_id" value="<?= h((string)$account['id']) ?>">
          <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
          <button class="btn sm ghost<?= $isActive ? ' danger' : '' ?>"><?= $isActive ? 'Disable' : 'Enable' ?></button>
        </form>
        <?php endif; ?>
      </div>
<?php endforeach; ?>
    </div>
  </div>
  <aside class="side">
    <h2>System check</h2>
    <ul class="chk-l">
<?php foreach ($systemChecks as $check): ?>
      <li><span class="d <?= $check['ok'] ? 'ok' : 'bad' ?>" aria-hidden="true"><?= $check['ok'] ? icon('check') : '!' ?></span><div><?= h($check['label']) ?> <span class="sr"><?= $check['ok'] ? 'OK' : 'Action needed' ?></span><small><?= h($check['detail']) ?></small></div></li>
<?php endforeach; ?>
    </ul>
    <p class="sub" style="margin-top:18px"><b style="color:var(--ink)"><?= h(formatBytes((int)($storageStats['media_bytes'] ?? 0))) ?></b> used by <?= (int)($storageStats['media_count'] ?? 0) ?> files</p>
  </aside>
</div>
<?php
    pageEnd(); exit;
}
if ($action === 'detail') {
    $rawDetailId = $_GET['id'] ?? '';
    $detailId = is_string($rawDetailId) ? $rawDetailId : '';
    $detailIndex = null;
    foreach ($allGalleryImages as $index => $image) {
        if (hash_equals((string)$image['id'], $detailId)) { $detailIndex = $index; break; }
    }
    if ($detailIndex === null) {
        http_response_code(404);
        pageStart('Photo not found');
        ?>
<div class="empty"><h2>This photo is not available</h2><p>It may have been deleted or has expired.</p><p><a class="btn" href="./">Back to photos</a></p></div>
<?php
        pageEnd(); exit;
    }
    $detail = $allGalleryImages[$detailIndex];
    $detailLink = static fn(array $target): string => '?' . cleanQuery(['action' => 'detail', 'id' => $target['id']] + $listParams);
    $previousUrl = $detailIndex > 0 ? $detailLink($allGalleryImages[$detailIndex - 1]) : null;
    $nextUrl = $detailIndex + 1 < count($allGalleryImages) ? $detailLink($allGalleryImages[$detailIndex + 1]) : null;
    $backUrl = '?' . cleanQuery($listParams);
    $detailImageUrl = '?action=image&id=' . rawurlencode((string)$detail['id']);
    $canManage = $currentUser['role'] === 'admin' || $detail['owner_id'] === $currentUser['id'];
    $detailExpiry = expiryInfo($detail, time());
    $shownShare = '';
    $hasShare = false;
    if ($canManage) {
        $shareStatement = $pdo->prepare('SELECT token FROM share_links WHERE media_id = :id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now) ORDER BY created_at DESC LIMIT 1');
        $shareStatement->execute([':id' => (string)$detail['id'], ':now' => time()]);
        $shareRow = $shareStatement->fetch(PDO::FETCH_ASSOC);
        $hasShare = is_array($shareRow);
        // Links created before tokens were stored have an empty token and cannot be shown again.
        if ($hasShare && (string)$shareRow['token'] !== '') $shownShare = sharePath((string)$shareRow['token']);
    }
    pageStart((string)$detail['original_name'], 'viewer');
    ?>
<div class="vwrap">
  <div class="vtop">
    <a class="icon-btn" href="<?= h($backUrl) ?>" aria-label="Back to photos"><?= icon('back') ?></a>
    <div class="t"><b><?= h((string)$detail['original_name']) ?></b></div>
    <span class="cnt"><?= $detailIndex + 1 ?> of <?= count($allGalleryImages) ?></span>
  </div>
  <div class="vbody">
    <div class="stage">
      <img src="<?= h($detailImageUrl) ?>" alt="<?= h((string)$detail['original_name']) ?>">
      <?php if ($previousUrl !== null): ?><a class="nav l" href="<?= h($previousUrl) ?>" rel="prev" aria-label="Previous photo"><?= icon('back') ?></a><?php endif; ?>
      <?php if ($nextUrl !== null): ?><a class="nav r" href="<?= h($nextUrl) ?>" rel="next" aria-label="Next photo"><?= icon('next') ?></a><?php endif; ?>
    </div>
    <aside class="panel">
      <?php if ($error || $notice): ?><p class="banner<?= $error ? ' error' : '' ?> js-hide" role="<?= $error ? 'alert' : 'status' ?>" data-toast="<?= h((string)($error ?: $notice)) ?>" data-toast-ms="<?= $error ? 5000 : 2400 ?>"><?= h((string)($error ?: $notice)) ?></p><?php endif; ?>
<?php if ($canManage) { $shareFields = '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . h((string)$detail['id']) . '"><input type="hidden" name="return_q" value="' . h($searchTerm) . '"><input type="hidden" name="return_sort" value="' . h($sortOrder) . '"><input type="hidden" name="return_filter" value="' . h($filter) . '"><input type="hidden" name="return_page" value="' . (int)$galleryPage . '">'; } ?>
      <section class="pcard">
        <dl>
          <dt>File name</dt><dd class="fname"><?= h((string)$detail['original_name']) ?></dd>
          <dt>Added by</dt><dd><?= h((string)$detail['display_name']) ?></dd>
          <dt>Date</dt><dd><?= h(date('Y-m-d', (int)$detail['created_at'])) ?></dd>
          <dt>Size</dt><dd><?= h(formatBytes((int)$detail['size_bytes'])) ?></dd>
          <dt>Deleted on</dt><dd><?= $detailExpiry === null ? 'Kept until deleted' : h($detailExpiry['date'] . ' (' . $detailExpiry['label'] . ')') ?></dd>
        </dl>
<?php if ($canManage): ?>
        <form class="keep-form" method="post" action="?action=set_retention">
          <?= $shareFields ?>
          <label for="retentionSelect">Keep this photo</label>
          <div class="link">
            <select class="field" id="retentionSelect" name="retention">
              <option value="keep" selected>Keep as is (<?= $detailExpiry === null ? 'forever' : h($detailExpiry['date']) ?>)</option>
<?php foreach (retentionOptions() as $retentionValue => $retentionLabel): ?>
              <option value="<?= h((string)$retentionValue) ?>"><?= h($retentionLabel) ?></option>
<?php endforeach; ?>
            </select>
            <button class="btn primary sm">Save</button>
          </div>
          <p class="hint">Counts from today. The photo and its share link are deleted at the end. Choose Forever to turn automatic deletion off.</p>
        </form>
<?php endif; ?>
      </section>
<?php if ($canManage): ?>
      <section class="pcard">
        <h2>Share link</h2>
<?php if ($shownShare !== ''): ?>
        <div class="link"><input id="shareUrl" class="share-url" aria-label="Share link" readonly value="<?= h($shownShare) ?>" data-path="<?= h($shownShare) ?>"><button type="button" class="btn primary sm" data-copy-link="<?= h($shownShare) ?>">Copy</button></div>
        <p class="hint">Anyone with the link can see this photo until it is deleted. A new link stops the old one from working.</p>
<?php endif; ?>
        <div class="stack">
          <form method="post" action="?action=create_share">
            <?= $shareFields ?>
            <button class="btn<?= $shownShare === '' ? ' primary' : '' ?>"><?= icon('link') ?><?= $hasShare ? 'Create new share link' : 'Create share link' ?></button>
          </form>
<?php if ($hasShare): ?>
          <form method="post" action="?action=revoke_share" data-confirm="Stop sharing this photo?" data-confirm-text="The current share link stops working. You can create a new one later." data-confirm-label="Stop sharing">
            <?= $shareFields ?>
            <button class="btn danger"><?= icon('close') ?>Stop sharing</button>
          </form>
<?php endif; ?>
        </div>
      </section>
<?php endif; ?>
      <div class="stack">
        <a class="btn" href="?action=download&amp;id=<?= h(rawurlencode((string)$detail['id'])) ?>"><?= icon('download') ?>Download original</a>
        <button type="button" class="btn" data-copy-link="<?= h($detailImageUrl) ?>"><?= icon('copy') ?>Copy link for signed-in friends</button>
<?php if ($canManage): ?>
        <form method="post" action="?action=delete" data-confirm="<?= h('Delete ' . (string)$detail['original_name'] . '?') ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
          <input type="hidden" name="id" value="<?= h((string)$detail['id']) ?>">
          <button class="btn danger"><?= icon('trash') ?>Delete photo</button>
        </form>
<?php endif; ?>
      </div>
    </aside>
  </div>
</div>
<?php
    pageEnd(); exit;
}
$now = time();
$photoWord = $totalImageCount === 1 ? 'photo' : 'photos';
$pageQuery = static fn(int $page): string => '?' . cleanQuery(['q' => $searchTerm, 'sort' => $sortOrder, 'filter' => $filter, 'page' => $page]);
pageStart('Photos');
if ($error) echo '<p class="banner error" role="alert">' . h($error) . '</p>';
if ($notice) echo '<p class="banner" role="status">' . h($notice) . '</p>';
?>
<div class="title-row">
  <div><h1>Photos</h1><p class="sub"><?= $totalImageCount ?> <?= $photoWord ?><?= $searchTerm !== '' ? ' matching “' . h($searchTerm) . '”' : '' ?></p></div>
  <button class="btn primary" type="button" data-add-photos><?= icon('plus') ?>Add photos</button>
</div>
<form class="tools" method="get" action="./">
  <button class="sr" name="filter" value="<?= h($filter) ?>" id="applyFilters" tabindex="-1" aria-hidden="true">Apply</button>
  <div class="seg" role="group" aria-label="Show">
    <button name="filter" value="all" aria-pressed="<?= $filter === 'all' ? 'true' : 'false' ?>">Everyone</button>
    <button name="filter" value="mine" aria-pressed="<?= $filter === 'mine' ? 'true' : 'false' ?>">Mine</button>
    <button name="filter" value="expiring" aria-pressed="<?= $filter === 'expiring' ? 'true' : 'false' ?>">Expiring soon</button>
  </div>
  <label class="search"><span class="sr">Search file names</span><?= icon('search') ?><input type="search" name="q" maxlength="120" value="<?= h($searchTerm) ?>" placeholder="Search file names"></label>
  <label><span class="sr">Sort</span><select class="field" name="sort" id="gallerySort"><option value="newest"<?= $sortOrder === 'newest' ? ' selected' : '' ?>>Newest first</option><option value="oldest"<?= $sortOrder === 'oldest' ? ' selected' : '' ?>>Oldest first</option></select></label>
  <noscript><button class="btn sm" name="filter" value="<?= h($filter) ?>">Apply</button></noscript>
</form>
<div class="grid" id="grid">
  <button class="drop" type="button" data-add-photos><?= icon('upload') ?><strong>Add photos</strong><span>or drop them here</span></button>
<?php foreach ($images as $image):
    $id = (string)$image['id'];
    $name = (string)$image['original_name'];
    $imageUrl = '?action=image&id=' . rawurlencode($id);
    $detailUrl = '?' . cleanQuery(['action' => 'detail', 'id' => $id, 'q' => $searchTerm, 'sort' => $sortOrder, 'filter' => $filter, 'page' => $galleryPage]);
    $expiry = expiryInfo($image, $now);
    $canShare = $currentUser['role'] === 'admin' || $image['owner_id'] === $currentUser['id'];
    $isShared = $canShare && isset($sharedIds[$id]);
    $caption = (string)$image['display_name'] . ', ' . date('Y-m-d', (int)$image['created_at']) . ($expiry !== null ? ', ' . $expiry['label'] : ''); ?>
  <article class="tile">
    <a class="tile-link" href="<?= h($detailUrl) ?>" aria-label="<?= h($name . ', added by ' . (string)$image['display_name']) ?>"><img loading="lazy" decoding="async" src="<?= h($imageUrl) ?>" alt=""></a>
    <label class="pick"><input type="checkbox" form="bulkActionsForm" name="ids[]" value="<?= h($id) ?>" aria-label="Select <?= h($name) ?>"><span class="circle"><?= icon('check') ?></span></label>
    <?= avatar((string)$image['owner_id'], (string)$image['display_name'], 'av by') ?>
    <?php if ($canShare): ?><span class="shr" title="Has a share link"<?= $isShared ? '' : ' hidden' ?>><?= icon('link') ?><span class="sr">Has a share link</span></span>
    <button type="button" class="qshare" data-quick-share="<?= h($id) ?>" title="Copy share link" aria-label="Copy share link for <?= h($name) ?>"><?= icon('link') ?></button><?php endif; ?>
    <?php if ($expiry !== null && $expiry['soon']): ?><span class="badge"><?= h($expiry['label']) ?></span><?php endif; ?>
    <?php if ($expiry !== null): ?><span class="life<?= $expiry['soon'] ? ' soon' : '' ?>" aria-hidden="true"><i style="width:<?= (int)round($expiry['fraction'] * 100) ?>%"></i></span><?php endif; ?>
    <span class="cap"><b><?= h($name) ?></b><?= h($caption) ?></span>
  </article>
<?php endforeach; ?>
</div>
<?php if (!$images): ?>
<div class="empty"><h2><?= $searchTerm !== '' || $filter !== 'all' ? 'No photos match' : 'No photos yet' ?></h2><p><?= $searchTerm !== '' || $filter !== 'all' ? 'Try another search or filter.' : 'Add the first photos to get started.' ?></p></div>
<?php endif; ?>
<?php if ($pageCount > 1): ?>
<nav class="pager" aria-label="Gallery pages">
  <?php if ($galleryPage > 1): ?><a class="btn sm" href="<?= h($pageQuery($galleryPage - 1)) ?>">Previous</a><?php endif; ?>
  <span>Page <?= $galleryPage ?> of <?= $pageCount ?></span>
  <?php if ($galleryPage < $pageCount): ?><a class="btn sm" href="<?= h($pageQuery($galleryPage + 1)) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if ($images): ?>
<form class="selbar" id="bulkActionsForm" method="post" action="?action=bulk">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="return_q" value="<?= h($searchTerm) ?>"><input type="hidden" name="return_sort" value="<?= h($sortOrder) ?>"><input type="hidden" name="return_filter" value="<?= h($filter) ?>"><input type="hidden" name="return_page" value="<?= $galleryPage ?>">
  <b id="selectedCount" role="status" aria-live="polite">0 selected</b>
  <button type="button" class="btn sm" id="selectVisible">Select page</button>
  <button class="btn sm" name="bulk_action" value="share" title="Copy share links. Photos without one get a new link."><?= icon('link') ?>Share links</button>
  <button class="btn sm" name="bulk_action" value="copy"><?= icon('copy') ?>Copy links</button>
  <button class="btn sm" name="bulk_action" value="delete"><?= icon('trash') ?>Delete</button>
  <button type="button" class="icon-btn" id="clearSel" aria-label="Clear selection"><?= icon('close') ?></button>
</form>
<?php endif; ?>
<input class="sr" id="imageFiles" type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
<dialog class="modal" id="uploadDialog" aria-labelledby="uploadTitle">
  <form class="sheet-in" id="uploadForm" method="post" action="?action=upload" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <h2 id="uploadTitle">Add photos</h2>
    <p class="sub">JPEG, PNG, GIF or WebP, up to <?= h((string)round($maxBytes / 1024 / 1024)) ?> MiB each.</p>
    <button type="button" class="zone" data-add-photos><?= icon('upload') ?><strong>Drop photos here</strong><span>or choose from your device</span></button>
    <div class="queue" id="uploadQueue" hidden><div class="queue-summary" id="queueSummary" aria-live="polite"></div><div class="queue-items" id="queueItems"></div></div>
<?php $savedRetention = userDefaultRetention($pdo, (string)$currentUser['id']); ?>
    <fieldset class="keep" data-default="<?= h($savedRetention) ?>">
      <legend>Keep these photos</legend>
      <div class="seg wrap">
<?php foreach (retentionOptions() as $retentionValue => $retentionLabel): ?>
        <label><input type="radio" name="retention" value="<?= h((string)$retentionValue) ?>"<?= (string)$retentionValue === $savedRetention ? ' checked' : '' ?>><span><?= h($retentionLabel) ?></span></label>
<?php endforeach; ?>
      </div>
      <p class="hint" id="retentionHint"></p>
      <label class="remember" id="rememberRow" hidden><input type="checkbox" id="rememberDefault"> Use this as my default</label>
    </fieldset>
    <div class="foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary" id="uploadButton" disabled>Upload</button></div>
  </form>
</dialog>
<?php
pageEnd();

/** Inline icons (Lucide-style strokes). All markup here is static. */
function icon(string $name): string {
    static $paths = [
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'check' => '<path d="m5 12 5 5 9-10"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 16v4h16v-4"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'back' => '<path d="M15 5l-7 7 7 7"/>',
        'next' => '<path d="M9 5l7 7-7 7"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.7c2 .6 3.3 2.3 3.5 5.3"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.1 1-1.1 1.8M12 17h.01"/>',
        'logout' => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5Z"/>',
        'auto' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/><path d="M12 3a9 9 0 0 1 0 18" fill="currentColor" stroke="none"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function brandMark(string $class = 'mark'): string {
    return '<svg class="' . h($class) . '" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9"/><path d="M12 24V8h6a5 5 0 0 1 0 10h-6"/></svg>';
}

function avatarInitials(string $name): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches);
    $words = $matches[0] ?? [];
    if ($words === []) return '?';
    if (count($words) === 1) preg_match('/^.{1,2}/us', $words[0], $part);
    else { preg_match('/^./us', $words[0], $first); preg_match('/^./us', $words[1], $second); $part = [($first[0] ?? '') . ($second[0] ?? '')]; }
    $initials = (string)($part[0] ?? '?');
    return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
}

function avatar(string $userId, string $displayName, string $class = 'av'): string {
    $hue = crc32($userId) % 360;
    return '<span class="' . h($class) . '" style="background:hsl(' . $hue . ' 42% 40%)" title="' . h($displayName) . '" aria-hidden="true">' . h(avatarInitials($displayName)) . '</span>';
}

/** Remaining lifetime of a photo, or null if it is kept forever. */
function expiryInfo(array $image, int $now): ?array {
    if ($image['expires_at'] === null) return null;
    $expires = (int)$image['expires_at'];
    $remaining = max(0, $expires - $now);
    $total = max(1, $expires - (int)$image['created_at']);
    if ($remaining >= 2 * 86400) $label = intdiv($remaining, 86400) . ' days left';
    elseif ($remaining >= 3600) { $hours = intdiv($remaining, 3600); $label = $hours . ($hours === 1 ? ' hour left' : ' hours left'); }
    else $label = 'Under 1 hour left';
    return ['fraction' => min(1.0, $remaining / $total), 'label' => $label, 'soon' => $remaining < 86400, 'date' => date('Y-m-d', $expires)];
}

/** Build a short query string without default values. */
function cleanQuery(array $params): string {
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'sort' && $value === 'newest') || ($key === 'filter' && $value === 'all') || ($key === 'page' && (int)$value === 1)) unset($params[$key]);
    }
    return http_build_query($params);
}

/** Retention choices in days (keys) with their labels. Months are counted as 30 days. */
function retentionOptions(): array {
    return ['never' => 'Forever', '1' => '1 day', '7' => '7 days', '30' => '30 days', '60' => '2 months', '90' => '3 months', '180' => '6 months'];
}

/**
 * Who may see a photo: admins see everything, owners see their own, members see everything
 * except photos of guests, and guests see only their own photos.
 */
function mayViewMedia(array $viewer, string $ownerId, string $ownerRole): bool {
    if ($viewer['role'] === 'admin' || $viewer['id'] === $ownerId) return true;
    return $viewer['role'] === 'member' && $ownerRole !== 'guest';
}

/** Human-readable name of a role. */
function roleLabel(string $role): string {
    return ['admin' => 'Administrator', 'guest' => 'Guest'][$role] ?? 'Member';
}

/** The retention period a user preselects for new uploads; falls back to Forever. */
function userDefaultRetention(PDO $pdo, string $userId): string {
    $statement = $pdo->prepare('SELECT default_retention FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $userId]);
    $value = (string)$statement->fetchColumn();
    return array_key_exists($value, retentionOptions()) ? $value : 'never';
}

function pageStart(string $title, string $variant = 'app'): void {
    $user = isset($GLOBALS['currentUser']) && is_array($GLOBALS['currentUser']) ? $GLOBALS['currentUser'] : null;
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>' . h($title) . ' · phpSimpleImageSharing</title>';
    echo '<script>(()=>{const r=document.documentElement;r.classList.add("js");let t="system";try{const s=localStorage.getItem("phpSimpleImageSharing-theme");if(s==="light"||s==="dark")t=s}catch{}if(t!=="system")r.dataset.theme=t;r.dataset.mode=t})()</script><style>';
    echo <<<'CSS'
:root{--bg:#f4f5f7;--surface:#fff;--raised:#fff;--ink:#14171f;--muted:#667085;--line:#e3e5ea;--quiet:#eceef2;--accent:#3b4bff;--accent-ink:#fff;--tint:#e9ebff;--warn:#b86a00;--warn-bg:#fff1dc;--danger:#c4261d;--ok:#18794e;--r-ctl:10px;--font:ui-sans-serif,system-ui,-apple-system,"Segoe UI Variable Text","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;color-scheme:light}
:root[data-theme=dark]{color-scheme:dark;--bg:#0f1115;--surface:#171a20;--raised:#1d2129;--ink:#eceef3;--muted:#98a0b0;--line:#2a2f39;--quiet:#222731;--accent:#8590ff;--accent-ink:#0d1030;--tint:#262b52;--warn:#f0b35a;--warn-bg:#3a2d17;--danger:#ff7a70;--ok:#5fd29a}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]):not([data-theme=dark]){color-scheme:dark;--bg:#0f1115;--surface:#171a20;--raised:#1d2129;--ink:#eceef3;--muted:#98a0b0;--line:#2a2f39;--quiet:#222731;--accent:#8590ff;--accent-ink:#0d1030;--tint:#262b52;--warn:#f0b35a;--warn-bg:#3a2d17;--danger:#ff7a70;--ok:#5fd29a}}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 var(--font);min-height:100vh}
button,input,select{font:inherit;color:inherit}
button{cursor:pointer}
a{color:var(--accent)}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
svg.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.mark{width:28px;height:28px;flex:none}.mark rect{fill:var(--accent)}.mark path{fill:none;stroke:var(--accent-ink);stroke-width:3.4;stroke-linecap:round;stroke-linejoin:round}

.bar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:8px;height:56px;padding:0 16px;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:9px;font-weight:700;letter-spacing:-.02em;text-decoration:none;color:inherit}
.bar .sp{flex:1}
.icon-btn{display:inline-grid;place-items:center;width:38px;height:38px;border:0;border-radius:999px;background:transparent;color:inherit;text-decoration:none}
.icon-btn:hover{background:var(--quiet)}
#themeToggle .ti{display:none}
#themeToggle{padding:0}#themeToggle .ti svg{display:block}
:root[data-mode=system] #themeToggle .ti-system,:root[data-mode=light] #themeToggle .ti-light,:root[data-mode=dark] #themeToggle .ti-dark{display:block}
.acct{position:relative}
.acct summary{list-style:none;display:flex;align-items:center;gap:8px;padding:4px 6px 4px 4px;border-radius:999px;cursor:pointer}
.acct summary::-webkit-details-marker{display:none}
.acct summary:hover,.acct[open] summary{background:var(--quiet)}
.av{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;font-size:11px;font-weight:700;color:#fff;flex:none;line-height:1}
.menu{position:absolute;right:0;top:46px;min-width:220px;background:var(--raised);border:1px solid var(--line);border-radius:14px;padding:6px;box-shadow:0 12px 32px rgba(0,0,0,.16)}
.menu .who{padding:8px 10px 6px;color:var(--muted);font-size:13px}.menu .who strong{display:block;color:var(--ink);font-size:14px}
.menu a,.menu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border:0;border-radius:9px;background:transparent;text-align:left;text-decoration:none;color:inherit}
.menu a:hover,.menu button:hover{background:var(--quiet)}
.menu hr{border:0;border-top:1px solid var(--line);margin:6px 0}
.menu form{margin:0}

.page{max-width:1180px;margin:0 auto;padding:22px 16px 110px}
.title-row{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
h1{font-size:28px;line-height:1.1;letter-spacing:-.03em;margin:0}
h2{margin:0}
.sub{color:var(--muted);margin:4px 0 0;font-size:14px}
.hint{color:var(--muted);font-size:13px;margin:6px 0 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 16px;border-radius:var(--r-ctl);border:1px solid var(--line);background:var(--surface);font-weight:600;text-decoration:none;color:var(--ink);list-style:none}
.btn::-webkit-details-marker{display:none}
.btn:hover{background:var(--quiet)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.danger{color:var(--danger)}
.btn.danger-solid{background:var(--danger);border-color:var(--danger);color:#fff}
.btn.ghost{border-color:transparent;background:transparent}.btn.ghost:hover{background:var(--quiet)}
.btn.sm{min-height:34px;padding:0 12px;font-size:14px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.banner{padding:12px 14px;border-radius:12px;margin:0 0 16px;font-size:14px;background:var(--tint)}
.banner.error{color:var(--danger);background:color-mix(in srgb,var(--danger) 10%,var(--surface))}

.tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.seg{display:inline-flex;padding:3px;border-radius:999px;background:var(--quiet);position:relative}
.seg button,.seg label{border:0;background:transparent;padding:6px 14px;border-radius:999px;font-weight:600;font-size:14px;color:var(--muted);position:relative;cursor:pointer}
.seg button[aria-pressed=true],.seg input:checked+span{background:var(--surface);color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.14)}
.seg.wrap{flex-wrap:wrap;border-radius:18px}.seg label{padding:0}.seg label span{display:block;padding:6px 14px;border-radius:999px}
.keep .seg{display:flex;width:100%}.keep .seg label{flex:1 1 0;min-width:72px}.keep .seg label span{text-align:center;white-space:nowrap}
.seg input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
.seg label:focus-within span{outline:2px solid var(--accent);outline-offset:2px}
.search{position:relative;flex:1;min-width:150px;max-width:340px}
.search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted)}
.search input{width:100%;height:40px;padding:0 12px 0 38px;border-radius:999px;border:1px solid var(--line);background:var(--surface)}
.field{height:40px;padding:0 12px;border-radius:var(--r-ctl);border:1px solid var(--line);background:var(--surface)}
.js .nojs{display:none}.js .js-hide{display:none}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
.tile{position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--quiet)}
.tile-link{position:absolute;inset:0;display:block}
.tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .14s}
.tile.sel img{transform:scale(.88);border-radius:8px}.tile.sel{background:var(--tint)}
.cap{position:absolute;inset:auto 0 0 0;padding:28px 10px 16px;color:#fff;font-size:12px;line-height:1.3;background:linear-gradient(transparent,rgba(0,0,0,.62));opacity:0;transition:opacity .15s;pointer-events:none}
.cap b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile:hover .cap,.tile:focus-within .cap{opacity:1}
.tile .by{position:absolute;left:8px;bottom:18px;width:22px;height:22px;font-size:9px;border:2px solid rgba(255,255,255,.92);pointer-events:none}
.tile:hover .by{opacity:0}
.tile .shr{position:absolute;right:8px;bottom:18px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.5);border:2px solid rgba(255,255,255,.92);color:#fff;display:grid;place-items:center;pointer-events:none}
.tile .shr[hidden]{display:none}.tile .shr svg{width:11px;height:11px;stroke-width:2.6}
.tile:hover .shr{opacity:0}
.qshare{position:absolute;top:8px;right:8px;z-index:2;width:30px;height:30px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;display:grid;place-items:center;cursor:pointer;opacity:0;transition:opacity .12s}
.qshare svg{width:15px;height:15px}.qshare:hover{background:var(--accent)}.qshare:disabled{cursor:progress}
.tile:hover .qshare,.tile:focus-within .qshare{opacity:1}
.tile:hover .badge{opacity:0}.grid.selecting .qshare{display:none}
@media(hover:none){.qshare{opacity:1}.tile .shr{opacity:1}.tile:hover .badge{opacity:1}.tile .qshare~.badge{right:44px}}
.life{position:absolute;left:8px;right:8px;bottom:7px;height:5px;border-radius:9px;background:rgba(0,0,0,.45);box-shadow:0 0 0 1px rgba(255,255,255,.18);pointer-events:none}
.life i{display:block;height:100%;border-radius:9px;background:#fff}
.life.soon i{background:#ffb347}
.badge{position:absolute;top:8px;right:8px;padding:2px 8px;border-radius:999px;background:var(--warn-bg);color:var(--warn);font-size:11px;font-weight:700;pointer-events:none}
.pick{position:absolute;top:8px;left:8px;width:28px;height:28px;z-index:2}
.pick input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}
.pick .circle{position:absolute;inset:0;border-radius:50%;border:2px solid #fff;background:rgba(0,0,0,.3);display:grid;place-items:center;color:transparent;opacity:0;transition:opacity .12s;pointer-events:none}
.pick .circle svg{width:14px;height:14px;stroke-width:3}
.tile:hover .circle,.tile:focus-within .circle,.grid.selecting .circle{opacity:1}
.pick input:checked+.circle{opacity:1;background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.pick input:focus-visible+.circle{outline:2px solid var(--accent);outline-offset:2px}
@media(hover:none){.pick .circle{opacity:1}.drop span{display:none}}
.drop{display:grid;place-content:center;gap:6px;text-align:center;aspect-ratio:1;border-radius:10px;border:2px dashed var(--line);background:transparent;color:var(--muted);padding:10px}
.drop:hover{border-color:var(--accent);color:var(--accent);background:var(--tint)}
.drop strong{color:var(--ink);font-size:14px}.drop svg{margin:0 auto;width:26px;height:26px}
.empty{padding:34px 8px;text-align:center;color:var(--muted)}.empty h2{font-size:18px;color:var(--ink);margin-bottom:4px}
.pager{display:flex;justify-content:center;align-items:center;gap:14px;margin:22px 0;color:var(--muted);font-size:14px}
.dragging body::after,.dragging::after{content:"Drop photos to add them";position:fixed;inset:10px;z-index:200;border:3px dashed var(--accent);border-radius:20px;background:color-mix(in srgb,var(--accent) 12%,transparent);display:grid;place-items:center;font-weight:700;font-size:20px;color:var(--accent);pointer-events:none}

.selbar{position:fixed;left:50%;bottom:18px;transform:translate(-50%,0);display:flex;align-items:center;gap:4px;padding:8px 8px 8px 16px;border-radius:999px;background:var(--ink);color:var(--bg);box-shadow:0 10px 30px rgba(0,0,0,.28);z-index:30;white-space:nowrap;max-width:calc(100% - 16px);margin:0;transition:opacity .15s,transform .15s}
.js .selbar:not(.on){opacity:0;pointer-events:none;transform:translate(-50%,20px)}
.selbar b{margin-right:6px;font-size:14px}
.selbar .btn{background:transparent;border-color:transparent;color:inherit;min-height:36px;padding:0 10px}
.selbar .btn:hover{background:rgba(127,127,127,.28)}
.selbar .icon-btn{width:36px;height:36px}

dialog.modal{border:0;padding:0;border-radius:20px;background:var(--surface);color:var(--ink);width:min(520px,calc(100% - 24px));max-height:calc(100vh - 24px);box-shadow:0 24px 60px rgba(0,0,0,.35)}
dialog.modal::backdrop{background:rgba(8,10,14,.55)}
#uploadDialog{width:min(720px,calc(100% - 24px))}
@media(max-width:640px){#uploadDialog,dialog.modal{margin:auto auto 0;border-radius:20px 20px 0 0;width:100%;max-width:none}}
.sheet-in{padding:22px 20px 20px}
.sheet-in h2{font-size:20px;letter-spacing:-.02em;margin-bottom:4px}
.foot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.foot form{display:contents}
.zone{font:inherit;background:none;width:100%;display:grid;place-items:center;gap:4px;margin:14px 0;padding:20px;border:2px dashed var(--line);border-radius:14px;text-align:center;color:var(--muted);cursor:pointer}
.zone:hover{border-color:var(--accent);background:var(--tint)}.zone strong{color:var(--ink)}.zone svg{width:26px;height:26px}
.queue{margin:6px 0}.queue-summary{font-size:13px;color:var(--muted);margin-bottom:6px}
.queue-items{display:grid;gap:6px;max-height:34vh;overflow:auto}
.qi{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:10px;align-items:center;padding:6px;border-radius:12px;background:var(--bg)}
.qi img{width:40px;height:40px;object-fit:cover;border-radius:8px;background:var(--quiet)}
.qx{width:30px;height:30px;border:0;border-radius:50%;background:none;color:var(--muted);font-size:20px;line-height:1;cursor:pointer}.qx:hover{background:var(--quiet);color:var(--danger)}dialog[data-busy] .qx{visibility:hidden}
.qn{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qs{font-size:12px;color:var(--muted)}.qs.ok{color:var(--ok)}.qs.fail{color:var(--danger)}
.qt{height:4px;border-radius:9px;background:var(--line);margin-top:5px;overflow:hidden}.qt i{display:block;height:100%;width:0;background:var(--accent);transition:width .1s}
fieldset.keep{border:0;padding:0;margin:16px 0 0}
.remember{display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px;color:var(--muted);cursor:pointer}.remember[hidden]{display:none}
fieldset.keep legend{font-weight:600;font-size:14px;margin-bottom:6px;padding:0}
.guide{display:grid;gap:10px;margin:14px 0 0;padding-left:20px;font-size:14px}.guide strong{display:block}.guide span{color:var(--muted)}

.toast{position:fixed;left:50%;bottom:76px;transform:translate(-50%,20px);padding:10px 16px;border-radius:999px;background:var(--ink);color:var(--bg);opacity:0;transition:.18s;z-index:300;pointer-events:none;font-weight:600;font-size:14px;max-width:calc(100% - 24px)}
.toast.show{opacity:1;transform:translate(-50%,0)}

.cols{display:grid;gap:28px;grid-template-columns:1fr}
@media(min-width:900px){.cols{grid-template-columns:1fr 340px}}
.list{border-top:1px solid var(--line)}
.person{display:flex;align-items:center;gap:12px;padding:14px 4px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.person .av{width:38px;height:38px;font-size:13px}
.person .n{flex:1;min-width:150px}.person .n b{display:block}.person .n span{color:var(--muted);font-size:13px}
.person form{margin:0}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;background:var(--quiet);font-size:12px;font-weight:600;color:var(--muted)}
.pill.admin{background:var(--tint);color:var(--accent)}.pill.off{background:var(--warn-bg);color:var(--warn)}
.reset[open]{flex-basis:100%}
.reset-form{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.reset-form input,.form-grid input,.form-grid select{height:40px;border-radius:var(--r-ctl);border:1px solid var(--line);background:var(--surface);padding:0 12px}
.reset-form input{flex:1;min-width:180px}
.add-person{margin-bottom:18px}.add-person summary{display:inline-flex;cursor:pointer}
.add-person form{margin-top:14px}
.form-grid{display:grid;gap:10px;grid-template-columns:1fr;margin:0 0 4px}
@media(min-width:600px){.form-grid{grid-template-columns:1fr 1fr}}
.form-grid label{display:grid;gap:5px;font-size:13px;font-weight:600}.form-grid input,.form-grid select{font-weight:400}
.side{padding:18px;border-radius:16px;background:var(--surface);border:1px solid var(--line);align-self:start}
.side h2{font-size:16px;margin-bottom:12px}
.chk-l{list-style:none;margin:0;padding:0;display:grid;gap:12px;font-size:14px}
.chk-l li{display:flex;gap:10px;align-items:flex-start}
.chk-l .d{width:20px;height:20px;border-radius:50%;display:grid;place-items:center;flex:none;font-size:12px;font-weight:800}
.chk-l .d.ok{background:color-mix(in srgb,var(--ok) 18%,var(--surface));color:var(--ok)}
.chk-l .d.bad{background:color-mix(in srgb,var(--danger) 18%,var(--surface));color:var(--danger)}
.chk-l small{display:block;color:var(--muted);overflow-wrap:anywhere}

.login{min-height:calc(100vh - 220px);display:grid;place-items:center}
.login-form{width:min(400px,100%);display:grid;gap:12px}
.login-form h1{font-size:34px}
.login-form label{display:grid;gap:6px;font-weight:600;font-size:14px}
.login-form input{height:46px;border-radius:12px;border:1px solid var(--line);background:var(--surface);padding:0 14px;font-weight:400}
.login-form .btn{min-height:46px}

body.viewer-page{background:#0c0e12;color:#eceef3}
.viewer-page :focus-visible{outline-color:#8590ff}
.viewer-page .toast{background:#eceef3;color:#0c0e12;box-shadow:0 6px 20px rgba(0,0,0,.45)}
.vwrap{min-height:100vh;display:grid;grid-template-rows:auto 1fr}
.vtop{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:8px;height:56px;padding:0 16px;background:color-mix(in srgb,#0c0e12 88%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid #2a2f39}
.vtop .icon-btn{color:#fff;margin-left:-8px}.vtop .icon-btn:hover{background:rgba(255,255,255,.12)}
.vtop .t{flex:1;min-width:0}.vtop .t b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.vtop .cnt{font-size:13px;color:#98a0b0;white-space:nowrap}
.vbody{display:grid;grid-template-columns:1fr;min-height:0}
@media(min-width:900px){.vbody{grid-template-columns:1fr 340px}}
.stage{position:relative;display:grid;place-items:center;min-height:0;padding:0 12px 12px}
.stage img{max-width:100%;max-height:calc(100vh - 90px);border-radius:12px;display:block;object-fit:contain}
.nav{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.14);color:#fff;display:grid;place-items:center;text-decoration:none}
.nav:hover{background:rgba(255,255,255,.26)}.nav.l{left:22px}.nav.r{right:22px}
.panel{padding:6px 18px 32px;display:flex;flex-direction:column;gap:14px}
.panel dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:14px}
.panel dt{color:#98a0b0}.panel dd{margin:0;min-width:0}.panel dd.fname{overflow-wrap:anywhere}
.panel .btn{background:#1d2129;border-color:#2a2f39;color:#eceef3;width:100%;margin:0}
.panel .btn:hover{background:#262b36}
.panel .btn.primary{background:#8590ff;border-color:#8590ff;color:#0d1030}
.panel .btn.danger{color:#ff7a70}
.panel .banner{background:#1d2129;color:#eceef3;margin:0}.panel .banner.error{color:#ff7a70}
.panel .hint{color:#98a0b0;margin:0}
.panel form{margin:0}
.stack{display:grid;gap:8px}
.pcard{display:grid;gap:12px;padding:14px;border-radius:14px;background:#1d2129;border:1px solid #2a2f39}
.pcard h2,.pcard label{display:block;margin:0 0 -4px;font-weight:600;font-size:14px}
.pcard .btn{background:#262b36}.pcard .btn.primary{background:#8590ff}.pcard .btn:hover{background:#2f3541}.pcard .btn.primary:hover{background:#9aa3ff}
.pcard .keep-form{display:grid;gap:8px;padding-top:12px;border-top:1px solid #2a2f39}
.pcard .link{display:flex;gap:8px}
.pcard .link .btn{width:auto}
.pcard input,.panel .field{height:38px;border-radius:9px;border:1px solid #2a2f39;background:#0f1115;color:#eceef3;padding:0 10px;flex:1;min-width:0}

@media(max-width:560px){.brand .full{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.mark{width:32px;height:32px}h1{font-size:24px}.grid{grid-template-columns:repeat(3,1fr);gap:3px}.tile,.drop{border-radius:6px}.acct .nm{display:none}.stage{padding:0 0 12px}.nav.l{left:10px}.nav.r{right:10px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
CSS;
    echo '</style><body class="' . ($variant === 'viewer' ? 'viewer-page' : '') . '">';
    if ($variant !== 'viewer') {
        echo '<header class="bar"><a class="brand" href="./">' . brandMark() . '<span class="full">phpSimpleImageSharing</span></a><span class="sp"></span>';
        echo '<button class="icon-btn" id="themeToggle" type="button" aria-label="Change theme"><span class="ti ti-system">' . icon('auto') . '</span><span class="ti ti-light">' . icon('sun') . '</span><span class="ti ti-dark">' . icon('moon') . '</span></button>';
        if ($user !== null) {
            echo '<details class="acct"><summary aria-label="Account menu">' . avatar((string)$user['id'], (string)$user['display_name']) . '<span class="nm">' . h((string)$user['display_name']) . '</span>' . icon('chevron') . '</summary><div class="menu">';
            echo '<div class="who"><strong>' . h((string)$user['display_name']) . '</strong>' . h(roleLabel((string)$user['role'])) . '</div>';
            if ($user['role'] === 'admin') echo '<a href="?action=users">' . icon('users') . 'People and system</a>';
            echo '<button type="button" data-open-dialog="guideDialog">' . icon('help') . 'Quick guide</button><hr>';
            echo '<form method="post" action="?action=logout"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button>' . icon('logout') . 'Sign out</button></form></div></details>';
        }
        echo '</header>';
    }
    echo '<main class="' . ($variant === 'viewer' ? 'viewer-main' : 'page') . '">';
}

function pageEnd(): void {
    $signedIn = isset($GLOBALS['currentUser']) && is_array($GLOBALS['currentUser']);
    echo '</main><div class="toast" id="toast" role="status" aria-live="polite"></div>';
    if ($signedIn) {
        echo '<dialog class="modal" id="confirmDialog" aria-labelledby="confirmTitle"><div class="sheet-in"><h2 id="confirmTitle"></h2><p class="sub" id="confirmText"></p><form method="dialog" class="foot"><button class="btn ghost" value="no">Cancel</button><button class="btn danger-solid" value="yes" id="confirmYes">Delete</button></form></div></dialog>';
        echo '<dialog class="modal" id="guideDialog" aria-labelledby="guideTitle"><div class="sheet-in"><h2 id="guideTitle">Quick guide</h2><ol class="guide">';
        echo '<li><strong>Only signed-in people can see the gallery.</strong><span>Everyone you add sees all photos.</span></li>';
        echo '<li><strong>Add photos</strong><span>Use the button at the top or drop files anywhere on the page. Choose how long to keep them.</span></li>';
        echo '<li><strong>Share one photo</strong><span>Open a photo and create a share link. Anyone with the link can see that photo without signing in. A new link replaces the old one.</span></li>';
        echo '<li><strong>Select several photos</strong><span>Use the circle in a photo’s corner to copy links or delete your own photos.</span></li>';
        echo '<li><strong>The bar on each photo shows how long it stays.</strong><span>Deleting here does not remove copies others saved or backups.</span></li>';
        echo '</ol><form method="dialog" class="foot"><button class="btn primary">Got it</button></form></div></dialog>';
    }
    echo '<script>';
    echo <<<'JS'
(()=>{
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s)),root=document.documentElement,themeKey='phpSimpleImageSharing-theme';
  let toastTimer;
  function toast(message,ms=2400){const el=$('#toast');if(!el)return;el.textContent=message;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),ms)}
  $$('[data-toast]').forEach(note=>{toast(note.dataset.toast,Number(note.dataset.toastMs)||2400)});

  const themeButton=$('#themeToggle');let theme='system';
  try{const saved=localStorage.getItem(themeKey);if(saved==='light'||saved==='dark')theme=saved}catch{}
  function applyTheme(){if(theme==='system')root.removeAttribute('data-theme');else root.dataset.theme=theme;root.dataset.mode=theme;if(themeButton){const label='Theme: '+theme;themeButton.title=label;themeButton.setAttribute('aria-label',label+'. Click to change.')}}
  applyTheme();
  if(themeButton)themeButton.addEventListener('click',()=>{theme=theme==='system'?'light':theme==='light'?'dark':'system';try{localStorage.setItem(themeKey,theme)}catch{}applyTheme();toast('Theme: '+theme)});

  const account=$('details.acct');
  if(account){document.addEventListener('click',event=>{if(!account.contains(event.target))account.open=false});document.addEventListener('keydown',event=>{if(event.key==='Escape')account.open=false})}

  $$('[data-open-dialog]').forEach(button=>button.addEventListener('click',()=>{if(account)account.open=false;const dialog=document.getElementById(button.dataset.openDialog);if(dialog&&dialog.showModal)dialog.showModal()}));
  $$('dialog.modal').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog&&!dialog.dataset.busy)dialog.close()}));
  $$('[data-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog').close()));

  const confirmDialog=$('#confirmDialog');let confirmResolve=null;
  function ask(title,text,label){return new Promise(resolve=>{if(!confirmDialog||!confirmDialog.showModal){resolve(window.confirm(title));return}$('#confirmTitle').textContent=title;$('#confirmText').textContent=text;$('#confirmYes').textContent=label;confirmResolve=resolve;confirmDialog.returnValue='';confirmDialog.showModal()})}
  if(confirmDialog)confirmDialog.addEventListener('close',()=>{if(confirmResolve){const resolve=confirmResolve;confirmResolve=null;resolve(confirmDialog.returnValue==='yes')}});
  $$('[data-confirm]').forEach(control=>{
    control.addEventListener('submit',async event=>{
      if(control.dataset.ok){delete control.dataset.ok;return}
      event.preventDefault();
      if(await ask(control.dataset.confirm,control.dataset.confirmText||'This removes the photo and its share link for everyone. It cannot be undone.',control.dataset.confirmLabel||'Delete')){control.dataset.ok='1';control.requestSubmit()}
    });
  });

  async function copyText(text,message,prompt){try{await navigator.clipboard.writeText(text);toast(message)}catch{window.prompt(prompt,text)}}
  $$('[data-quick-share]').forEach(button=>button.addEventListener('click',async event=>{
    event.preventDefault();event.stopPropagation();
    if(button.disabled)return;button.disabled=true;
    try{
      const body=new FormData();body.append('csrf',document.querySelector('input[name="csrf"]').value);body.append('id',button.dataset.quickShare);
      const response=await fetch('?action=quick_share',{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});
      const result=await response.json().catch(()=>({}));
      if(!response.ok||!result.path){toast(result.error||'The share link could not be created.');return}
      const mark=button.closest('.tile').querySelector('.shr');if(mark)mark.hidden=false;
      await copyText(new URL(result.path,location.href).href,result.created?'Share link created and copied':'Share link copied','Copy this link:');
    }catch{toast('The share link could not be created.')}
    finally{button.disabled=false}
  }));
  $$('[data-copy-link]').forEach(button=>button.addEventListener('click',()=>copyText(new URL(button.dataset.copyLink,location.href).href,'Link copied','Copy this link:')));
  $$('.share-url').forEach(input=>{try{input.value=new URL(input.dataset.path,location.href).href}catch{}input.addEventListener('focus',()=>input.select())});

  const bulk=$('#bulkActionsForm');
  if(bulk){
    const boxes=$$('input[name="ids[]"]'),count=$('#selectedCount'),selectPage=$('#selectVisible'),grid=$('#grid');
    const selected=()=>boxes.filter(box=>box.checked);
    function update(){const n=selected().length;count.textContent=n+' selected';bulk.classList.toggle('on',n>0);grid.classList.toggle('selecting',n>0);boxes.forEach(box=>box.closest('.tile').classList.toggle('sel',box.checked));selectPage.textContent=n===boxes.length?'Clear page':'Select page'}
    boxes.forEach(box=>box.addEventListener('change',update));
    selectPage.addEventListener('click',()=>{const all=boxes.every(box=>box.checked);boxes.forEach(box=>box.checked=!all);update()});
    $('#clearSel').addEventListener('click',()=>{boxes.forEach(box=>box.checked=false);update()});
    grid.addEventListener('click',event=>{const link=event.target.closest('.tile-link');if(!link||!bulk.classList.contains('on'))return;event.preventDefault();const box=link.closest('.tile').querySelector('input[name="ids[]"]');box.checked=!box.checked;update()});
    bulk.addEventListener('submit',async event=>{
      if(bulk.dataset.ok){delete bulk.dataset.ok;return}
      const action=event.submitter&&event.submitter.value,chosen=selected();
      if(action!=='copy'&&action!=='delete'&&action!=='share')return;
      event.preventDefault();
      if(chosen.length===0){toast('Select at least one photo');return}
      if(action==='share'){
        const trigger=event.submitter;trigger.disabled=true;
        try{
          const body=new FormData(bulk);body.delete('bulk_action');chosen.forEach(box=>body.append('ids[]',box.value));
          const response=await fetch('?action=quick_share_bulk',{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});
          const result=await response.json().catch(()=>({}));
          if(!response.ok||!Array.isArray(result.links)){toast(result.error||'The share links could not be created.');return}
          const created=result.links.filter(link=>link.created).length,skipped=result.skipped||0;
          for(const link of result.links){const tile=document.querySelector('[data-quick-share="'+link.id+'"]');const mark=tile&&tile.closest('.tile').querySelector('.shr');if(mark)mark.hidden=false}
          const text=result.links.map(link=>new URL(link.path,location.href).href).join('\n');
          const parts=[result.links.length+(result.links.length===1?' share link copied':' share links copied')];
          if(created)parts.push(created+' new');if(skipped)parts.push(skipped+' skipped, not yours');
          await copyText(text,parts.join(', '),'Copy these links:');
        }catch{toast('The share links could not be created.')}
        finally{trigger.disabled=false}
        return;
      }
      if(action==='copy'){const text=chosen.map(box=>new URL('?action=image&id='+encodeURIComponent(box.value),location.href).href).join('\n');copyText(text,chosen.length+(chosen.length===1?' link copied':' links copied'),'Copy these links:');return}
      const n=chosen.length;
      if(await ask('Delete '+n+(n===1?' photo?':' photos?'),'This removes them and their share links for everyone. You can only delete your own photos unless you are an administrator. It cannot be undone.','Delete')){bulk.dataset.ok='1';bulk.requestSubmit(event.submitter)}
    });
    update();
  }

  const form=$('#uploadForm');
  if(form){
    const dialog=$('#uploadDialog'),input=$('#imageFiles'),queue=$('#uploadQueue'),items=$('#queueItems'),summary=$('#queueSummary'),button=$('#uploadButton'),hint=$('#retentionHint');
    let files=[],busy=false,uploadedSome=false;
    const plural=(n,word)=>n+' '+word+(n===1?'':'s');
    function keepDate(){const value=form.querySelector('[name="retention"]:checked').value;if(value==='never')return null;const d=new Date(Date.now()+Number(value)*864e5);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
    function updateHint(){const date=keepDate();hint.textContent=date?'They will be deleted on '+date+'. Backups and copies elsewhere are not removed.':'They stay until someone deletes them.'}
    if(!form.querySelector('[name="retention"]:checked')){const fallback=form.querySelector('[name="retention"][value="never"]');if(fallback)fallback.checked=true}
    const remember=$('#rememberDefault'),rememberRow=$('#rememberRow'),savedDefault=form.querySelector('.keep').dataset.default;
    function syncRemember(){const same=form.querySelector('[name="retention"]:checked').value===savedDefault;rememberRow.hidden=same;if(same)remember.checked=false}
    $$('[name="retention"]',form).forEach(radio=>radio.addEventListener('change',()=>{updateHint();syncRemember()}));updateHint();syncRemember();
    function refresh(){const pending=files.filter(f=>!f.done).length,failed=files.some(f=>f.failed);button.disabled=busy||pending===0;button.textContent=pending===0?'Upload':(failed?'Retry '+plural(pending,'photo'):'Upload '+plural(pending,'photo'))}
    function clear(){for(const img of $$('img',items))if(img.src.startsWith('blob:'))URL.revokeObjectURL(img.src);files=[];items.replaceChildren();queue.hidden=true;summary.textContent='';refresh()}
    function addFiles(list){
      if(busy)return;
      files=files.filter(f=>!f.done);for(const r of Array.from(items.children))if(!files.some(f=>f.row===r))r.remove();
      for(const file of Array.from(list||[])){
        if(files.some(f=>f.file.name===file.name&&f.file.size===file.size&&f.file.lastModified===file.lastModified))continue;
        const row=document.createElement('div');row.className='qi';
        const thumb=document.createElement('img');thumb.alt='';if(file.type.startsWith('image/'))thumb.src=URL.createObjectURL(file);else thumb.hidden=true;
        const body=document.createElement('div');
        const name=document.createElement('div');name.className='qn';name.textContent=file.name;
        const status=document.createElement('div');status.className='qs';status.textContent='Ready';status.setAttribute('aria-live','polite');
        const track=document.createElement('div');track.className='qt';track.setAttribute('role','progressbar');track.setAttribute('aria-label','Upload progress for '+file.name);track.setAttribute('aria-valuemin','0');track.setAttribute('aria-valuemax','100');track.setAttribute('aria-valuenow','0');
        const fill=document.createElement('i');track.append(fill);body.append(name,status,track);
        const drop=document.createElement('button');drop.type='button';drop.className='qx';drop.setAttribute('aria-label','Remove '+file.name);drop.textContent='×';
        const entry={file,row,status,fill,track,done:false,failed:false};
        drop.addEventListener('click',()=>{if(busy)return;if(thumb.src.startsWith('blob:'))URL.revokeObjectURL(thumb.src);row.remove();files=files.filter(f=>f!==entry);syncQueue()});
        row.append(thumb,body,drop);items.append(row);
        files.push(entry);
      }
      syncQueue();
    }
    function syncQueue(){queue.hidden=files.length===0;summary.textContent=files.length?plural(files.filter(f=>!f.done).length,'photo')+' ready':'';refresh()}
    $$('[data-add-photos]').forEach(control=>control.addEventListener('click',()=>input.click()));
    input.addEventListener('change',()=>{if(input.files.length){addFiles(input.files);if(!dialog.open)dialog.showModal()}input.value=''});
    dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault()});
    dialog.addEventListener('close',()=>{if(uploadedSome){location.reload();return}clear()});

    const hasFiles=event=>Array.from((event.dataTransfer&&event.dataTransfer.types)||[]).includes('Files');
    let depth=0;
    document.addEventListener('dragenter',event=>{if(hasFiles(event)){depth++;root.classList.add('dragging')}});
    document.addEventListener('dragleave',event=>{if(hasFiles(event)){depth=Math.max(0,depth-1);if(!depth)root.classList.remove('dragging')}});
    document.addEventListener('dragover',event=>{if(hasFiles(event))event.preventDefault()});
    document.addEventListener('drop',event=>{if(!hasFiles(event))return;event.preventDefault();depth=0;root.classList.remove('dragging');if(busy||$('dialog[open]:not(#uploadDialog)'))return;addFiles(event.dataTransfer.files);if(!dialog.open)dialog.showModal()});

    function upload(entry){return new Promise(resolve=>{
      const body=new FormData();body.append('csrf',form.querySelector('[name="csrf"]').value);body.append('retention',form.querySelector('[name="retention"]:checked').value);if(remember.checked)body.append('remember_default','1');body.append('images[]',entry.file,entry.file.name);
      const xhr=new XMLHttpRequest();xhr.open('POST',form.action);xhr.timeout=120000;xhr.setRequestHeader('Accept','application/json');
      const fail=text=>{entry.status.className='qs fail';entry.status.textContent=text;resolve(false)};
      xhr.upload.addEventListener('progress',event=>{if(event.lengthComputable){const percent=Math.round(event.loaded/event.total*100);entry.fill.style.width=percent+'%';entry.track.setAttribute('aria-valuenow',String(percent))}});
      xhr.addEventListener('load',()=>{let result;try{result=JSON.parse(xhr.responseText).items[0]}catch{}if(xhr.status>=200&&xhr.status<300&&result&&result.success){entry.fill.style.width='100%';entry.track.setAttribute('aria-valuenow','100');entry.status.className='qs ok';entry.status.textContent='Uploaded';resolve(true)}else fail((result&&result.error)||'Upload failed. Try again.')});
      xhr.addEventListener('error',()=>fail('Connection lost. Try again.'));
      xhr.addEventListener('timeout',()=>fail('Upload timed out. Try again.'));
      xhr.send(body);
    })}
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      const pending=files.filter(f=>!f.done);if(!pending.length)return;
      busy=true;dialog.dataset.busy='1';refresh();let failed=0;
      for(const entry of pending){entry.failed=false;entry.fill.style.width='0%';entry.status.className='qs';entry.status.textContent='Uploading…';if(await upload(entry)){entry.done=true;uploadedSome=true}else{entry.failed=true;failed++}}
      busy=false;delete dialog.dataset.busy;refresh();
      if(failed===0){summary.textContent='Done. Refreshing…';setTimeout(()=>location.reload(),600)}
      else summary.textContent=plural(files.filter(f=>f.done).length,'photo')+' uploaded, '+failed+' failed.';
    });
  }

  document.addEventListener('keydown',event=>{
    if($('dialog[open]')||event.target.closest('input,select,textarea'))return;
    const link=event.key==='ArrowLeft'?$('a[rel="prev"]'):event.key==='ArrowRight'?$('a[rel="next"]'):null;
    if(link)link.click();
  });
})();
JS;
    echo '</script></body></html>';
}


/** Normalize PHP's flat or multi-file upload array into one record per file. */
function normalizeUploads(mixed $upload): array
{
    if (!is_array($upload) || !array_key_exists('name', $upload)) return [];
    if (!is_array($upload['name'])) return [$upload];
    $files = [];
    foreach (array_keys($upload['name']) as $index) {
        $files[] = [
            'name' => $upload['name'][$index] ?? '',
            'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0,
        ];
    }
    return $files;
}

/** Return a compact 128-bit Crockford Base32 share token. */
function generateShareToken(): string
{
    $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
    $bytes = random_bytes(16);
    $buffer = 0;
    $bits = 0;
    $encoded = '';
    for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
        $buffer = ($buffer << 8) | ord($bytes[$index]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
            $buffer &= (1 << $bits) - 1;
        }
    }
    if ($bits > 0) $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $encoded;
}

/**
 * Return the active share link of a photo, creating one if none exists.
 * Returns null when the photo is missing, expired or not manageable by the user.
 *
 * @return array{path: string, created: bool}|null
 */
function ensureShareLink(PDO $pdo, array $user, string $mediaId, int $now): ?array
{
    if (!preg_match('/\A[a-f0-9]{32,64}\z/', $mediaId)) return null;
    $find = $pdo->prepare('SELECT owner_id, expires_at FROM media WHERE id = :id LIMIT 1');
    $find->execute([':id' => $mediaId]);
    $media = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media) || ($media['expires_at'] !== null && (int)$media['expires_at'] <= $now)
        || ($user['role'] !== 'admin' && $media['owner_id'] !== $user['id'])) {
        return null;
    }
    $existing = $pdo->prepare("SELECT token FROM share_links WHERE media_id = :id AND revoked_at IS NULL AND token <> '' AND (expires_at IS NULL OR expires_at > :now) ORDER BY created_at DESC LIMIT 1");
    $existing->execute([':id' => $mediaId, ':now' => $now]);
    $token = $existing->fetchColumn();
    if (is_string($token) && $token !== '') return ['path' => sharePath($token), 'created' => false];

    $token = generateShareToken();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE share_links SET revoked_at = :now WHERE media_id = :id AND revoked_at IS NULL')->execute([':now' => $now, ':id' => $mediaId]);
        $pdo->prepare('INSERT INTO share_links (id, media_id, token_hash, token, created_at, expires_at, revoked_at) VALUES (:id, :media_id, :token_hash, :token, :created_at, :expires_at, NULL)')->execute([
            ':id' => bin2hex(random_bytes(16)), ':media_id' => $mediaId, ':token_hash' => hash('sha256', $token),
            ':token' => $token, ':created_at' => $now, ':expires_at' => $media['expires_at'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    return ['path' => sharePath($token), 'created' => true];
}

function sharePath(string $token): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '/' || $basePath === '.') $basePath = '';
    return rtrim($basePath, '/') . '/' . rawurlencode($token);
}

function redirectToRickroll(): never
{
    header('Cache-Control: no-store, private');
    header('Location: https://www.youtube.com/watch?v=dQw4w9WgXcQ', true, 302);
    exit;
}

function servePublicShare(PDO $pdo, MediaStorage $storage, string $token): never
{
    $token = strtolower($token);
    if (!preg_match('/\A[0-9abcdefghjkmnpqrstvwxyz]{26}\z/', $token)) redirectToRickroll();
    $statement = $pdo->prepare(
        'SELECT media.owner_id, media.storage_name, media.mime
         FROM share_links
         JOIN media ON media.id = share_links.media_id
         WHERE share_links.token_hash = :token_hash
           AND share_links.revoked_at IS NULL
           AND (share_links.expires_at IS NULL OR share_links.expires_at > :link_now)
           AND (media.expires_at IS NULL OR media.expires_at > :media_now)
         LIMIT 1'
    );
    $now = time();
    $statement->execute([':token_hash' => hash('sha256', $token), ':link_now' => $now, ':media_now' => $now]);
    $media = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media)) redirectToRickroll();
    if (!in_array($media['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) { http_response_code(404); exit; }
    $file = $storage->locate((string)$media['owner_id'], (string)$media['storage_name']);
    if ($file === null) { http_response_code(404); exit; }
    header('Content-Type: ' . $media['mime']);
    header('Content-Length: ' . filesize($file));
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    readfile($file);
    exit;
}
