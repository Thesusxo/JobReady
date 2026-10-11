<?php
declare(strict_types=1);

use JobReady\Security\DemoRateLimiter;
use JobReady\Storage\Database;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'JobReady\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) require $file;
    }
});

function demoRateCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Database::initialize($pdo);
$limiter = new DemoRateLimiter($pdo, 'offline-fictional-rate-limiter-key');
demoRateCheck($limiter->consume('judge_login', 'hashed-client-a', 2, 3600), 'First per-client attempt blocked.');
demoRateCheck($limiter->consume('judge_login', 'hashed-client-a', 2, 3600), 'Second per-client attempt blocked.');
demoRateCheck(!$limiter->consume('judge_login', 'hashed-client-a', 2, 3600), 'Per-client limit was not enforced.');
demoRateCheck($limiter->consume('judge_login', 'hashed-client-b', 2, 3600), 'Per-client buckets were not isolated.');
demoRateCheck($limiter->consume('judge_login_global', 'all', 2, 3600), 'First global attempt blocked.');
demoRateCheck($limiter->consume('judge_login_global', 'all', 2, 3600), 'Second global attempt blocked.');
demoRateCheck(!$limiter->consume('judge_login_global', 'all', 2, 3600), 'Global rate cap was not enforced.');
$buckets = $pdo->query('SELECT bucket_hash FROM demo_rate_limits')->fetchAll(PDO::FETCH_COLUMN);
demoRateCheck(count($buckets) === 3 && !in_array('hashed-client-a', $buckets, true), 'Rate-limit storage exposed raw identities.');
echo "Demo rate limiter unit checks passed (per-client, global, opaque identifiers).\n";
