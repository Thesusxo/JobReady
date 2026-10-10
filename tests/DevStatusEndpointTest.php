<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

function statusCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([['local', true, true], ['development', true, true], ['local', false, false],
    ['development', false, false], ['production', true, false], ['production', false, false],
    ['staging', true, false]] as [$env, $debug, $allowed]) {
    $server = new IsolatedHttpServer($env, $debug);
    try {
        $response = $server->request('GET', '/api/dev/status');
        statusCheck($response['status'] === ($allowed ? 200 : 404), 'Development status access gate failed.');
        if ($allowed) {
            statusCheck($response['json'] === [
                'APP_ENV' => $env,
                'debug_enabled' => true,
                'AI_PROVIDER' => 'gemini',
                'GEMINI_MODEL' => 'gemini-3.1-flash-lite',
                'diagnostics_enabled' => true,
                'sqlite_driver_available' => true,
            ], 'Status fields, values, or types were incorrect; unexpected fields must not escape.');
        } else {
            statusCheck($response['json'] === ['error' => 'Endpoint not found.'], 'Denied response exposed runtime details.');
        }
    } finally {
        $server->stop();
    }
}

// Alter only the disposable fixture, never the repository's private .env.
foreach ([['APP_DEBUG', 'TRUE'], ['APP_DEBUG', '1'], ['APP_ENV', 'LOCAL'], ['APP_ENV', 'local # comment']] as [$key, $value]) {
    $server = new IsolatedHttpServer('local', true);
    try {
        $fixture = (new ReflectionProperty($server, 'fixture'))->getValue($server);
        $file = $fixture . '/.env';
        $contents = file_get_contents($file);
        $contents = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $contents);
        statusCheck(file_put_contents($file, $contents) !== false, 'Cannot update disposable fixture.');
        $response = $server->request('GET', '/api/dev/status');
        statusCheck($response['status'] === 404 && $response['json'] === ['error' => 'Endpoint not found.'], 'Non-exact development settings enabled status.');
    } finally {
        $server->stop();
    }
}

$server = new IsolatedHttpServer('local', true);
try {
    $fixture = (new ReflectionProperty($server, 'fixture'))->getValue($server);
    // Fixture-only settings verify reporting uses effective configuration, not constants.
    file_put_contents($fixture . '/.env', "APP_ENV=local\nAPP_DEBUG=true\nAPP_DB_PATH=data/test.sqlite\nAI_PROVIDER=openai\nGEMINI_MODEL=gemini-2.5-flash\nGEMINI_API_KEY=\nOPENAI_API_KEY=\nNEATLOGS_WRITE_KEY=\n");
    $response = $server->request('GET', '/api/dev/status');
    statusCheck($response['status'] === 200 && $response['json']['AI_PROVIDER'] === 'openai'
        && $response['json']['GEMINI_MODEL'] === 'gemini-2.5-flash', 'Status did not report selected configuration.');
    statusCheck(count($response['json']) === 6, 'Unexpected runtime data was exposed.');
} finally {
    $server->stop();
}

$server = new IsolatedHttpServer('local', true);
try {
    $fixture = (new ReflectionProperty($server, 'fixture'))->getValue($server);
    file_put_contents($fixture . '/.env', "APP_ENV=local\nAPP_DEBUG=true\nAPP_DB_PATH=data/test.sqlite\nAI_PROVIDER=gemini\nGEMINI_MODEL=SYNTHETIC_PRIVATE_MARKER\nGEMINI_API_KEY=\nOPENAI_API_KEY=\nNEATLOGS_WRITE_KEY=\n");
    $response = $server->request('GET', '/api/dev/status');
    statusCheck($response['status'] === 200 && $response['json']['GEMINI_MODEL'] === null,
        'Invalid model configuration was echoed.');
    statusCheck(!str_contains(json_encode($response['json']), 'SYNTHETIC_PRIVATE_MARKER'), 'Unrecognized configuration escaped the status response.');
} finally {
    $server->stop();
}

echo "Development status endpoint tests passed (allowed fields, exact access gate, HTTP 404 when disabled).\n";
