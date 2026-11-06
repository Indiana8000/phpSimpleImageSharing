<?php
declare(strict_types=1);

/**
 * Removes expired media in small, serialized batches. Expired media is already
 * hidden by gallery and delivery queries; this service reclaims its file bytes
 * and metadata during ordinary requests without requiring a cron daemon.
 */
final class MediaCleanup
{
    private const MAX_BATCH_SIZE = 100;

    /**
     * Delete up to $limit expired media items and their stored files.
     *
     * The SQLite write transaction serializes cleanup workers. Files are
     * removed before metadata is committed, so an interruption can at worst
     * leave an expired database row pointing at an absent file; expired media
     * remains inaccessible and a later cleanup pass can remove that row.
     *
     * @return int Number of media rows removed.
     */
    public static function run(PDO $pdo, MediaStorage $storage, int $limit = 10): int
    {
        $limit = max(1, min(self::MAX_BATCH_SIZE, $limit));
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $select = $pdo->prepare(
                'SELECT id, owner_id, storage_name
                 FROM media
                 WHERE expires_at IS NOT NULL AND expires_at <= :now
                 ORDER BY expires_at ASC, id ASC
                 LIMIT :batch_limit'
            );
            $select->bindValue(':now', $now, PDO::PARAM_INT);
            $select->bindValue(':batch_limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $expired = $select->fetchAll(PDO::FETCH_ASSOC);

            if ($expired === []) {
                $pdo->exec('COMMIT');
                return 0;
            }

            foreach ($expired as $media) {
                $storage->delete((string)$media['owner_id'], (string)$media['storage_name']);
            }

            $delete = $pdo->prepare(
                'DELETE FROM media
                 WHERE id = :id AND expires_at IS NOT NULL AND expires_at <= :now'
            );
            foreach ($expired as $media) {
                $delete->execute([':id' => $media['id'], ':now' => $now]);
            }

            $pdo->exec('COMMIT');
            return count($expired);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }
}
