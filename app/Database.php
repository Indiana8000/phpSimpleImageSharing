<?php
declare(strict_types=1);

/**
 * PDO SQLite connection and schema bootstrap for phpSimpleImageSharing.
 *
 * The database is stored directly in the configured private data directory.
 * Media bytes remain in per-user directories and are not stored in SQLite.
 */
final class Database
{
    private const DATABASE_FILE = 'phpSimpleImageSharing.sqlite';
    private const SCHEMA_VERSION = 1;
    private const BUSY_TIMEOUT_MS = 5000;

    /**
     * Open the SQLite database and ensure the current schema exists.
     *
     * The data directory must already exist, be writable by PHP, and be
     * outside the web root. WAL is intentionally not enabled by default.
     */
    public static function connect(string $dataDir): PDO
    {
        $resolvedDir = realpath($dataDir);
        if ($resolvedDir === false || !is_dir($resolvedDir) || !is_writable($resolvedDir)) {
            throw new RuntimeException('The configured private data directory must exist and be writable.');
        }

        $databasePath = $resolvedDir . DIRECTORY_SEPARATOR . self::DATABASE_FILE;
        if (is_link($databasePath)) {
            throw new RuntimeException('The SQLite database path must not be a symbolic link.');
        }

        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        @chmod($databasePath, 0600);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        self::migrate($pdo);

        return $pdo;
    }

    /** Apply any schema migrations required by this application version. */
    private static function migrate(PDO $pdo): void
    {
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException('The database was created by a newer version of phpSimpleImageSharing.');
        }
        if ($version === self::SCHEMA_VERSION) {
            return;
        }

        // An immediate transaction serializes simultaneous first-run schema setup.
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            // Another request may have completed the migration while this one waited.
            $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version > self::SCHEMA_VERSION) {
                throw new RuntimeException('The database was created by a newer version of phpSimpleImageSharing.');
            }
            if ($version === 0) {
                self::createInitialSchema($pdo);
                self::createLoginAttemptsTable($pdo);
                $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            } elseif ($version !== self::SCHEMA_VERSION) {
                // No released version used an older layout, so there is nothing to migrate.
                throw new RuntimeException('Unsupported development database; delete it to start fresh.');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    private static function createInitialSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id TEXT PRIMARY KEY NOT NULL,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('admin', 'member')),
                active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                auth_version INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE media (
                id TEXT PRIMARY KEY NOT NULL,
                owner_id TEXT NOT NULL,
                storage_name TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime TEXT NOT NULL,
                size_bytes INTEGER NOT NULL CHECK (size_bytes >= 0),
                created_at INTEGER NOT NULL,
                expires_at INTEGER,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (owner_id, storage_name)
            )
            SQL);
        $pdo->exec('CREATE INDEX media_created_at_idx ON media(created_at DESC, id)');
        $pdo->exec('CREATE INDEX media_owner_created_at_idx ON media(owner_id, created_at DESC, id)');
        $pdo->exec('CREATE INDEX media_expires_at_idx ON media(expires_at) WHERE expires_at IS NOT NULL');

        $pdo->exec(<<<'SQL'
            CREATE TABLE share_links (
                id TEXT PRIMARY KEY NOT NULL,
                media_id TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                token TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER,
                revoked_at INTEGER,
                FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
            )
            SQL);
        $pdo->exec('CREATE INDEX share_links_media_id_idx ON share_links(media_id)');
        $pdo->exec('CREATE INDEX share_links_expires_at_idx ON share_links(expires_at) WHERE expires_at IS NOT NULL');
    }

    private static function createLoginAttemptsTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE login_attempts (
                ip_hash TEXT PRIMARY KEY NOT NULL,
                attempts INTEGER NOT NULL,
                window_started_at INTEGER NOT NULL,
                locked_until INTEGER NOT NULL DEFAULT 0
            )
            SQL);
    }
}
