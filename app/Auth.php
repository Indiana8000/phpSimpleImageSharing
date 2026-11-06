<?php
declare(strict_types=1);

/**
 * User and authentication operations for phpSimpleImageSharing.
 *
 * This class deliberately has no HTTP or session behavior. The front controller
 * is responsible for CSRF checks, session rotation, rate limiting, and checking
 * that the current account is still active on each request.
 */
final class Auth
{
    private const ROLES = ['admin', 'member'];

    private function __construct()
    {
    }

    /**
     * Create the first administrator from the legacy configured password hash.
     * This is safe to call on every request: it inserts only while the users
     * table is empty, so later config changes never reset an existing account.
     *
     * @return bool True only when this call created the account.
     */
    public static function bootstrapInitialAdmin(
        PDO $pdo,
        string $legacyPasswordHash,
        string $username = 'admin',
        string $displayName = 'Administrator'
    ): bool {
        // Once any account exists, the legacy config hash is no longer needed.
        // Do not let a removed or changed bootstrap setting affect sign-in.
        if ($pdo->query('SELECT 1 FROM users LIMIT 1')->fetchColumn() !== false) {
            return false;
        }

        if (!self::isPasswordHash($legacyPasswordHash)) {
            throw new InvalidArgumentException('The initial administrator password hash is missing or invalid.');
        }

        $username = self::normalizeUsername($username);
        $displayName = self::normalizeDisplayName($displayName);
        $id = bin2hex(random_bytes(16));
        $now = time();

        // A single INSERT ... SELECT makes the empty-table check atomic with the
        // insert. SQLite serializes competing writers, so parallel first visits
        // cannot create duplicate bootstrap accounts.
        $statement = $pdo->prepare(
            'INSERT INTO users (id, username, display_name, password_hash, role, active, created_at)
             SELECT :id, :username, :display_name, :password_hash, \'admin\', 1, :created_at
             WHERE NOT EXISTS (SELECT 1 FROM users LIMIT 1)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => $legacyPasswordHash,
            ':created_at' => $now,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Verify credentials and return a public user record, or null on failure.
     * The returned record never contains password_hash.
     *
     * @return array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int}|null
     */
    public static function login(PDO $pdo, string $username, string $password): ?array
    {
        $username = trim($username);
        if (strlen($username) > 320 || strlen($password) > 1024) {
            password_verify(self::dummyPasswordValue(), self::dummyPasswordHash());
            return null;
        }
        $statement = $pdo->prepare(
            'SELECT id, username, display_name, password_hash, role, active, auth_version, created_at
             FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute([':username' => $username]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            // Keep the work factor comparable for unknown usernames.
            password_verify($password, self::dummyPasswordHash());
            return null;
        }

        $valid = password_verify($password, (string)$user['password_hash']);
        if (!$valid || (int)$user['active'] !== 1) {
            return null;
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $user['id'],
            ]);
        }

        unset($user['password_hash']);
        return self::publicUser($user);
    }

