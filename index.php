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

$action = (string)($_GET['action'] ?? '');
$error = '';
$notice = '';
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ./'); exit;
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

if ($action === 'image') {
    $id = (string)($_GET['id'] ?? '');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        if ($action === 'upload') {
            $upload = $_FILES['image'] ?? [];
            $stored = $mediaStorage->storeUploadedFile($currentUser['id'], is_array($upload) ? $upload : [], $maxBytes);
            $createdAt = time();
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO media (id, owner_id, storage_name, original_name, mime, size_bytes, created_at)
                     VALUES (:id, :owner_id, :storage_name, :original_name, :mime, :size_bytes, :created_at)'
                );
                $statement->execute([
                    ':id' => $stored['id'], ':owner_id' => $currentUser['id'], ':storage_name' => $stored['storage_name'],
                    ':original_name' => $stored['original_name'], ':mime' => $stored['mime'],
                    ':size_bytes' => $stored['size_bytes'], ':created_at' => $createdAt,
                ]);
            } catch (Throwable $exception) {
                $mediaStorage->delete($currentUser['id'], $stored['storage_name']);
                throw $exception;
            }
            $imageUrl = '?action=image&id=' . rawurlencode($stored['id']);
            $notice = 'Image uploaded. <a href="' . h($imageUrl) . '">View image</a> <button type="button" class="inline-action" data-copy-link="' . h($imageUrl) . '">Copy link</button>';
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $statement = $pdo->prepare('SELECT owner_id, storage_name FROM media WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $id]);
            $media = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($media) && ($currentUser['role'] === 'admin' || $media['owner_id'] === $currentUser['id'])) {
                $mediaStorage->delete((string)$media['owner_id'], (string)$media['storage_name']);
                $delete = $pdo->prepare('DELETE FROM media WHERE id = :id');
                $delete->execute([':id' => $id]);
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
        'SELECT media.id, media.owner_id, media.storage_name, media.original_name, media.mime, media.size_bytes, media.created_at,
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
pageStart('Gallery');
if ($error) echo '<p class="error">' . h($error) . '</p>';
if ($notice) echo '<p class="notice">' . $notice . '</p>';
echo '<section class="welcome"><div><p class="eyebrow">Your private album · Signed in as ' . h($currentUser['username']) . '</p><h1>Our moments</h1><p class="subheading">A small collection of moments shared with friends.</p></div><div class="welcome-actions">' . ($currentUser['role'] === 'admin' ? '<a class="quiet-button" href="?action=users">Manage users</a>' : '') . '<span class="private-badge"><span></span>Private</span></div></section>';
echo '<form class="upload" method="post" action="?action=upload" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="upload-copy"><span class="upload-icon" aria-hidden="true">↑</span><div><strong>Add an image</strong><small>JPEG, PNG, GIF, or WebP · up to ' . h((string)round($maxBytes / 1024 / 1024)) . ' MB</small></div></div><label class="file-control"><span>Choose file</span><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button class="primary">Upload</button></form>';
echo '<div class="section-heading"><div><h2>All images</h2><p>' . count($images) . ' ' . (count($images) === 1 ? 'image' : 'images') . '</p></div><span class="sort-label">Newest first</span></div>';
if (!$images) echo '<div class="empty"><span class="empty-icon">▧</span><h2>Your album is ready for its first image</h2><p>Add an image above to get started.</p></div>';
echo '<div class="grid">';
foreach ($images as $image) {
    $id = (string)$image['id'];
    $imageUrl = '?action=image&id=' . rawurlencode($id);
    $name = (string)$image['original_name'];
    $created = (int)$image['created_at'];
    $canDelete = $currentUser['role'] === 'admin' || $image['owner_id'] === $currentUser['id'];
    echo '<article class="photo-card"><a class="photo" href="' . h($imageUrl) . '"><img loading="lazy" src="' . h($imageUrl) . '" alt="' . h($name) . '"></a><div class="photo-info"><strong title="' . h($name) . '">' . h($name) . '</strong><time datetime="' . h(date('Y-m-d', $created)) . '">' . h(date('Y-m-d', $created)) . '</time><small class="owner-label">Uploaded by ' . h($image['display_name']) . '</small><div class="photo-actions"><button type="button" class="text-action" data-copy-link="' . h($imageUrl) . '">Copy link</button>' . ($canDelete ? '<form method="post" action="?action=delete" onsubmit="return confirm(\'Delete this image?\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . h($id) . '"><button class="text-action danger">Delete</button></form>' : '') . '</div></div></article>';
}
echo '</div><form class="logout" method="post" action="?action=logout"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="text-action">Sign out</button></form><div class="toast" id="toast" role="status" aria-live="polite"></div>';
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
    echo '<style>.login-panel{max-width:460px;margin:0 auto;padding:28px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow)}.login-panel h1{font:500 32px/1.2 Georgia,serif;letter-spacing:-.04em;margin:0}.login-panel .subheading{margin-bottom:24px}.login-form{display:grid;gap:9px}.login-form label,.user-create label,.password-reset{font-size:12px;font-weight:650}.login-form input,.user-create input,.user-create select,.password-reset input{font:inherit;color:var(--ink);background:var(--raised);border:1px solid var(--line);border-radius:8px;padding:10px 12px}.login-form .primary{margin-top:5px}.welcome-actions{display:flex;align-items:center;gap:9px}.admin-panel{padding:18px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);margin-bottom:28px}.admin-panel h2{font-size:15px;margin:0}.admin-panel>p{color:var(--muted);font-size:12px;margin:3px 0 16px}.user-create{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;align-items:end}.user-create label,.password-reset{display:grid;gap:5px}.user-create .primary{height:39px}.user-list{display:grid;gap:9px}.user-card{display:flex;align-items:center;gap:15px;padding:13px;border:1px solid var(--line);border-radius:9px;background:var(--surface)}.user-card>div:first-child{flex:1;min-width:140px}.user-card strong,.user-card small{display:block}.user-card strong{font-size:13px}.user-card small{color:var(--muted);font-size:11px}.user-actions{display:flex;align-items:end;gap:8px;margin:0}.password-reset input{width:145px;padding:7px}.photo-info .owner-label{display:block;color:var(--muted);font-size:10px;margin-top:3px}@media(max-width:750px){.user-create{grid-template-columns:repeat(2,minmax(0,1fr))}.user-card{flex-wrap:wrap}.user-card>div:first-child{flex-basis:100%}.password-reset input{width:130px}}@media(max-width:600px){.login-panel{padding:20px}.brand{font-size:13px;letter-spacing:-.07em}.brand-mark{width:25px;height:25px}.welcome-actions{align-items:flex-end;flex-direction:column}.user-create{grid-template-columns:1fr}.user-card{align-items:flex-start}.user-actions{flex-wrap:wrap}}</style>';
}
function pageEnd(): void {
    echo '</main></div><script>(()=>{const root=document.documentElement,button=document.getElementById("themeToggle"),key="phpSimpleImageSharing-theme";let choice=localStorage.getItem(key)||"system";function apply(){if(choice==="system")root.removeAttribute("data-theme");else root.dataset.theme=choice;button.textContent="Theme: "+(choice==="system"?"System":choice==="light"?"Light":"Dark");button.setAttribute("aria-label","Change theme. Current: "+choice)}apply();button.addEventListener("click",()=>{choice=choice==="system"?"light":choice==="light"?"dark":"system";localStorage.setItem(key,choice);apply()});let toastTimer;const toast=document.getElementById("toast");document.querySelectorAll("[data-copy-link]").forEach(control=>control.addEventListener("click",async()=>{const url=new URL(control.dataset.copyLink,location.href).href;try{await navigator.clipboard.writeText(url);if(toast){toast.textContent="Link copied";toast.classList.add("show");clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.remove("show"),2200)}}catch{window.prompt("Copy this link:",url)}}))})();</script></body></html>';
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
