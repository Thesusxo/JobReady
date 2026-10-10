<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';
require __DIR__ . '/Support/DocumentFixtures.php';

$server = new IsolatedHttpServer();
$docx = tempnam(sys_get_temp_dir(), 'jobready-docx-fixture-');
try {
    $pdf = $server->upload('fictional.pdf', DocumentFixtures::pdf(), 'text/plain');
    if ($pdf['status'] !== 200 || $pdf['json']['format'] !== 'pdf' || !str_contains($pdf['json']['text'], 'PHP, MySQL')) {
        throw new RuntimeException('Real multipart PDF extraction failed.');
    }
    DocumentFixtures::docx($docx);
    $word = $server->upload('fictional.docx', file_get_contents($docx), 'application/pdf');
    if ($word['status'] !== 200 || $word['json']['format'] !== 'docx' || !str_contains($word['json']['text'], 'PHP, MySQL')) {
        throw new RuntimeException('Real multipart DOCX extraction failed.');
    }
    foreach ([
        ['text.pdf', 'Not a PDF', 422, 'malformed'],
        ['unsupported.txt', 'Fictional text', 415, 'unsupported'],
        ['image.pdf', DocumentFixtures::pdf(false), 422, 'no_text'],
        ['protected.pdf', DocumentFixtures::pdf(true, true), 422, 'encrypted'],
        ['large.pdf', str_repeat('X', 5 * 1024 * 1024 + 1), 413, 'too_large'],
    ] as [$filename, $contents, $status, $code]) {
        $result = $server->upload($filename, $contents);
        if ($result['status'] !== $status || ($result['json']['code'] ?? '') !== $code || isset($result['json']['text'])) {
            throw new RuntimeException('Multipart validation did not reject the document safely.');
        }
    }
    // The endpoint must not depend on or read .env, create SQLite state, or instantiate AI services.
    $fixture = (new ReflectionProperty($server, 'fixture'))->getValue($server);
    unlink($fixture . '/.env');
    $withoutConfig = $server->upload('fictional.pdf', DocumentFixtures::pdf());
    if ($withoutConfig['status'] !== 200 || is_dir($fixture . '/data')) {
        throw new RuntimeException('Upload extraction unexpectedly loaded application configuration/state.');
    }
    echo "CV upload HTTP tests passed (PDF/DOCX multipart, actual bytes, upload limits, cleanup, no private configuration or AI).\n";
} finally {
    unlink($docx);
    $server->stop();
}
