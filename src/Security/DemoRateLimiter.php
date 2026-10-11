<?php
declare(strict_types=1);

namespace JobReady\Security;

use PDO;

final class DemoRateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly string $key)
    {
    }

    public function consume(string $scope, string $identity, int $limit, int $windowSeconds): bool
    {
        if (!preg_match('/^[a-z_]{1,48}$/', $scope) || $limit < 1 || $windowSeconds < 1) {
            return false;
        }
        $bucket = hash_hmac('sha256', $scope . "\0" . $identity, $this->key);
        $now = time();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO demo_rate_limits (bucket_hash, window_started, hit_count)
            VALUES (:bucket, :window, 1)
            ON CONFLICT(bucket_hash) DO UPDATE SET
                hit_count = CASE WHEN window_started = excluded.window_started
                    THEN MIN(hit_count + 1, :cap) ELSE 1 END,
                window_started = excluded.window_started
            SQL);
        $statement->execute(['bucket' => $bucket, 'window' => $window, 'cap' => $limit + 1]);
        $read = $this->pdo->prepare('SELECT hit_count FROM demo_rate_limits WHERE bucket_hash = :bucket');
        $read->execute(['bucket' => $bucket]);
        $allowed = (int) $read->fetchColumn() <= $limit;

        // Opportunistic cleanup; entries contain HMAC buckets, never client IPs or session IDs.
        if (($now % 97) === 0) {
            $cleanup = $this->pdo->prepare('DELETE FROM demo_rate_limits WHERE window_started < :cutoff');
            $cleanup->execute(['cutoff' => $now - (8 * 86400)]);
        }
        return $allowed;
    }
}
