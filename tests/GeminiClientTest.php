<?php
declare(strict_types=1);

use JobReady\AI\AIUnavailableException;
use JobReady\AI\GeminiClient;
use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NullNeatlogsClient;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

spl_autoload_register(static function (string $class): void {
    $prefix = 'JobReady\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function geminiCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function geminiEnvelope(array $object): string
{
    return json_encode(['candidates' => [[
        'finishReason' => 'STOP',
        'content' => ['parts' => [['text' => json_encode($object, JSON_THROW_ON_ERROR)]]],
    ]]], JSON_THROW_ON_ERROR);
}

$plan = ['focus_areas' => ['API design', 'Reliability'], 'rationale' => 'Relevant to the supplied role.', 'suggested_depth' => 'intermediate'];
geminiCheck(GeminiClient::parseResponse(200, geminiEnvelope($plan)) === $plan, 'JSON object parsing failed.');
$split = json_encode(['candidates' => [[
    'finishReason' => 'STOP', 'content' => ['parts' => [
        ['thought' => true, 'text' => 'Internal thought, not an answer.'],
        ['text' => '{"evidence":'], ['text' => '"unclear"}'],
    ]],
]]], JSON_THROW_ON_ERROR);
geminiCheck(GeminiClient::parseResponse(200, $split) === ['evidence' => 'unclear'], 'Split text parts/thought filtering failed.');

$invalidResponses = [
    [429, '{"error":{"message":"private provider detail","status":"RESOURCE_EXHAUSTED"}}'],
    [403, '{"error":{"message":"private provider detail"}}'],
    [500, 'private provider detail'],
    [200, 'not JSON'],
    [200, '{}'],
    [200, '{"error":{"message":"private provider detail"}}'],
    [200, '{"promptFeedback":{"blockReason":"SAFETY"}}'],
    [200, '{"candidates":[{"finishReason":"MAX_TOKENS","content":{"parts":[{"text":"{}"}]}}]}'],
    [200, '{"candidates":[{"finishReason":"SAFETY"}]}'],
    [200, '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"functionCall":{"name":"shell"}}]}}]}'],
    [200, '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"[]"}]}}]}'],
    [200, '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"{broken"}]}}]}'],
    [200, str_repeat('x', 65537)],
];
foreach ($invalidResponses as [$status, $body]) {
    $rejected = false;
    try {
        GeminiClient::parseResponse($status, $body);
    } catch (AIUnavailableException $exception) {
        $rejected = true;
        geminiCheck(!str_contains($exception->getMessage(), 'private provider detail'), 'Provider details escaped into exception text.');
    }
    geminiCheck($rejected, 'Invalid provider response was accepted.');
}

$mode = 'normal';
$calls = 0;
$claimCalls = 0;
$transport = static function (string $url, array $payload) use (&$mode, &$calls, &$claimCalls, $plan): array {
    $calls++;
    geminiCheck($url === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent', 'Incorrect REST model endpoint.');
    geminiCheck(($payload['generationConfig']['responseMimeType'] ?? '') === 'application/json', 'JSON mode is missing.');
    geminiCheck(($payload['generationConfig']['maxOutputTokens'] ?? 0) <= 800, 'Output is not bounded.');
    geminiCheck(isset($payload['systemInstruction']['parts'][0]['text']), 'System instructions are missing.');
    geminiCheck(($payload['contents'][0]['role'] ?? '') === 'user', 'Candidate input is not separate from instructions.');
    $context = json_decode($payload['contents'][0]['parts'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'quota') {
        return ['status' => 429, 'body' => '{"error":{"message":"private provider detail"}}'];
    }
    if ($mode === 'timeout') {
        throw new RuntimeException('private provider detail');
    }
    if ($mode === 'invalid_action') {
        return ['status' => 200, 'body' => geminiEnvelope(['action' => 'execute_shell', 'rationale' => 'Invalid action.', 'arguments' => []])];
    }
    if (isset($context['claim'])) {
        $claimCalls++;
        return ['status' => 200, 'body' => geminiEnvelope(['evidence' => 'unclear', 'explanation' => 'Measurement evidence is missing.', 'follow_up_focus' => 'Measurement'])];
    }
    if (!isset($context['allowed_actions'])) {
        return ['status' => 200, 'body' => geminiEnvelope($plan)];
    }
    if ($context['answer_count'] === 0) {
        $decision = ['action' => 'ask_question', 'rationale' => 'Explore API experience.', 'arguments' => ['question' => 'Describe your API performance work.', 'focus' => 'API design']];
    } elseif ($context['answer_count'] === 1 && $context['recent_evaluations'] === []) {
        geminiCheck($context['recent_answers'][0]['answer_text'] === 'I reduced latency by 40%.', 'Earlier answers are missing from Gemini context.');
        $decision = ['action' => 'verify_claim', 'rationale' => 'Review the impact claim.', 'arguments' => ['claim' => 'reduced latency by 40%']];
    } elseif ($context['answer_count'] === 1) {
        $decision = ['action' => 'probe_answer', 'rationale' => 'Measurement was unclear.', 'arguments' => ['question' => 'How did you measure that latency reduction?', 'focus' => 'Measurement', 'probe_reason' => 'Get evidence for the claim.']];
    } else {
        $decision = ['action' => 'finish_interview', 'rationale' => 'Enough evidence gathered.', 'arguments' => ['summary' => 'Candidate supplied measurement evidence.', 'readiness' => 'developing']];
    }
    return ['status' => 200, 'body' => geminiEnvelope($decision)];
};
$client = new GeminiClient('offline-placeholder-only', GeminiClient::DEFAULT_MODEL, $transport);
geminiCheck($client instanceof InterviewAIClientInterface, 'Gemini does not implement the provider-independent contract.');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
Database::initialize($pdo);
$service = new InterviewService($pdo, new InterviewStore($pdo), $client, new DecisionValidator(), new NullNeatlogsClient());
$started = $service->start([
    'cv_text' => 'Built a PHP API and improved application performance.',
    'job_description' => 'Backend engineering role covering API design and reliability.',
    'target_role' => 'Backend Engineer',
]);
$afterAnswer = $service->submitAnswer($started['id'], 'I reduced latency by 40%.');
geminiCheck($afterAnswer['current_question'] === 'How did you measure that latency reduction?', 'Gemini could not execute the adaptive claim/probe loop.');
geminiCheck($claimCalls === 1, 'Gemini verification was not executed.');

foreach (['quota' => 503, 'timeout' => 503, 'invalid_action' => 502] as $failureMode => $expectedStatus) {
    $mode = $failureMode;
    $rejected = false;
    try {
        $service->submitAnswer($started['id'], 'I used before and after p95 measurements.');
    } catch (HttpException $exception) {
        $rejected = true;
        geminiCheck($exception->status === $expectedStatus, 'Incorrect recovery status.');
        geminiCheck(!str_contains($exception->getMessage(), 'private provider detail'), 'Private details leaked to API error.');
    }
    geminiCheck($rejected, 'Invalid turn was accepted.');
    $progress = $service->getProgress($started['id']);
    geminiCheck($progress['answer_count'] === 1 && $progress['current_question'] === $afterAnswer['current_question'], 'Failed turn did not roll back for retry.');
}
$mode = 'normal';
$finished = $service->submitAnswer($started['id'], 'I used before and after p95 measurements.');
geminiCheck($finished['status'] === 'finished', 'Gemini workflow failed to finish.');
geminiCheck(array_column($finished['decisions'], 'action') === ['ask_question', 'verify_claim', 'probe_answer', 'finish_interview'], 'Agent decisions changed during provider integration.');
geminiCheck(count($finished['evaluations']) === 2, 'Gemini evaluations were not persisted.');

$before = $calls;
try {
    (new GeminiClient(null, GeminiClient::DEFAULT_MODEL, $transport))->createPlan([]);
    throw new RuntimeException('Missing key was accepted.');
} catch (AIUnavailableException) {
    geminiCheck($calls === $before, 'Missing key reached transport.');
}
echo "Gemini parsing, request shape, agent compatibility, and recovery tests passed (offline).\n";
