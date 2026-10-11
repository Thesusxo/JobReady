<?php
declare(strict_types=1);

require __DIR__ . '/Support/IsolatedHttpServer.php';

function deploymentCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$judgeCode = 'fixture-only-code';
$verifier = password_hash($judgeCode, PASSWORD_DEFAULT);
$server = new IsolatedHttpServer('production', false, $verifier, '127.0.0.1,::1');
try {
    $root = $server->fixtureRoot();
    $bootstrap = $root . '/config/bootstrap.php';
    deploymentCheck(rename($bootstrap, $root . '/config/bootstrap.fixture-base.php'), 'Cannot prepare isolated fake-provider fixture.');
    $fakeBootstrap = <<<'PHP'
<?php
declare(strict_types=1);
$services = require __DIR__ . '/bootstrap.fixture-base.php';
$counter = dirname(__DIR__) . '/fake-ai-call-count';
$fakeAI = new class($counter) implements JobReady\AI\InterviewAIClientInterface {
    public function __construct(private readonly string $counterFile) {}
    private function recordCall(): void {
        $count = is_file($this->counterFile) ? (int) file_get_contents($this->counterFile) : 0;
        file_put_contents($this->counterFile, (string) ($count + 1), LOCK_EX);
    }
    public function createPlan(array $candidate): array {
        $this->recordCall();
        return ['focus_areas' => ['PHP debugging', 'Application security'], 'rationale' => 'Fictional offline fixture plan.', 'suggested_depth' => 'beginner'];
    }
    public function decide(array $context): array {
        $this->recordCall();
        if ((int) $context['answer_count'] === 0) {
            return ['action' => 'ask_question', 'rationale' => 'Fictional opening action.', 'arguments' => ['question' => 'Describe a fictional PHP project.', 'focus' => 'PHP debugging']];
        }
        return ['action' => 'probe_answer', 'rationale' => 'Fictional adaptive probe.', 'arguments' => ['question' => 'What debugging steps did you try?', 'focus' => 'PHP debugging', 'probe_reason' => 'Request concrete details from the fictional answer.']];
    }
    public function verifyClaim(array $context, string $claim): array {
        $this->recordCall();
        return ['evidence' => 'unclear', 'explanation' => 'Fictional fixture evaluation.', 'follow_up_focus' => 'PHP debugging'];
    }
};
$services['interview_service'] = new JobReady\Interview\InterviewService(
    $services['pdo'], $services['interview_store'], $fakeAI,
    new JobReady\Interview\DecisionValidator(), new JobReady\Observability\NullNeatlogsClient(),
);
return $services;
PHP;
    deploymentCheck(file_put_contents($bootstrap, $fakeBootstrap) !== false, 'Cannot install isolated fake provider.');

    $page = $server->requestRaw('GET', '/app.html');
    deploymentCheck($page['status'] === 200 && str_contains($page['body'], 'Judge access code'), 'Judge login page did not load.');
    deploymentCheck(!str_contains($page['body'], $judgeCode), 'Judge code was embedded in the public HTML.');

    foreach ([
        ['POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST],
        ['POST', '/api/interviews/123e4567-e89b-42d3-a456-426614174000/answers', '{"answer":"fictional fixture answer"}'],
        ['GET', '/api/interviews/123e4567-e89b-42d3-a456-426614174000', null],
        ['POST', '/api/cv/extract', '{}'],
    ] as [$method, $path, $body]) {
        $response = $server->request($method, $path, $body);
        deploymentCheck($response['status'] === 401, 'Sensitive API route accepted an unauthenticated request.');
        deploymentCheck(!isset($response['json']['diagnostics'], $response['json']['interview']), 'Unauthorized response disclosed session data.');
    }
    deploymentCheck(!is_file($root . '/fake-ai-call-count'), 'Unauthenticated request reached the fake AI provider.');
    deploymentCheck($server->fixtureSessionFileCount() === 0, 'Unauthenticated requests created PHP session files.');

    $sessionStatus = $server->request('GET', '/api/demo/session');
    deploymentCheck($sessionStatus['json'] === ['access_required' => true, 'authenticated' => false, 'csrf_token' => null], 'Production session status was not safely gated.');
    $badLogin = $server->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}', ['CF-Visitor' => '{"scheme":"https"}']);
    deploymentCheck($badLogin['status'] === 401 && !str_contains(json_encode($badLogin['json']), $judgeCode), 'Invalid code was not safely rejected.');
    deploymentCheck($server->fixtureSessionFileCount() === 0, 'Invalid login created a server session.');

    $login = $server->request('POST', '/api/demo/login', json_encode(['access_code' => $judgeCode], JSON_THROW_ON_ERROR), [
        'CF-Visitor' => '{"scheme":"https"}', 'Cookie' => 'JOBREADY_DEMO=attacker-controlled-session-id',
    ]);
    deploymentCheck($login['status'] === 200 && $login['json']['authenticated'] === true, 'Valid judge login failed.');
    $csrf = $login['json']['csrf_token'] ?? '';
    deploymentCheck(preg_match('/^[a-f0-9]{64}$/', $csrf) === 1, 'Session CSRF token missing or malformed.');
    $cookie = $server->lastSetCookie ?? '';
    deploymentCheck(stripos($cookie, 'httponly') !== false && stripos($cookie, 'secure') !== false
        && stripos($cookie, 'samesite=strict') !== false, 'Production session cookie flags are incomplete.');
    preg_match('/^JOBREADY_DEMO=([^;]+)/', $cookie, $cookieMatch);
    deploymentCheck(isset($cookieMatch[1]) && $cookieMatch[1] !== 'attacker-controlled-session-id', 'Session ID fixation was not prevented.');
    deploymentCheck(!str_contains(json_encode($login['json']), $judgeCode), 'Access code appeared in the login response.');

    $missingCsrf = $server->request('POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST);
    $wrongCsrf = $server->request('POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST, ['X-CSRF-Token' => str_repeat('0', 64)]);
    deploymentCheck($missingCsrf['status'] === 403 && $wrongCsrf['status'] === 403, 'Missing or invalid CSRF token was accepted.');
    deploymentCheck(!is_file($root . '/fake-ai-call-count'), 'CSRF-rejected request reached the fake AI provider.');

    $start = $server->request('POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST, ['X-CSRF-Token' => $csrf]);
    deploymentCheck($start['status'] === 201 && $start['json']['status'] === 'active', 'Authenticated start did not pass the access layer.');
    deploymentCheck($start['json']['answer_count'] === 0 && is_string($start['json']['current_question']), 'Fake start response was not valid session progress.');
    deploymentCheck(!array_key_exists('cv_text', $start['json']) && !array_key_exists('job_description', $start['json']), 'Raw candidate inputs were exposed by response.');
    deploymentCheck((int) file_get_contents($root . '/fake-ai-call-count') === 2, 'Authenticated fake provider did not perform exactly the plan and opening decision.');
    $csrfStatus = $server->request('GET', '/api/demo/session');
    deploymentCheck($csrfStatus['json']['authenticated'] === true && $csrfStatus['json']['csrf_token'] === $csrf, 'Browser-style session/CSRF recovery failed.');

    $interviewId = $start['json']['id'];
    $answer = $server->request('POST', '/api/interviews/' . $interviewId . '/answers', '{"answer":"fictional fixture answer"}', ['X-CSRF-Token' => $csrf]);
    deploymentCheck($answer['status'] === 200 && $answer['json']['answer_count'] === 1, 'Authenticated answer was not saved.');
    $lastDecision = end($answer['json']['decisions']);
    deploymentCheck(($lastDecision['action'] ?? null) === 'probe_answer', 'Fake adaptive next decision was not validated/returned.');
    deploymentCheck((int) file_get_contents($root . '/fake-ai-call-count') === 3, 'Answer turn exceeded its expected single fake decision call.');

    $progress = $server->request('GET', '/api/interviews/' . $interviewId);
    deploymentCheck($progress['status'] === 200 && $progress['json']['answer_count'] === 1, 'Authenticated browser-style progress retrieval failed.');
    $logout = $server->request('POST', '/api/demo/logout', '{}', ['X-CSRF-Token' => $csrf]);
    deploymentCheck($logout['status'] === 200, 'Authenticated CSRF-protected logout failed.');
    $afterLogout = $server->request('GET', '/api/interviews/' . $interviewId);
    deploymentCheck($afterLogout['status'] === 401, 'Logout did not revoke access.');

    foreach (['/.env', '/config/bootstrap.php', '/src/AI/GeminiClient.php', '/vendor/autoload.php', '/data/test.sqlite', '/.git/config'] as $privatePath) {
        $response = $server->requestRaw('GET', $privatePath);
        deploymentCheck($response['status'] !== 200, 'Private or non-public path was served.');
    }
    $devStatus = $server->request('GET', '/api/dev/status');
    deploymentCheck($devStatus['status'] === 404, 'Production diagnostics endpoint was exposed.');
    echo "Demo deployment security integration passed (production fixture, mock AI, no external providers).\n";
} finally {
    $server->stop();
}

// With a peer that is not in the trusted-proxy allowlist, client-supplied CF headers must not
// change the rate-limit identity. The fixture simulates distinct spoofed addresses from one peer.
$untrustedProxy = new IsolatedHttpServer('local', false, $verifier, '192.0.2.44');
try {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $response = $untrustedProxy->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}',
            ['CF-Connecting-IP' => '198.51.100.' . ($attempt + 1)]);
        deploymentCheck($response['status'] === 401, 'Spoof test login returned unexpected status.');
    }
    $limited = $untrustedProxy->request('POST', '/api/demo/login', '{"access_code":"wrong-fixture-code"}', ['CF-Connecting-IP' => '198.51.100.99']);
    deploymentCheck($limited['status'] === 429, 'Untrusted forwarded IP values bypassed per-client rate limiting.');
    echo "Untrusted forwarding-header isolation passed.\n";
} finally {
    $untrustedProxy->stop();
}
