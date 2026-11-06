<?php
declare(strict_types=1);

// Private media sharing for PHP 8.4 shared hosting.
// Copy config.example.php to config.php and configure the private data directory.
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
    importLegacyMedia($pdo, $mediaStorage, $dataDir);
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
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (@inet_pton(trim($remoteAddress)) === false) $remoteAddress = '127.0.0.1';
$loginThrottleKey = LoginRateLimiter::key($remoteAddress);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function token(): string { return bin2hex(random_bytes(32)); }
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
if (array_key_exists('invalid_share', $_GET)) redirectToRickroll();
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
    pageStart('Sign in');
    if ($error) echo '<p class="error">' . h($error) . '</p>';
    echo '<section class="login-panel"><p class="eyebrow">Your private album</p><h1>Welcome back</h1><p class="subheading">Sign in to view the photos you share with friends.</p><form class="login-form" method="post" action="?action=login"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><label for="username">Username</label><input id="username" name="username" required autocomplete="username" autofocus><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"><button class="primary">Sign in</button></form></section>';
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
    $statement = $pdo->prepare('SELECT owner_id, storage_name, mime, expires_at FROM media WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $media = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media)) { http_response_code(404); exit; }
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
    $statement = $pdo->prepare('SELECT owner_id, storage_name, original_name, mime, expires_at FROM media WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $media = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media) || ($media['expires_at'] !== null && (int)$media['expires_at'] <= time())) { http_response_code(404); exit; }
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
        if ($action === 'upload') {
            $retentionDays = (string)($_POST['retention'] ?? 'never');
            if (!in_array($retentionDays, ['never', '1', '7', '30'], true)) {
                throw new InvalidArgumentException('Choose a valid image retention period.');
            }
            $expiresAt = $retentionDays === 'never' ? null : time() + ((int)$retentionDays * 86400);
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
            if ($successCount > 0) $notice = $successCount . ' ' . ($successCount === 1 ? 'image uploaded.' : 'images uploaded.');
            if ($failureCount > 0) $error = $failureCount . ' ' . ($failureCount === 1 ? 'file could not be uploaded.' : 'files could not be uploaded. Check their format and size.');
        } elseif ($action === 'create_share') {
            $mediaId = (string)($_POST['id'] ?? '');
            if (!preg_match('/\A[a-f0-9]{32,64}\z/', $mediaId)) throw new InvalidArgumentException('Invalid media ID.');
            $findMedia = $pdo->prepare('SELECT id, owner_id, expires_at FROM media WHERE id = :id LIMIT 1');
            $findMedia->execute([':id' => $mediaId]);
            $sharedMedia = $findMedia->fetch(PDO::FETCH_ASSOC);
            if (!is_array($sharedMedia) || ($currentUser['role'] !== 'admin' && $sharedMedia['owner_id'] !== $currentUser['id'])) {
                throw new RuntimeException('You cannot create a share link for this image.');
            }
            $tokenValue = generateShareToken();
            $now = time();
            $pdo->beginTransaction();
            try {
                $revoke = $pdo->prepare('UPDATE share_links SET revoked_at = :revoked_at WHERE media_id = :media_id AND revoked_at IS NULL');
                $revoke->execute([':revoked_at' => $now, ':media_id' => $mediaId]);
                $insert = $pdo->prepare(
                    'INSERT INTO share_links (id, media_id, token_hash, created_at, expires_at, revoked_at)
                     VALUES (:id, :media_id, :token_hash, :created_at, :expires_at, NULL)'
                );
                $insert->execute([
                    ':id' => bin2hex(random_bytes(16)), ':media_id' => $mediaId,
                    ':token_hash' => hash('sha256', $tokenValue), ':created_at' => $now,
                    ':expires_at' => $sharedMedia['expires_at'],
                ]);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            $sharePath = sharePath($tokenValue);
            $notice = 'New share link generated. Any previous link for this image is now invalid. <a href="' . h($sharePath) . '">Open share</a> <code class="share-url">' . h($sharePath) . '</code><button type="button" class="inline-action" data-copy-link="' . h($sharePath) . '">Copy share link</button>';
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $statement = $pdo->prepare('SELECT owner_id, storage_name FROM media WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $id]);
            $media = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($media) && ($currentUser['role'] === 'admin' || $media['owner_id'] === $currentUser['id'])) {
                $mediaStorage->delete((string)$media['owner_id'], (string)$media['storage_name']);
                $delete = $pdo->prepare('DELETE FROM media WHERE id = :id');
                $delete->execute([':id' => $id]);
                $_SESSION['flash_notice'] = 'Image deleted.';
            } else {
                $_SESSION['flash_error'] = 'This image could not be deleted.';
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
        if (in_array($action, ['create_user', 'reset_user_password', 'set_user_active'], true)) $action = 'users';
    } catch (Throwable $exception) {
        error_log('phpSimpleImageSharing request error: ' . $exception->getMessage());
        $error = 'The request could not be completed. Check the server log for details.';
        if (in_array($action, ['create_user', 'reset_user_password', 'set_user_active'], true)) $action = 'users';
    }
}

$images = [];
try {
    $query = $pdo->prepare(
        'SELECT media.id, media.owner_id, media.storage_name, media.original_name, media.mime, media.size_bytes, media.created_at, media.expires_at,
                users.display_name, users.username
         FROM media JOIN users ON users.id = media.owner_id
         WHERE media.expires_at IS NULL OR media.expires_at > :now
         ORDER BY media.created_at DESC, media.id DESC'
    );
    $query->execute([':now' => time()]);
    $images = $query->fetchAll(PDO::FETCH_ASSOC);
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
$images = array_slice($images, ($galleryPage - 1) * $imagesPerPage, $imagesPerPage);
if ($action === 'users' && $currentUser['role'] === 'admin') {
    $users = Auth::listUsers($pdo, $currentUser);
    pageStart('Manage users');
    if ($error) echo '<p class="error">' . h($error) . '</p>';
    if ($notice) echo '<p class="notice">' . h($notice) . '</p>';
    echo '<section class="welcome"><div><p class="eyebrow">Administration</p><h1>Manage users</h1><p class="subheading">Create and manage access for your friends.</p></div><a class="quiet-button" href="./">Back to gallery</a></section>';
    echo '<section class="admin-panel"><h2>Create an account</h2><p>Passwords must contain at least 12 characters.</p><form class="user-create" method="post" action="?action=create_user"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><label>Username<input name="username" required minlength="3" maxlength="32" autocomplete="off"></label><label>Display name<input name="display_name" required maxlength="80"></label><label>Temporary password<input name="password" type="password" required minlength="12" autocomplete="new-password"></label><label>Role<select name="role"><option value="member">Member</option><option value="admin">Administrator</option></select></label><button class="primary">Create account</button></form></section>';
    echo '<div class="section-heading"><div><h2>Accounts</h2><p>' . count($users) . ' users</p></div></div><div class="user-list">';
    foreach ($users as $account) {
        echo '<article class="user-card"><div><strong>' . h($account['display_name']) . '</strong><small>@' . h($account['username']) . ' · ' . h(ucfirst($account['role'])) . ' · ' . ((int)$account['active'] === 1 ? 'Active' : 'Disabled') . '</small></div><form class="user-actions" method="post" action="?action=set_user_active"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="user_id" value="' . h($account['id']) . '"><input type="hidden" name="active" value="' . ((int)$account['active'] === 1 ? '0' : '1') . '"><button class="text-action">' . ((int)$account['active'] === 1 ? 'Disable' : 'Enable') . '</button></form><form class="user-actions" method="post" action="?action=reset_user_password"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="user_id" value="' . h($account['id']) . '"><label class="password-reset">New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label><button class="text-action">Reset password</button></form></article>';
    }
    echo '</div><div class="toast" id="toast" role="status" aria-live="polite"></div>';
    pageEnd(); exit;
}
if ($action === 'detail') {
    $rawDetailId = $_GET['id'] ?? '';
    $detailId = is_string($rawDetailId) ? $rawDetailId : '';
    $detailIndex = null;
    foreach ($allGalleryImages as $index => $image) {
        if (hash_equals((string)$image['id'], $detailId)) { $detailIndex = $index; break; }
    }
    if ($detailIndex === null) { http_response_code(404); pageStart('Image not found'); echo '<p class="error">This image is unavailable.</p><a class="quiet-button" href="./">Back to gallery</a>'; pageEnd(); exit; }
    $detail = $allGalleryImages[$detailIndex];
    $previousUrl = $detailIndex > 0 ? '?' . http_build_query(['action' => 'detail', 'id' => $allGalleryImages[$detailIndex - 1]['id'], 'sort' => $sortOrder, 'q' => $searchTerm, 'page' => $galleryPage]) : null;
    $nextUrl = $detailIndex + 1 < count($allGalleryImages) ? '?' . http_build_query(['action' => 'detail', 'id' => $allGalleryImages[$detailIndex + 1]['id'], 'sort' => $sortOrder, 'q' => $searchTerm, 'page' => $galleryPage]) : null;
    $detailImageUrl = '?action=image&id=' . rawurlencode((string)$detail['id']);
    pageStart((string)$detail['original_name']);
    echo '<section class="detail-heading"><a class="quiet-button" href="?' . h(http_build_query(['q' => $searchTerm, 'sort' => $sortOrder, 'page' => $galleryPage])) . '">← Back to gallery</a><nav class="detail-nav" aria-label="Image navigation">' . ($previousUrl === null ? '<span class="quiet-button disabled">← Previous</span>' : '<a class="quiet-button" href="' . h($previousUrl) . '" rel="prev">← Previous</a>') . '<span>' . ($detailIndex + 1) . ' of ' . count($allGalleryImages) . '</span>' . ($nextUrl === null ? '<span class="quiet-button disabled">Next →</span>' : '<a class="quiet-button" href="' . h($nextUrl) . '" rel="next">Next →</a>') . '</nav></section>';
    echo '<article class="detail-card"><img class="detail-image" src="' . h($detailImageUrl) . '" alt="' . h((string)$detail['original_name']) . '"><div class="detail-info"><div><h1>' . h((string)$detail['original_name']) . '</h1><p>' . h(date('Y-m-d', (int)$detail['created_at'])) . ' · Uploaded by ' . h((string)$detail['display_name']) . '</p></div><div class="detail-actions"><a class="quiet-button" href="?action=download&id=' . rawurlencode((string)$detail['id']) . '">Download original</a><button type="button" class="quiet-button" data-copy-link="' . h($detailImageUrl) . '">Copy private link</button></div></div></article><div class="toast" id="toast" role="status" aria-live="polite"></div>';
    pageEnd(); exit;
}
pageStart('Gallery');
if ($error) echo '<p class="error">' . h($error) . '</p>';
if ($notice) echo '<p class="notice">' . $notice . '</p>';
echo '<section class="welcome"><div><p class="eyebrow">Your private album · Signed in as ' . h($currentUser['username']) . '</p><h1>Our moments</h1><p class="subheading">A small collection of moments shared with friends.</p></div><div class="welcome-actions">' . ($currentUser['role'] === 'admin' ? '<a class="quiet-button" href="?action=users">Manage users</a>' : '') . '<span class="private-badge"><span></span>Private</span></div></section>';
echo '<form class="upload" id="uploadForm" method="post" action="?action=upload" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="upload-copy"><span class="upload-icon" aria-hidden="true">↑</span><div><strong>Add images</strong><small>Drop files here or choose them · JPEG, PNG, GIF, WebP · up to ' . h((string)round($maxBytes / 1024 / 1024)) . ' MB each</small></div></div><label class="retention-control" for="retention"><span>Keep for</span><select id="retention" name="retention"><option value="never">Never delete</option><option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option></select></label><label class="file-control"><span>Choose images</span><input id="imageFiles" type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple></label><button class="primary" id="uploadButton">Upload</button><div class="upload-queue" id="uploadQueue" hidden><div class="queue-summary" id="queueSummary" aria-live="polite"></div><div class="queue-items" id="queueItems"></div></div></form>';
echo '<div class="section-heading"><div><h2>All images</h2><p>' . $totalImageCount . ' ' . ($totalImageCount === 1 ? 'image' : 'images') . '</p></div><form class="gallery-filters" method="get" action="./"><label for="gallerySearch">Search images</label><input id="gallerySearch" type="search" name="q" maxlength="120" value="' . h($searchTerm) . '" placeholder="Filename"><label for="gallerySort">Sort</label><select id="gallerySort" name="sort"><option value="newest"' . ($sortOrder === 'newest' ? ' selected' : '') . '>Newest first</option><option value="oldest"' . ($sortOrder === 'oldest' ? ' selected' : '') . '>Oldest first</option></select><button class="quiet-button">Apply</button></form></div>';
if (!$images) echo '<div class="empty"><span class="empty-icon">▧</span><h2>' . ($searchTerm === '' ? 'Your album is ready for its first image' : 'No images match your search') . '</h2><p>' . ($searchTerm === '' ? 'Add an image above to get started.' : 'Try another filename or clear the search.') . '</p></div>';
echo '<div class="grid">';
foreach ($images as $image) {
    $id = (string)$image['id'];
    $imageUrl = '?action=image&id=' . rawurlencode($id);
    $detailUrl = '?' . http_build_query(['action' => 'detail', 'id' => $id, 'sort' => $sortOrder, 'q' => $searchTerm, 'page' => $galleryPage]);
    $name = (string)$image['original_name'];
    $created = (int)$image['created_at'];
    $canManage = $currentUser['role'] === 'admin' || $image['owner_id'] === $currentUser['id'];
    $expiryLabel = $image['expires_at'] === null ? '' : '<small class="expiry-label">Deletes ' . h(date('Y-m-d', (int)$image['expires_at'])) . '</small>';
    echo '<article class="photo-card"><a class="photo" href="' . h($detailUrl) . '"><img loading="lazy" src="' . h($imageUrl) . '" alt="' . h($name) . '"></a><div class="photo-info"><strong title="' . h($name) . '">' . h($name) . '</strong><time datetime="' . h(date('Y-m-d', $created)) . '">' . h(date('Y-m-d', $created)) . '</time>' . $expiryLabel . '<small class="owner-label">Uploaded by ' . h($image['display_name']) . '</small><div class="photo-actions">' . ($canManage ? '<form method="post" action="?action=create_share"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . h($id) . '"><button class="text-action">Generate share link</button></form>' : '<span></span>') . ($canManage ? '<form method="post" action="?action=delete" data-confirm="' . h('Delete ' . $name . '?') . '"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . h($id) . '"><button class="text-action danger">Delete</button></form>' : '') . '</div></div></article>';
}
echo '</div>';
if ($pageCount > 1) {
    echo '<nav class="pagination" aria-label="Gallery pages">' . ($galleryPage > 1 ? '<a class="quiet-button" href="?' . h(http_build_query(['q' => $searchTerm, 'sort' => $sortOrder, 'page' => $galleryPage - 1])) . '">← Previous</a>' : '') . '<span>Page ' . $galleryPage . ' of ' . $pageCount . '</span>' . ($galleryPage < $pageCount ? '<a class="quiet-button" href="?' . h(http_build_query(['q' => $searchTerm, 'sort' => $sortOrder, 'page' => $galleryPage + 1])) . '">Next →</a>' : '') . '</nav>';
}
echo '<form class="logout" method="post" action="?action=logout"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="text-action">Sign out</button></form><div class="toast" id="toast" role="status" aria-live="polite"></div>';
pageEnd();

function pageStart(string $title): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>' . h($title) . ' · phpSimpleImageSharing</title><style>
    :root{font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color-scheme:light dark;--page:#f4f1eb;--surface:#fffefa;--raised:#fff;--ink:#33312d;--muted:#827c73;--line:#e9e3d9;--quiet:#f7f4ee;--accent:#ae7056;--accent-hover:#90583f;--accent-ink:#fff;--tint:#f7eee8;--danger:#a9473c;--focus:#b76f4d;--shadow:0 12px 34px rgba(57,43,31,.07);--radius:12px}
    html[data-theme="dark"]{color-scheme:dark;--page:#1b1a18;--surface:#242321;--raised:#2b2926;--ink:#f1ede6;--muted:#b3aa9e;--line:#403d38;--quiet:#302e2b;--accent:#cf9275;--accent-hover:#e4a487;--accent-ink:#251913;--tint:#382c26;--danger:#ed9588;--focus:#e3a184;--shadow:0 14px 36px rgba(0,0,0,.2)}
    @media(prefers-color-scheme:dark){html:not([data-theme="light"]){color-scheme:dark;--page:#1b1a18;--surface:#242321;--raised:#2b2926;--ink:#f1ede6;--muted:#b3aa9e;--line:#403d38;--quiet:#302e2b;--accent:#cf9275;--accent-hover:#e4a487;--accent-ink:#251913;--tint:#382c26;--danger:#ed9588;--focus:#e3a184;--shadow:0 14px 36px rgba(0,0,0,.2)}}
    *{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);transition:background .2s,color .2s}.app-shell{max-width:1120px;margin:0 auto;padding:0 28px 48px}.topbar{height:76px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);margin-bottom:42px}.brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--ink);font-weight:760;letter-spacing:-.055em;font-size:16px}.brand-mark{width:28px;height:28px;border-radius:9px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-size:14px;flex:none}.top-actions{display:flex;align-items:center;gap:10px}.theme-toggle,.quiet-button{font:inherit;border:1px solid var(--line);border-radius:9px;background:var(--surface);color:var(--ink);padding:8px 11px;cursor:pointer;font-size:13px}.theme-toggle:hover,.quiet-button:hover{background:var(--quiet)}
    button:focus-visible,a:focus-visible,input:focus-visible{outline:3px solid color-mix(in srgb,var(--focus) 65%,transparent);outline-offset:2px}.welcome{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:25px}.eyebrow{font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);font-weight:750;margin:0 0 7px}.welcome h1{font:500 clamp(31px,4vw,43px)/1.1 Georgia,serif;letter-spacing:-.04em;margin:0}.subheading{color:var(--muted);margin:8px 0 0;font-size:14px}.private-badge{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);border-radius:99px;background:var(--surface);padding:7px 11px;color:var(--muted);font-size:12px;white-space:nowrap}.private-badge span{width:7px;height:7px;border-radius:50%;background:#6d9878}
    .upload{display:flex;align-items:center;gap:12px;border:1px solid var(--line);border-radius:var(--radius);padding:15px;background:var(--surface);margin:0 0 34px;box-shadow:var(--shadow)}.upload-copy{display:flex;align-items:center;gap:11px;margin-right:auto}.upload-icon{width:36px;height:36px;border-radius:10px;background:var(--tint);color:var(--accent);display:grid;place-items:center;font-size:21px}.upload-copy strong{display:block;font-size:13px}.upload-copy small{display:block;color:var(--muted);font-size:11px;margin-top:2px}.file-control{position:relative;overflow:hidden}.file-control span{display:block;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);font-size:12px;padding:9px 11px;cursor:pointer}.file-control input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}.primary{font:inherit;border:0;border-radius:8px;background:var(--accent);color:var(--accent-ink);font-size:12px;font-weight:700;padding:10px 14px;cursor:pointer}.primary:hover{background:var(--accent-hover)}
    .section-heading{display:flex;align-items:end;justify-content:space-between;margin:0 0 13px}.section-heading h2{font-size:16px;margin:0;letter-spacing:-.02em}.section-heading p{font-size:11px;color:var(--muted);margin:2px 0 0}.sort-label{font-size:11px;color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:17px}.photo-card{min-width:0;background:var(--surface);border:1px solid var(--line);border-radius:10px;overflow:hidden;transition:transform .16s,box-shadow .16s}.photo-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}.photo{display:block;aspect-ratio:4/3;background:var(--quiet);overflow:hidden}.photo img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s}.photo-card:hover img{transform:scale(1.025)}.photo-info{padding:11px 12px 10px}.photo-info>strong{display:block;font-size:12px;font-weight:680;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.photo-info time{display:block;color:var(--muted);font-size:10px;margin-top:2px}.photo-actions{display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--line);margin-top:9px;padding-top:7px}.photo-actions form{margin:0}
    .text-action,.inline-action{border:0;background:none;color:var(--accent);font:inherit;font-size:11px;padding:2px 0;cursor:pointer;text-decoration:none}.text-action:hover,.inline-action:hover{text-decoration:underline}.danger{color:var(--danger)}.error,.notice{padding:12px 14px;border:1px solid var(--line);border-radius:9px;font-size:13px;margin:0 0 15px}.error{color:var(--danger);background:color-mix(in srgb,var(--danger) 7%,var(--surface))}.notice{background:var(--tint);color:var(--ink)}.notice a{color:var(--accent);font-weight:650}.notice .inline-action{margin-left:10px}.empty{padding:48px 20px;text-align:center;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface)}.empty-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:var(--tint);color:var(--accent);font-size:21px;margin:0 auto 12px}.empty h2{font:500 20px Georgia,serif;margin:0 0 6px}.empty p{color:var(--muted);font-size:13px;margin:0}.logout{display:flex;justify-content:flex-end;margin-top:34px}.toast{position:fixed;bottom:22px;left:50%;transform:translate(-50%,20px);opacity:0;pointer-events:none;background:var(--ink);color:var(--surface);padding:10px 14px;border-radius:9px;font-size:12px;transition:opacity .18s,transform .18s}.toast.show{opacity:1;transform:translate(-50%,0)}a{color:var(--accent)}
    @media(max-width:600px){.app-shell{padding:0 15px 32px}.topbar{height:64px;margin-bottom:29px}.welcome{align-items:flex-start}.welcome h1{font-size:33px}.upload{align-items:stretch;flex-wrap:wrap;padding:12px;gap:9px}.upload-copy{width:100%;margin:0 0 4px}.file-control{flex:1}.file-control span,.primary{height:38px;display:grid;place-items:center}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.photo-info{padding:9px}.photo-actions{gap:8px}.theme-toggle{padding:7px 9px}}@media(max-width:380px){.grid{grid-template-columns:1fr}.photo{aspect-ratio:16/10}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{transition:none!important;scroll-behavior:auto!important}}
    </style><body><div class="app-shell"><header class="topbar"><a class="brand" href="./"><span class="brand-mark">P</span>phpSimpleImageSharing</a><div class="top-actions"><button class="theme-toggle" id="themeToggle" type="button">Theme: System</button></div></header><main>';
    echo '<style>.login-panel{max-width:460px;margin:0 auto;padding:28px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow)}.login-panel h1{font:500 32px/1.2 Georgia,serif;letter-spacing:-.04em;margin:0}.login-panel .subheading{margin-bottom:24px}.login-form{display:grid;gap:9px}.login-form label,.user-create label,.password-reset{font-size:12px;font-weight:650}.login-form input,.user-create input,.user-create select,.password-reset input{font:inherit;color:var(--ink);background:var(--raised);border:1px solid var(--line);border-radius:8px;padding:10px 12px}.login-form .primary{margin-top:5px}.welcome-actions{display:flex;align-items:center;gap:9px}.admin-panel{padding:18px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);margin-bottom:28px}.admin-panel h2{font-size:15px;margin:0}.admin-panel>p{color:var(--muted);font-size:12px;margin:3px 0 16px}.user-create{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;align-items:end}.user-create label,.password-reset{display:grid;gap:5px}.user-create .primary{height:39px}.user-list{display:grid;gap:9px}.user-card{display:flex;align-items:center;gap:15px;padding:13px;border:1px solid var(--line);border-radius:9px;background:var(--surface)}.user-card>div:first-child{flex:1;min-width:140px}.user-card strong,.user-card small{display:block}.user-card strong{font-size:13px}.user-card small{color:var(--muted);font-size:11px}.user-actions{display:flex;align-items:end;gap:8px;margin:0}.password-reset input{width:145px;padding:7px}.photo-info .owner-label{display:block;color:var(--muted);font-size:10px;margin-top:3px}.share-url{font-size:12px;padding:2px 6px;background:var(--quiet);border-radius:5px;color:var(--ink)}@media(max-width:750px){.user-create{grid-template-columns:repeat(2,minmax(0,1fr))}.user-card{flex-wrap:wrap}.user-card>div:first-child{flex-basis:100%}.password-reset input{width:130px}}@media(max-width:600px){.login-panel{padding:20px}.brand{font-size:13px;letter-spacing:-.07em}.brand-mark{width:25px;height:25px}.welcome-actions{align-items:flex-end;flex-direction:column}.user-create{grid-template-columns:1fr}.user-card{align-items:flex-start}.user-actions{flex-wrap:wrap}}</style>';
    echo '<style>.upload{flex-wrap:wrap}.retention-control{min-width:120px}.expiry-label{display:block;color:var(--muted);font-size:10px;margin-top:2px}.upload.is-dragover{border-color:var(--accent);background:var(--tint)}.upload-queue{flex-basis:100%;border-top:1px solid var(--line);padding-top:12px}.queue-summary{font-size:12px;color:var(--muted);margin-bottom:7px}.queue-items{display:grid;gap:6px}.queue-item{display:grid;grid-template-columns:34px minmax(0,1fr) 160px auto;align-items:center;gap:9px;padding:7px;background:var(--quiet);border-radius:7px}.queue-item img{width:34px;height:34px;object-fit:cover;border-radius:5px}.queue-name{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.queue-status{font-size:10px;color:var(--muted)}.queue-status.success{color:#56815f}.queue-status.failed{color:var(--danger)}.queue-track{height:4px;background:var(--line);border-radius:10px;overflow:hidden}.queue-track span{display:block;height:100%;width:0;background:var(--accent);transition:width .1s}.queue-refresh{margin-top:8px}.primary:disabled{opacity:.6;cursor:wait}@media(max-width:600px){.retention-control{flex:1;min-width:130px}.queue-item{grid-template-columns:30px minmax(0,1fr) 90px}.queue-item .queue-track{grid-column:2/4;width:100%}.queue-item img{width:30px;height:30px}}</style>';
    echo '<style>.gallery-filters{display:flex;align-items:center;gap:7px}.gallery-filters label{font-size:11px;color:var(--muted)}.gallery-filters input,.gallery-filters select{font:inherit;font-size:12px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:7px 9px}.gallery-filters input{width:170px}.detail-heading,.detail-nav,.detail-actions{display:flex;align-items:center;gap:9px}.detail-heading{justify-content:space-between;margin-bottom:15px}.detail-nav{font-size:12px;color:var(--muted)}.quiet-button.disabled{opacity:.45}.detail-card{overflow:hidden;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface)}.detail-image{display:block;width:100%;max-height:75vh;object-fit:contain;background:var(--quiet)}.detail-info{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:16px}.detail-info h1{font:500 21px/1.2 Georgia,serif;overflow-wrap:anywhere;margin:0}.detail-info p{font-size:12px;color:var(--muted);margin:5px 0 0}.detail-actions{flex-wrap:wrap}.pagination{display:flex;justify-content:center;align-items:center;gap:14px;margin-top:22px;font-size:12px;color:var(--muted)}@media(max-width:650px){.section-heading{align-items:flex-start;flex-direction:column;gap:10px}.gallery-filters{width:100%;flex-wrap:wrap}.gallery-filters input{flex:1;min-width:90px}.gallery-filters label:first-child{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.detail-heading{align-items:flex-start;flex-direction:column}.detail-nav{width:100%;justify-content:space-between}.detail-info{align-items:flex-start;flex-direction:column}.detail-actions{width:100%}}</style>';
}
function pageEnd(): void {
    echo '</main></div><script>';
    echo <<<'JS'
(()=>{
  const root=document.documentElement,themeButton=document.getElementById('themeToggle'),themeKey='phpSimpleImageSharing-theme';
  let theme=localStorage.getItem(themeKey)||'system';
  function applyTheme(){if(theme==='system')root.removeAttribute('data-theme');else root.dataset.theme=theme;themeButton.textContent='Theme: '+(theme==='system'?'System':theme==='light'?'Light':'Dark');themeButton.setAttribute('aria-label','Change theme. Current: '+theme)}
  applyTheme();themeButton.addEventListener('click',()=>{theme=theme==='system'?'light':theme==='light'?'dark':'system';localStorage.setItem(themeKey,theme);applyTheme()});
  let toastTimer;const toast=document.getElementById('toast');
  document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm)){event.preventDefault()}}));
  document.querySelectorAll('[data-copy-link]').forEach(control=>control.addEventListener('click',async()=>{const url=new URL(control.dataset.copyLink,location.href).href;try{await navigator.clipboard.writeText(url);if(toast){toast.textContent='Link copied';toast.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.remove('show'),2200)}}catch{window.prompt('Copy this link:',url)}}));

  const form=document.getElementById('uploadForm');
  if(form){
    const input=document.getElementById('imageFiles'),queue=document.getElementById('uploadQueue'),items=document.getElementById('queueItems'),summary=document.getElementById('queueSummary'),button=document.getElementById('uploadButton');
    let files=[];
    function selectFiles(next){for(const image of items.querySelectorAll('img'))if(image.src.startsWith('blob:'))URL.revokeObjectURL(image.src);files=[];items.replaceChildren();for(const file of Array.from(next||[])){const row=document.createElement('div');row.className='queue-item';const preview=document.createElement('img');preview.alt='';if(file.type.startsWith('image/'))preview.src=URL.createObjectURL(file);else preview.hidden=true;const name=document.createElement('span');name.className='queue-name';name.textContent=file.name;const track=document.createElement('span');track.className='queue-track';track.setAttribute('role','progressbar');track.setAttribute('aria-label','Upload progress for '+file.name);track.setAttribute('aria-valuemin','0');track.setAttribute('aria-valuemax','100');track.setAttribute('aria-valuenow','0');const fill=document.createElement('span');track.append(fill);const status=document.createElement('span');status.className='queue-status';status.setAttribute('aria-live','polite');status.textContent='Ready';row.append(preview,name,track,status);items.append(row);files.push({file,status,fill,track})}queue.hidden=files.length===0;summary.textContent=files.length?`${files.length} ${files.length===1?'file':'files'} ready`:''}
    input.addEventListener('change',()=>{selectFiles(input.files);input.value=''});
    form.addEventListener('dragover',event=>{event.preventDefault();form.classList.add('is-dragover')});
    form.addEventListener('dragleave',event=>{if(!form.contains(event.relatedTarget))form.classList.remove('is-dragover')});
    form.addEventListener('drop',event=>{event.preventDefault();form.classList.remove('is-dragover');selectFiles(event.dataTransfer.files)});
    function uploadFile(entry){return new Promise(resolve=>{const file=entry.file,body=new FormData();body.append('csrf',form.querySelector('[name="csrf"]').value);body.append('retention',form.querySelector('[name="retention"]').value);body.append('images[]',file,file.name);const xhr=new XMLHttpRequest();xhr.open('POST',form.action);xhr.timeout=120000;xhr.setRequestHeader('Accept','application/json');xhr.upload.addEventListener('progress',event=>{if(event.lengthComputable){const percent=Math.round(event.loaded/event.total*100);entry.fill.style.width=percent+'%';entry.track.setAttribute('aria-valuenow',String(percent))}});xhr.addEventListener('load',()=>{let result;try{result=JSON.parse(xhr.responseText).items?.[0]}catch{}if(xhr.status>=200&&xhr.status<300&&result?.success){entry.fill.style.width='100%';entry.track.setAttribute('aria-valuenow','100');entry.status.className='queue-status success';entry.status.textContent='Uploaded';resolve(true)}else{entry.status.className='queue-status failed';entry.status.textContent=result?.error||'Upload failed';resolve(false)}});xhr.addEventListener('error',()=>{entry.status.className='queue-status failed';entry.status.textContent='Connection failed';resolve(false)});xhr.addEventListener('timeout',()=>{entry.status.className='queue-status failed';entry.status.textContent='Upload timed out. Try again.';resolve(false)});xhr.send(body)})}
    form.addEventListener('submit',async event=>{if(files.length===0)return;event.preventDefault();button.disabled=true;input.disabled=true;let succeeded=0;for(const entry of files){entry.fill.style.width='0%';entry.status.className='queue-status';entry.status.textContent='Uploading…';const ok=await uploadFile(entry);if(ok)succeeded++}const failed=files.length-succeeded;button.disabled=false;input.disabled=false;if(succeeded>0&&failed===0){summary.textContent='Upload complete. Refreshing gallery…';setTimeout(()=>location.reload(),1100)}else if(failed>0){summary.textContent=`${succeeded} uploaded · ${failed} failed`;if(succeeded>0){files=files.filter(entry=>entry.status.classList.contains('failed'));const refresh=document.createElement('a');refresh.href='./';refresh.className='queue-refresh';refresh.textContent='Refresh gallery to see uploaded images';summary.append(' · ',refresh)}}});
  }
})();
JS;
    echo '</script></body></html>';
}


