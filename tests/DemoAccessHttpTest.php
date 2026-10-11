<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

function accessHttpCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$code = 'fixture-only-code';
$hash = password_hash($code, PASSWORD_DEFAULT);
$server = new IsolatedHttpServer('production', false, $hash, '127.0.0.1,::1');
try {
    $paths = [
        ['POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST],
        ['POST', '/api/interviews/123e4567-e89b-42d3-a456-426614174000/answers', '{"answer":"fictional fixture answer"}'],
        ['GET', '/api/interviews/123e4567-e89b-42d3-a456-426614174000', null],
        ['POST', '/api/cv/extract', '{}'],
    ];
    foreach ($paths as [$method, $path, $body]) {
        $response = $server->request($method, $path, $body);
        accessHttpCheck($response['status'] === 401 && isset($response['json']['error']), 'Unauthenticated sensitive route was not denied.');
        accessHttpCheck(!isset($response['json']['diagnostics'], $response['json']['interview']), 'Unauthorized response contained sensitive data.');
    }
    accessHttpCheck($server->fixtureSessionFileCount() === 0, 'Unauthenticated API calls created PHP session files.');
    $status = $server->request('GET', '/api/demo/session');
    accessHttpCheck($status['status'] === 200 && $status['json']['access_required'] === true
        && $status['json']['authenticated'] === false && $status['json']['csrf_token'] === null,
        'Unauthenticated judge gate status was unsafe.');
    $login = $server->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}', ['CF-Visitor' => '{"scheme":"https"}']);
    accessHttpCheck($login['status'] === 401 && !str_contains(json_encode($login['json']), $code), 'Wrong access code was not safely rejected.');
    accessHttpCheck($server->fixtureSessionFileCount() === 0, 'Failed login created a PHP session file.');
    $login = $server->request('POST', '/api/demo/login', json_encode(['access_code' => $code], JSON_THROW_ON_ERROR), [
        'CF-Visitor' => '{"scheme":"https"}', 'Cookie' => 'JOBREADY_DEMO=attacker-controlled-session-id',
    ]);
    accessHttpCheck($login['status'] === 200 && $login['json']['authenticated'] === true, 'Correct fictional judge code did not authenticate.');
    accessHttpCheck(preg_match('/^[a-f0-9]{64}$/', $login['json']['csrf_token'] ?? '') === 1, 'CSRF token missing.');
    accessHttpCheck($server->fixtureSessionFileCount() === 1, 'Successful login did not establish a single regenerated session.');
    $cookie = $server->lastSetCookie ?? '';
    preg_match('/^JOBREADY_DEMO=([^;]+)/', $cookie, $cookieMatch);
    accessHttpCheck(isset($cookieMatch[1]) && $cookieMatch[1] !== 'attacker-controlled-session-id', 'Session identifier was not regenerated after authentication.');
    accessHttpCheck(!$server->fixtureSessionsContain($code), 'Plaintext judge access code was persisted in the PHP session.');
    accessHttpCheck(stripos($cookie, 'httponly') !== false && stripos($cookie, 'secure') !== false && stripos($cookie, 'SameSite=Strict') !== false,
        'HTTPS trusted-proxy cookie flags incorrect: captured=' . ($cookie !== '' ? 'yes' : 'no') . ',httponly=' . (str_contains($cookie, 'HttpOnly') ? 'yes' : 'no') . ',secure=' . (stripos($cookie, 'secure') !== false ? 'yes' : 'no') . ',samesite_strict=' . (stripos($cookie, 'samesite=strict') !== false ? 'yes' : 'no') . '.');

    $validCandidate = IsolatedHttpServer::DIAGNOSTIC_REQUEST;
    $missingCsrf = $server->request('POST', '/api/interviews', $validCandidate);
    accessHttpCheck($missingCsrf['status'] === 403, 'State-changing API accepted missing CSRF token.');
    $wrongCsrf = $server->request('POST', '/api/interviews', $validCandidate, ['X-CSRF-Token' => str_repeat('0', 64)]);
    accessHttpCheck($wrongCsrf['status'] === 403, 'State-changing API accepted incorrect CSRF token.');

    $token = $login['json']['csrf_token'];
    $last = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $last = $server->request('POST', '/api/interviews', $validCandidate, ['X-CSRF-Token' => $token]);
        if ($attempt < 2) accessHttpCheck($last['status'] === 503, 'Keyless offline fixture must fail before external AI transport.');
    }
    accessHttpCheck($last['status'] === 429, 'Per-client interview creation cap was not applied.');

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $answerLimit = $server->request('POST', '/api/interviews/123e4567-e89b-42d3-a456-426614174000/answers',
            '{"answer":"fictional fixture answer"}', ['X-CSRF-Token' => $token]);
        accessHttpCheck($answerLimit['status'] === 404, 'Unknown interview should stop before any AI call; received HTTP ' . $answerLimit['status'] . '.');
    }
    $answerLimit = $server->request('POST', '/api/interviews/123e4567-e89b-42d3-a456-426614174000/answers',
        '{"answer":"fictional fixture answer"}', ['X-CSRF-Token' => $token]);
    accessHttpCheck($answerLimit['status'] === 429, 'Per-session answer-turn cap was not applied.');

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $firstUpload = $server->request('POST', '/api/cv/extract', '{}', ['X-CSRF-Token' => $token]);
        accessHttpCheck($firstUpload['status'] === 415, 'Authenticated upload route did not reject wrong content type safely.');
    }
    $limitedUpload = $server->request('POST', '/api/cv/extract', '{}', ['X-CSRF-Token' => $token]);
    accessHttpCheck($limitedUpload['status'] === 429, 'CV extraction cap was not applied.');
    $logout = $server->request('POST', '/api/demo/logout', '{}');
    accessHttpCheck($logout['status'] === 403, 'Logout accepted missing CSRF token.');
    $logout = $server->request('POST', '/api/demo/logout', '{}', ['X-CSRF-Token' => $token]);
    accessHttpCheck($logout['status'] === 200, 'CSRF-protected logout failed.');
    $again = $server->request('GET', '/api/interviews/123e4567-e89b-42d3-a456-426614174000');
    accessHttpCheck($again['status'] === 401, 'Logout did not revoke judge access.');
} finally {
    $server->stop();
}