    /** @return array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int}|null */
    public static function findById(PDO $pdo, string $id): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, display_name, role, active, auth_version, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($user) ? self::publicUser($user) : null;
    }

    /** @return array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int}|null */
    public static function findByUsername(PDO $pdo, string $username): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, display_name, role, active, auth_version, created_at FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute([':username' => trim($username)]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($user) ? self::publicUser($user) : null;
    }

    /** @return list<array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int}> */
    public static function listUsers(PDO $pdo, array $actor): array
    {
        self::requireAdmin($actor);
        $statement = $pdo->query(
            'SELECT id, username, display_name, role, active, auth_version, created_at
             FROM users ORDER BY active DESC, username COLLATE NOCASE ASC'
        );
        return array_map(self::publicUser(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int} */
    public static function createUser(
        PDO $pdo,
        array $actor,
        string $username,
        string $displayName,
        string $password,
        string $role = 'member'
    ): array {
        self::requireAdmin($actor);
        $username = self::normalizeUsername($username);
        $displayName = self::normalizeDisplayName($displayName);
        self::validatePassword($password);
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be admin or member.');
        }

        $user = [
            'id' => bin2hex(random_bytes(16)),
            'username' => $username,
            'display_name' => $displayName,
            'role' => $role,
            'active' => 1,
            'auth_version' => 0,
            'created_at' => time(),
        ];
        $statement = $pdo->prepare(
            'INSERT INTO users (id, username, display_name, password_hash, role, active, created_at)
             VALUES (:id, :username, :display_name, :password_hash, :role, 1, :created_at)'
        );
        $statement->execute([
            ':id' => $user['id'],
            ':username' => $user['username'],
            ':display_name' => $user['display_name'],
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $user['role'],
            ':created_at' => $user['created_at'],
        ]);

        return $user;
    }

    public static function resetPassword(PDO $pdo, array $actor, string $targetUserId, string $newPassword): void
    {
        self::requireAdmin($actor);
        self::validatePassword($newPassword);

        $statement = $pdo->prepare('UPDATE users SET password_hash = :hash, auth_version = auth_version + 1 WHERE id = :id');
        $statement->execute([
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $targetUserId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('User not found.');
        }
    }

    public static function setActive(PDO $pdo, array $actor, string $targetUserId, bool $active): void
    {
        self::requireAdmin($actor);
        $statement = $pdo->prepare('SELECT id, role, active FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $targetUserId]);
        $target = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($target)) {
            throw new RuntimeException('User not found.');
        }

        if (!$active && $target['role'] === 'admin' && (int)$target['active'] === 1) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($count <= 1) {
                throw new RuntimeException('The last active administrator cannot be disabled.');
            }
        }

        $update = $pdo->prepare('UPDATE users SET active = :new_active, auth_version = auth_version + 1 WHERE id = :id AND active <> :old_active');
        $newActive = $active ? 1 : 0;
        $update->execute([':new_active' => $newActive, ':old_active' => $newActive, ':id' => $targetUserId]);
    }

    private static function requireAdmin(array $actor): void
    {
        if (($actor['role'] ?? null) !== 'admin' || (int)($actor['active'] ?? 0) !== 1) {
            throw new RuntimeException('Administrator access required.');
        }
    }

    private static function normalizeUsername(string $username): string
    {
        $username = trim($username);
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{2,31}\z/', $username)) {
            throw new InvalidArgumentException('Username must be 3–32 characters and use letters, numbers, dots, underscores, or hyphens.');
        }
        return $username;
    }

    private static function normalizeDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 240 || preg_match('//u', $displayName) !== 1) {
            throw new InvalidArgumentException('Display name must be valid UTF-8 and contain 1–80 characters.');
        }
        preg_match_all('/./us', $displayName, $characters);
        if (count($characters[0]) > 80) {
            throw new InvalidArgumentException('Display name must be valid UTF-8 and contain 1–80 characters.');
        }
        return $displayName;
    }

    private static function validatePassword(string $password): void
    {
        $length = strlen($password);
        if ($length < 12 || $length > 1024) {
            throw new InvalidArgumentException('Password must be between 12 and 1024 bytes.');
        }
    }

    private static function isPasswordHash(string $hash): bool
    {
        if ($hash === '' || str_starts_with($hash, 'replace-with-')) {
            return false;
        }
        $info = password_get_info($hash);
        return ($info['algoName'] ?? 'unknown') !== 'unknown';
    }

    private static function dummyPasswordHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('invalid-account-password-check', PASSWORD_DEFAULT);
        }
        return $hash;
    }

    private static function dummyPasswordValue(): string
    {
        return 'invalid-account-password-check';
    }

    /** @param array<string,mixed> $user @return array{id:string,username:string,display_name:string,role:string,active:int,auth_version:int,created_at:int} */
    private static function publicUser(array $user): array
    {
        return [
            'id' => (string)$user['id'],
            'username' => (string)$user['username'],
            'display_name' => (string)$user['display_name'],
            'role' => (string)$user['role'],
            'active' => (int)$user['active'],
            'auth_version' => (int)($user['auth_version'] ?? 1),
            'created_at' => (int)$user['created_at'],
        ];
    }
}
