<?php
declare(strict_types=1);

use JobReady\AI\AIUnavailableException;
use JobReady\AI\GeminiClient;
use JobReady\Http\ErrorResponse;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NullNeatlogsClient;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'JobReady\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require __DIR__ . '/Support/IsolatedHttpServer.php';

function diagnosticCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function diagnosticEnvelope(array $object): string
{
    return json_encode(['candidates' => [[
        'finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($object, JSON_THROW_ON_ERROR)]]],
    ]]], JSON_THROW_ON_ERROR);
}

function diagnosticError(string $code, ?string $reason = null): string
{
    return json_encode(['error' => [
        'status' => $code,
        'message' => 'SYNTHETIC_PRIVATE_MARKER never expose provider messages or candidate data',
        'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => $reason, 'metadata' => ['private' => 'SYNTHETIC_PRIVATE_MARKER']]],
    ]], JSON_THROW_ON_ERROR);
}

$cases = [
    [400, diagnosticError('INVALID_ARGUMENT'), 'invalid_request', null],
    [400, diagnosticError('INVALID_ARGUMENT', 'API_KEY_INVALID'), 'authentication', 'API_KEY_INVALID'],
    [401, diagnosticError('UNAUTHENTICATED'), 'authentication', null],
    [403, diagnosticError('PERMISSION_DENIED', 'API_KEY_SERVICE_BLOCKED'), 'authorization', 'API_KEY_SERVICE_BLOCKED'],
    [404, diagnosticError('NOT_FOUND'), 'model_unavailable', null],
    [429, diagnosticError('RESOURCE_EXHAUSTED'), 'quota_or_rate_limit', null],
    [500, diagnosticError('INTERNAL'), 'provider_unavailable', null],
    [503, diagnosticError('UNAVAILABLE'), 'provider_unavailable', null],
    [403, 'SYNTHETIC_PRIVATE_MARKER non-JSON proxy response', 'authorization', null],
    [400, diagnosticError('SYNTHETIC_PRIVATE_MARKER', 'SYNTHETIC_PRIVATE_MARKER'), 'invalid_request', null],
    [200, 'not JSON SYNTHETIC_PRIVATE_MARKER', 'invalid_output', 'MALFORMED_JSON'],
    [200, '{}', 'invalid_output', 'INCOMPLETE_OUTPUT'],
    [200, '{"promptFeedback":{"blockReason":"SYNTHETIC_PRIVATE_MARKER"}}', 'invalid_output', 'BLOCKED_OUTPUT'],
    [200, '{"candidates":[{"finishReason":"MAX_TOKENS"}]}', 'invalid_output', 'INCOMPLETE_OUTPUT'],
    [200, '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"[]"}]}}]}', 'invalid_output', 'INVALID_JSON_OBJECT'],
];
foreach ($cases as [$status, $body, $category, $reason]) {
    $rejected = false;
    try {
        GeminiClient::parseResponse($status, $body);
    } catch (AIUnavailableException $exception) {
        $rejected = true;
        diagnosticCheck($exception->diagnostics['category'] === $category, 'Incorrect failure classification.');
        diagnosticCheck($exception->diagnostics['upstream_http_status'] === $status, 'Upstream status was lost.');
        diagnosticCheck(($exception->diagnostics['reason'] ?? null) === $reason, 'Incorrect safe reason.');
        diagnosticCheck(!str_contains(json_encode($exception->diagnostics) . $exception->getMessage(), 'SYNTHETIC_PRIVATE_MARKER'), 'Private provider data escaped diagnostics.');
    }
    diagnosticCheck($rejected, 'Failure was not rejected.');
}

