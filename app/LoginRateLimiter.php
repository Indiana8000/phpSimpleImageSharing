<?php
declare(strict_types=1);

/** SQLite-backed throttling for sign-in attempts. */
final class LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;
    private const LOCK_SECONDS = 900;

    private function __construct()
    {
    }

    /**
     * Produce a stable pseudonymous key from REMOTE_ADDR without retaining the address.
     * Forwarded headers must not be passed here because clients can spoof them.
     */
    public static function key(string $remoteAddress): string
    {
        $packedAddress = @inet_pton(trim($remoteAddress));
        if ($packedAddress === false) {
            throw new InvalidArgumentException('A valid remote IP address is required for sign-in throttling.');
        }

        return hash('sha256', $packedAddress);
    }

    public static function isBlocked(PDO $pdo, string $key, ?int $now = null): bool
    {
        self::assertKey($key);
        $now ??= time();

        $cleanup = $pdo->prepare(
            'DELETE FROM login_attempts WHERE locked_until <= :now AND window_started_at <= :cutoff'
        );
        $cleanup->execute([':now' => $now, ':cutoff' => $now - self::WINDOW_SECONDS]);

        $statement = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE ip_hash = :ip_hash LIMIT 1');
        $statement->execute([':ip_hash' => $key]);
        $lockedUntil = $statement->fetchColumn();

        return $lockedUntil !== false && (int)$lockedUntil > $now;
    }

    /**
     * Record one failed sign-in and return the current failure count in the window.
     * Callers should check isBlocked() before credential verification and clear()
     * after a successful sign-in.
     */
    public static function recordFailure(PDO $pdo, string $key, ?int $now = null): int
    {
        self::assertKey($key);
        $now ??= time();

        // Drop stale IP keys during failed sign-ins so a changing set of clients
        // cannot leave old throttle rows in the database indefinitely.
        $cleanup = $pdo->prepare(
            'DELETE FROM login_attempts WHERE locked_until <= :now AND window_started_at <= :cutoff'
        );
        $cleanup->execute([':now' => $now, ':cutoff' => $now - self::WINDOW_SECONDS]);

        // A single UPSERT keeps concurrent PHP workers from losing increments.
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO login_attempts (ip_hash, attempts, window_started_at, locked_until)
            VALUES (:ip_hash, 1, :now_initial, 0)
            ON CONFLICT(ip_hash) DO UPDATE SET
                attempts = CASE
                    WHEN login_attempts.locked_until > :now_attempt_locked THEN login_attempts.attempts
                    WHEN :now_attempt_start < login_attempts.window_started_at
                      OR :now_attempt_elapsed - login_attempts.window_started_at >= :window_attempt THEN 1
                    ELSE login_attempts.attempts + 1
                END,
                window_started_at = CASE
                    WHEN login_attempts.locked_until > :now_window_locked THEN login_attempts.window_started_at
                    WHEN :now_window_start < login_attempts.window_started_at
                      OR :now_window_elapsed - login_attempts.window_started_at >= :window_window THEN :now_window_reset
                    ELSE login_attempts.window_started_at
                END,
                locked_until = CASE
                    WHEN login_attempts.locked_until > :now_lock_active THEN login_attempts.locked_until
                    WHEN :now_lock_start < login_attempts.window_started_at
                      OR :now_lock_elapsed - login_attempts.window_started_at >= :window_lock THEN 0
                    WHEN login_attempts.attempts + 1 >= :max_failures THEN :now_lock_until + :lock_duration
                    ELSE 0
                END
            SQL);
        $statement->execute([
            ':ip_hash' => $key,
            ':now_initial' => $now,
            ':now_attempt_locked' => $now,
            ':now_attempt_start' => $now,
            ':now_attempt_elapsed' => $now,
            ':window_attempt' => self::WINDOW_SECONDS,
            ':now_window_locked' => $now,
            ':now_window_start' => $now,
            ':now_window_elapsed' => $now,
            ':window_window' => self::WINDOW_SECONDS,
            ':now_window_reset' => $now,
            ':now_lock_active' => $now,
            ':now_lock_start' => $now,
            ':now_lock_elapsed' => $now,
            ':window_lock' => self::WINDOW_SECONDS,
            ':now_lock_until' => $now,
            ':max_failures' => self::MAX_FAILURES,
            ':lock_duration' => self::LOCK_SECONDS,
        ]);

        $count = $pdo->prepare('SELECT attempts FROM login_attempts WHERE ip_hash = :ip_hash');
        $count->execute([':ip_hash' => $key]);
        return (int)$count->fetchColumn();
    }

    public static function clear(PDO $pdo, string $key): void
    {
        self::assertKey($key);
        $statement = $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = :ip_hash');
        $statement->execute([':ip_hash' => $key]);
    }

    private static function assertKey(string $key): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new InvalidArgumentException('Invalid sign-in throttle key.');
        }
    }
}
