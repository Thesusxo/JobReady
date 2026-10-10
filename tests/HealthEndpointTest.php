<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

$server = null;
$exitCode = 0;
try {
    $server = new IsolatedHttpServer();
    $response = $server->request('GET', '/');
    if ($response['status'] !== 200 || ($response['json']['status'] ?? null) !== 'ok'
        || ($response['json']['service'] ?? null) !== 'JobReady') {
        throw new RuntimeException('health_response');
    }
    echo "HTTP health endpoint test passed (GET /, HTTP 200, valid JobReady JSON).\n";
} catch (Throwable) {
    fwrite(STDERR, "HTTP health endpoint test failed: local server startup, HTTP status, or JSON assertion failed.\n");
    $exitCode = 1;
} finally {
    $server?->stop();
}
exit($exitCode);