/** Copy the old flat-file gallery into the first administrator's private folder once. */
function importLegacyMedia(PDO $pdo, MediaStorage $storage, string $dataDir): void
{
    if (Database::getMetadata($pdo, 'legacy_media_imported') === '1') return;
    $lockPath = $dataDir . DIRECTORY_SEPARATOR . '.legacy-media-import.lock';
    if (is_link($lockPath)) throw new RuntimeException('The legacy import lock must not be a symbolic link.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Could not create the legacy import lock.');
    @chmod($lockPath, 0600);
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock the legacy media import.');
        if (Database::getMetadata($pdo, 'legacy_media_imported') === '1') return;
        $ownerId = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1")->fetchColumn();
        if (!is_string($ownerId) || $ownerId === '') throw new RuntimeException('An administrator is required to import existing media.');
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $exists = $pdo->prepare('SELECT 1 FROM media WHERE id = :id LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO media (id, owner_id, storage_name, original_name, mime, size_bytes, created_at)
             VALUES (:id, :owner_id, :storage_name, :original_name, :mime, :size_bytes, :created_at)'
        );
        foreach (glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaPath) {
            if (is_link($metaPath) || !is_file($metaPath)) continue;
            $legacyId = basename($metaPath, '.json');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $legacyId)) continue;
            $meta = json_decode((string)@file_get_contents($metaPath), true);
            if (!is_array($meta) || !isset($extensions[$meta['mime'] ?? ''])) continue;
            $exists->execute([':id' => $legacyId]);
            if ($exists->fetchColumn() !== false) continue;
            $mime = (string)$meta['mime'];
            try {
                $copied = $storage->importLegacyFile($ownerId, $legacyId, $extensions[$mime], $mime, (string)($meta['name'] ?? 'image'));
            } catch (InvalidArgumentException $exception) {
                error_log('Skipped invalid legacy media ' . $legacyId . ': ' . $exception->getMessage());
                continue;
            }
            if ($copied === null) continue;
            try {
                $insert->execute([
                    ':id' => $copied['id'], ':owner_id' => $ownerId, ':storage_name' => $copied['storage_name'],
                    ':original_name' => $copied['original_name'], ':mime' => $copied['mime'],
                    ':size_bytes' => $copied['size_bytes'], ':created_at' => max(0, (int)($meta['created'] ?? time())),
                ]);
            } catch (Throwable $exception) {
                $storage->delete($ownerId, $copied['storage_name']);
                throw $exception;
            }
        }
        Database::setMetadata($pdo, 'legacy_media_imported', '1');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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

function sharePath(string $token): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = dirname($scriptName);
    if ($basePath === '/' || $basePath === '.') $basePath = '';
    return rtrim($basePath, '/') . '/s/' . rawurlencode($token);
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
