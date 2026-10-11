<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

$server = new IsolatedHttpServer();
try {
    $page = $server->requestRaw('GET', '/app.html');
    if ($page['status'] !== 200 || !str_starts_with($page['content_type'], 'text/html')
        || !str_contains($page['body'], 'id="preparation-form"')
        || !str_contains($page['body'], 'id="answer-form"')
        || !str_contains($page['body'], 'id="readiness-card"')) {
        throw new RuntimeException('Frontend HTML could not be served.');
    }
    foreach (['/assets/app.css', '/assets/app.mjs', '/assets/interview-api.mjs', '/assets/answer-draft.mjs', '/assets/cv-upload.mjs', '/assets/voice-interview.mjs', '/assets/jobready.svg'] as $asset) {
        $response = $server->requestRaw('GET', $asset);
        if ($response['status'] !== 200 || $response['body'] === '') {
            throw new RuntimeException('Frontend asset could not be served.');
        }
        if (str_ends_with($asset, '.mjs') && !str_contains($response['content_type'], 'javascript')) {
            throw new RuntimeException('JavaScript module MIME type is incorrect.');
        }
    }
    $health = $server->request('GET', '/');
    if ($health['status'] !== 200 || $health['json'] !== ['status' => 'ok', 'service' => 'JobReady']) {
        throw new RuntimeException('Frontend changed the health endpoint.');
    }
    echo "Frontend HTTP asset and unchanged health endpoint tests passed (isolated server).\n";
} finally {
    $server->stop();
}