$untrustedProxy = new IsolatedHttpServer('production', false, $hash, '192.0.2.44');
try {
    $response = $untrustedProxy->request('POST', '/api/demo/login', json_encode(['access_code' => $code], JSON_THROW_ON_ERROR), ['CF-Visitor' => '{"scheme":"https"}']);
    accessHttpCheck($response['status'] === 403, 'Untrusted peer forwarded HTTPS header was trusted.');
} finally {
    $untrustedProxy->stop();
}

$local = new IsolatedHttpServer('local', false);
try {
    $status = $local->request('GET', '/api/demo/session');
    accessHttpCheck($status['status'] === 200 && $status['json'] === ['access_required' => false, 'authenticated' => true, 'csrf_token' => null], 'Unconfigured local development workflow changed.');
} finally {
    $local->stop();
}

$bruteForce = new IsolatedHttpServer('local', false, $hash);
try {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $result = $bruteForce->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}');
        accessHttpCheck($result['status'] === 401, 'Unexpected early demo login throttle response.');
    }
    $limited = $bruteForce->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}');
    accessHttpCheck($limited['status'] === 429, 'Demo login brute-force limit was not enforced.');
} finally {
    $bruteForce->stop();
}

$unconfigured = new IsolatedHttpServer('production');
try {
    $status = $unconfigured->request('GET', '/api/demo/session');
    $login = $unconfigured->request('POST', '/api/demo/login', '{"access_code":"fixture-only-code"}');
    accessHttpCheck($status['json']['access_required'] === true && !$status['json']['authenticated'], 'Production without a verifier failed open.');
    accessHttpCheck($login['status'] === 503, 'Production without a verifier accepted login attempts.');
} finally {
    $unconfigured->stop();
}

echo "Demo access HTTP tests passed (gate, session rotation/cookies, CSRF, throttling, trusted proxy, local workflow).\n";