$unsafe = new AIUnavailableException('Safe message.', [
    'provider' => 'gemini', 'category' => 'authentication', 'stage' => 'plan',
    'upstream_http_status' => 400, 'curl_error_code' => 28,
    'provider_code' => 'SYNTHETIC_PRIVATE_MARKER', 'reason' => 'SYNTHETIC_PRIVATE_MARKER',
    'headers' => 'SYNTHETIC_PRIVATE_MARKER', 'api_key' => 'SYNTHETIC_PRIVATE_MARKER',
    'body' => 'SYNTHETIC_PRIVATE_MARKER', 'candidate' => 'SYNTHETIC_PRIVATE_MARKER',
]);
diagnosticCheck(array_keys($unsafe->diagnostics) === ['provider', 'category', 'stage', 'upstream_http_status', 'curl_error_code'], 'Diagnostic allowlist admitted extra data.');
diagnosticCheck(AIUnavailableException::sanitizeDiagnostics([
    'provider' => 'SYNTHETIC_PRIVATE_MARKER', 'category' => 'SYNTHETIC_PRIVATE_MARKER',
    'stage' => 'SYNTHETIC_PRIVATE_MARKER', 'upstream_http_status' => '400', 'curl_error_code' => -1,
]) === [], 'Unsafe field types were not rejected.');

$transportClient = new GeminiClient('offline-placeholder-only', transport: static fn (): array => ['status' => 0, 'body' => '', 'curl_error' => 28]);
foreach (['plan', 'decision', 'claim_verification'] as $stage) {
    try {
        match ($stage) {
            'plan' => $transportClient->createPlan([]),
            'decision' => $transportClient->decide([]),
            'claim_verification' => $transportClient->verifyClaim([], 'fictional claim'),
        };
        throw new RuntimeException('Transport failure was accepted.');
    } catch (AIUnavailableException $exception) {
        diagnosticCheck($exception->diagnostics['category'] === 'transport', 'Transport category was lost.');
        diagnosticCheck($exception->diagnostics['stage'] === $stage, 'Stage was lost in the client.');
        diagnosticCheck($exception->diagnostics['curl_error_code'] === 28, 'Numeric cURL error was lost.');
        diagnosticCheck(!isset($exception->diagnostics['upstream_http_status']), 'Absent HTTP status was invented.');
    }
}
try {
    (new GeminiClient('offline-placeholder-only', transport: static function (): array {
        throw new RuntimeException('SYNTHETIC_PRIVATE_MARKER');
    }))->createPlan([]);
    throw new RuntimeException('Thrown transport error was accepted.');
} catch (AIUnavailableException $exception) {
    diagnosticCheck($exception->diagnostics['category'] === 'transport', 'Thrown transport error misclassified.');
    diagnosticCheck($exception->getPrevious() === null, 'Sensitive nested transport exception was retained.');
}

$plan = ['focus_areas' => ['APIs', 'Debugging'], 'rationale' => 'Relevant to the role.', 'suggested_depth' => 'beginner'];
$ask = ['action' => 'ask_question', 'rationale' => 'Explore experience.', 'arguments' => ['question' => 'Describe the PHP inventory project.', 'focus' => 'APIs']];
$verify = ['action' => 'verify_claim', 'rationale' => 'Review evidence.', 'arguments' => ['claim' => 'reduced latency']];
$candidate = ['cv_text' => 'Fictional developer built a PHP inventory application.',
    'job_description' => 'Junior PHP role working on APIs, debugging, and security.', 'target_role' => 'Junior PHP Developer'];

