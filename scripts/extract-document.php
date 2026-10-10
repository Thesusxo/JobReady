<?php
declare(strict_types=1);

// Trusted CLI worker, never an uploaded script. No application bootstrap or private .env is loaded.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/config/autoload.php';

use JobReady\Documents\DocumentException;
use JobReady\Documents\DocumentExtractor;

libxml_use_internal_errors(true);
libxml_set_external_entity_loader(static fn () => null);
try {
    $format = $argv[2] ?? '';
    if ($format === 'pdf') {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new DocumentException('unavailable');
        }
        require $autoload;
    }
    $text = (new DocumentExtractor())->extract($argv[1] ?? '', $format);
    echo json_encode(['text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (DocumentException $exception) {
    echo json_encode(['error' => $exception->reason], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never return parser messages, paths, or source-document data in errors.
    echo '{"error":"malformed"}';
}
