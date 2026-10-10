<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

$server = null;
$exitCode = 0;
$phase = 'isolated_server_startup';
try {
    $server = new IsolatedHttpServer();
    $phase = 'interview_route_validation';
    $response = $server->request('POST', '/api/interviews', '{}');
    if ($response['status'] !== 422
        || ($response['json']['error'] ?? null) !== 'Invalid or missing field: cv_text.') {
        throw new RuntimeException('invalid_candidate_response');
    }
    echo "Interview connectivity test passed (POST /api/interviews, HTTP 422, structured validation error).\n";

    $phase = 'malformed_json_rejection';
    $response = $server->request('POST', '/api/interviews', '{');
    if ($response['status'] !== 400 || ($response['json']['error'] ?? null) !== 'Request body must be valid JSON.') {
        throw new RuntimeException('malformed_json_response');
    }
    echo "Malformed JSON rejection passed (HTTP 400, structured JSON error).\n";
} catch (Throwable) {
    // Fixed phase names only: never display bodies, environment, or arbitrary exception text.
    fwrite(STDERR, 'Interview connectivity test failed: phase=' . $phase . ".\n");
    $exitCode = 1;
} finally {
    $server?->stop();
}
exit($exitCode);
