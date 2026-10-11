<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';
require __DIR__ . '/Support/DocumentFixtures.php';

$server = new IsolatedHttpServer();
$docx = tempnam(sys_get_temp_dir(), 'jobready-docx-fixture-');
try {
    $pdf = $server->upload('fictional.pdf', DocumentFixtures::pdf(), 'text/plain', '198.51.100.11');
    if ($pdf['status'] !== 200 || $pdf['json']['format'] !== 'pdf' || !str_contains($pdf['json']['text'], 'PHP, MySQL')) {
        throw new RuntimeException('Real multipart PDF extraction failed.');
    }
    DocumentFixtures::docx($docx);
    $word = $server->upload('fictional.docx', file_get_contents($docx), 'application/pdf', '198.51.100.12');
    if ($word['status'] !== 200 || $word['json']['format'] !== 'docx' || !str_contains($word['json']['text'], 'PHP, MySQL')) {
        throw new RuntimeException('Real multipart DOCX extraction failed.');
    }
    foreach ([
        ['text.pdf', 'Not a PDF', 422, 'malformed'],
        ['unsupported.txt', 'Fictional text', 415, 'unsupported'],
        ['image.pdf', DocumentFixtures::pdf(false), 422, 'no_text'],
        ['protected.pdf', DocumentFixtures::pdf(true, true), 422, 'encrypted'],
        ['large.pdf', str_repeat('X', 5 * 1024 * 1024 + 1), 413, 'too_large'],
    ] as $index => [$filename, $contents, $status, $code]) {
        $result = $server->upload($filename, $contents, 'application/octet-stream', '198.51.100.' . (20 + $index));
        if ($result['status'] !== $status || ($result['json']['code'] ?? '') !== $code || isset($result['json']['text'])) {
            throw new RuntimeException('Multipart validation did not reject the document safely.');
        }
    }
    // Bootstrap initializes the rate-limit store, but extraction itself must not create interviews or invoke AI.
    $fixture = (new ReflectionProperty($server, 'fixture'))->getValue($server);
    $withoutConfig = $server->upload('fictional.pdf', DocumentFixtures::pdf(), 'application/octet-stream', '198.51.100.40');
    $pdo = new PDO('sqlite:' . $fixture . '/data/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $interviewCount = (int) $pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn();
    $pdo = null;
    if ($withoutConfig['status'] !== 200 || $interviewCount !== 0) {
        throw new RuntimeException('Upload extraction created an interview or failed in the keyless fixture.');
    }
    echo "CV upload HTTP tests passed (PDF/DOCX multipart, actual bytes, upload limits, cleanup, no private configuration or AI).\n";
} finally {
    unlink($docx);
    $server->stop();
}