foreach (['plan', 'decision', 'claim_verification'] as $stage) {
    $responses = match ($stage) {
        'plan' => [],
        'decision' => [$plan],
        'claim_verification' => [$plan, $ask, $verify],
    };
    $client = new GeminiClient('offline-placeholder-only', transport: static function () use (&$responses): array {
        if ($responses !== []) {
            return ['status' => 200, 'body' => diagnosticEnvelope(array_shift($responses))];
        }
        return ['status' => 429, 'body' => diagnosticError('RESOURCE_EXHAUSTED')];
    });
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    Database::initialize($pdo);
    $service = new InterviewService($pdo, new InterviewStore($pdo), $client, new DecisionValidator(), new NullNeatlogsClient());
    $started = null;
    try {
        $started = $service->start($candidate);
        $service->submitAnswer($started['id'], 'I reduced latency through caching.');
        throw new RuntimeException('Provider failure was accepted.');
    } catch (HttpException $exception) {
        diagnosticCheck($exception->status === 503, 'HTTP failure status changed.');
        diagnosticCheck($exception->diagnostics['stage'] === $stage, 'Stage was lost in HTTP conversion.');
        diagnosticCheck($exception->diagnostics['category'] === 'quota_or_rate_limit', 'Category was lost in HTTP conversion.');
        diagnosticCheck($exception->diagnostics['upstream_http_status'] === 429, 'Upstream status was lost in HTTP conversion.');
        $generic = ['error' => 'The interview service is temporarily unavailable.'];
        foreach ([['production', true], ['production', false], ['staging', true], ['local', false], ['development', false]] as [$env, $debug]) {
            diagnosticCheck(ErrorResponse::fromException($exception, $env, $debug) === $generic, 'Diagnostics leaked outside the development gate.');
        }
        foreach (['local', 'development'] as $env) {
            $payload = ErrorResponse::fromException($exception, $env, true);
            diagnosticCheck($payload['diagnostics'] === $exception->diagnostics, 'Development metadata was lost.');
            diagnosticCheck(!str_contains(json_encode($payload), 'SYNTHETIC_PRIVATE_MARKER'), 'Response exposed private data.');
        }
    }
    if ($stage === 'claim_verification') {
        $progress = $service->getProgress($started['id']);
        diagnosticCheck($progress['answer_count'] === 0 && $progress['current_question'] === $ask['arguments']['question'], 'Failed answer/verification did not roll back.');
        diagnosticCheck(count($progress['decisions']) === 1 && $progress['evaluations'] === [], 'Failed tool result was persisted.');
    } else {
        diagnosticCheck((int) $pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Failed creation was persisted.');
    }
}

// Exercise the actual HTTP gate using blank keys: client fails before any external request.
foreach ([['local', true, true], ['development', true, true], ['local', false, false], ['development', false, false], ['production', true, false], ['production', false, false], ['staging', true, false]] as [$env, $debug, $expose]) {
    $gated = in_array($env, ['production', 'staging'], true);
    $server = new IsolatedHttpServer($env, $debug, $gated ? password_hash('fixture-only-code', PASSWORD_DEFAULT) : '');
    try {
        $headers = [];
        if ($gated) {
            $login = $server->request('POST', '/api/demo/login', '{"access_code":"fixture-only-code"}', ['CF-Visitor' => '{"scheme":"https"}']);
            diagnosticCheck($login['status'] === 200, 'Production fixture judge login failed.');
            $headers['X-CSRF-Token'] = $login['json']['csrf_token'];
        }
        $response = $server->request('POST', '/api/interviews', IsolatedHttpServer::DIAGNOSTIC_REQUEST, $headers);
        diagnosticCheck($response['status'] === 503, 'Keyless HTTP fixture did not return 503.');
        diagnosticCheck($response['json']['error'] === 'The interview service is temporarily unavailable.', 'Public error message changed.');
        diagnosticCheck(isset($response['json']['diagnostics']) === $expose, 'Actual HTTP diagnostic gate failed.');
        if ($expose) {
            diagnosticCheck($response['json']['diagnostics'] === ['provider' => 'gemini', 'category' => 'configuration', 'stage' => 'plan', 'reason' => 'MISSING_KEY'], 'Keyless HTTP diagnostics were incorrect.');
        } else {
            diagnosticCheck(array_keys($response['json']) === ['error'], 'Generic HTTP response gained extra fields.');
        }
    } finally {
        $server->stop();
    }
}
echo "Gemini classification, stage preservation, safe HTTP exposure, and rollback diagnostics tests passed (offline).\n";
