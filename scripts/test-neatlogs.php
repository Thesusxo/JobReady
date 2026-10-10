<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') !== '--send') {
    fwrite(STDERR, "No trace sent. To explicitly send the non-sensitive development test trace, run: php scripts/test-neatlogs.php --send\n");
    exit(2);
}

try {
    /** @var array{environment: string, neatlogs: JobReady\Observability\NeatlogsClientInterface} $services */
    $services = require dirname(__DIR__) . '/config/bootstrap.php';
    if (!in_array($services['environment'], ['local', 'development'], true)) {
        fwrite(STDERR, "Refusing to send: APP_ENV must be local or development.\n");
        exit(2);
    }
    if (!$services['neatlogs'] instanceof JobReady\Observability\NeatlogsClient) {
        fwrite(STDERR, "Refusing to send: neatlogs write key and project must be configured in .env.\n");
        exit(2);
    }

    $sent = $services['neatlogs']->recordEvent('jobready.integration_test');
    if (!$sent) {
        if ($services['neatlogs'] instanceof JobReady\Observability\NeatlogsClient) {
            $diagnostic = $services['neatlogs']->getLastFailureDiagnostic();
            if ($diagnostic !== null) {
                $message = 'HTTP 403 diagnostic: category=' . $diagnostic['category'];
                if ($diagnostic['code'] !== null) {
                    $message .= ' code=' . $diagnostic['code'];
                }
                fwrite(STDERR, $message . "\n");
                exit(1);
            }
        }
        fwrite(STDERR, "Trace submission failed; see the generic server-side error log for transport status.\n");
        exit(1);
    }
    fwrite(STDOUT, "Trace accepted by the ingest endpoint. Check your neatlogs project for jobready.integration_test.\n");
} catch (Throwable $exception) {
    // Never display exception text; it could contain configuration or transport details.
    fwrite(STDERR, "Test setup failed. Check PHP configuration and .env without sharing credentials.\n");
    exit(1);
}
