<?php
declare(strict_types=1);

use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NullNeatlogsClient;
use JobReady\Security\DemoAccess;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

require dirname(__DIR__) . '/config/autoload.php';

function answerLimitCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectAnswerRateLimit(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        answerLimitCheck($exception->status === 429, $message . ' (wrong HTTP status)');
        return;
    }
    throw new RuntimeException($message);
}

function rateLimitDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Database::initialize($pdo);
    return $pdo;
}

function answerSessionId(int $number): string
{
    return sprintf('123e4567-e89b-42d3-a456-%012x', $number);
}

$model = new class implements InterviewAIClientInterface {
    public function createPlan(array $candidate): array
    {
        return ['focus_areas' => ['Testing', 'Reliability'], 'rationale' => 'Fictional test plan.', 'suggested_depth' => 'beginner'];
    }

    public function decide(array $context): array
    {
        if ($context['answer_count'] >= DecisionValidator::MAX_ANSWERS) {
            return ['action' => 'finish_interview', 'rationale' => 'Maximum fictional practice length reached.',
                'arguments' => ['summary' => 'Fictional readiness summary.', 'readiness' => 'developing']];
        }
        return ['action' => 'ask_question', 'rationale' => 'Continue fictional practice.',
            'arguments' => ['question' => 'Describe fictional example ' . ($context['answer_count'] + 1) . '.', 'focus' => 'Testing']];
    }

    public function verifyClaim(array $context, string $claim): array
    {
        return ['evidence' => 'unclear', 'explanation' => 'Fictional evidence is incomplete.', 'follow_up_focus' => 'Testing'];
    }
};

// One full, maximum-length fictional interview is allowed from one client within an hour.
$workflowPdo = rateLimitDatabase();
$workflowAccess = new DemoAccess($workflowPdo, 'production', password_hash('fictional-rate-fixture-key', PASSWORD_DEFAULT), '');
$workflow = new InterviewService($workflowPdo, new InterviewStore($workflowPdo), $model, new DecisionValidator(), new NullNeatlogsClient());
$candidate = ['target_role' => 'Fictional QA Engineer', 'cv_text' => 'Fictional tester with PHP test experience.',
    'job_description' => 'Fictional role covering test cases and bug reports.'];
$started = $workflow->start($candidate);
for ($answer = 1; $answer <= DecisionValidator::MAX_ANSWERS; $answer++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $workflowAccess->enforceEndpointLimit('/api/interviews/' . $started['id'] . '/answers', 'POST', $started['id']);
    $result = $workflow->submitAnswer($started['id'], 'Fictional answer number ' . $answer . '.');
}
answerLimitCheck($result['status'] === 'finished' && $result['answer_count'] === DecisionValidator::MAX_ANSWERS,
    'The full legitimate 20-answer interview did not finish.');
answerLimitCheck(count($result['evaluations']) === 1 && $result['evaluations'][0]['evaluation_type'] === 'readiness_report',
    'The maximum-length interview did not persist its readiness report.');

// Distinct client identities isolate and verify the per-interview cap.
$sessionPdo = rateLimitDatabase();
$sessionAccess = new DemoAccess($sessionPdo, 'production', password_hash('fictional-session-limit-key', PASSWORD_DEFAULT), '');
$sessionId = answerSessionId(1);
for ($i = 1; $i <= DecisionValidator::MAX_ANSWERS; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
    $sessionAccess->enforceEndpointLimit('/api/interviews/' . $sessionId . '/answers', 'POST', $sessionId);
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
expectAnswerRateLimit(
    static fn () => $sessionAccess->enforceEndpointLimit('/api/interviews/' . $sessionId . '/answers', 'POST', $sessionId),
    'The 21st answer to one interview must be limited.',
);

// A single client retains its independent 20-answer-per-hour cap across interviews.
$clientPdo = rateLimitDatabase();
$clientAccess = new DemoAccess($clientPdo, 'production', password_hash('fictional-client-limit-key', PASSWORD_DEFAULT), '');
$_SERVER['REMOTE_ADDR'] = '198.51.100.50';
for ($i = 1; $i <= 20; $i++) {
    $id = answerSessionId($i + 10);
    $clientAccess->enforceEndpointLimit('/api/interviews/' . $id . '/answers', 'POST', $id);
}
$nextId = answerSessionId(21 + 10);
expectAnswerRateLimit(
    static fn () => $clientAccess->enforceEndpointLimit('/api/interviews/' . $nextId . '/answers', 'POST', $nextId),
    'The existing per-client 20-answer hourly cap changed.',
);

// Global protection remains 40 answer turns per day across clients/interviews.
$globalPdo = rateLimitDatabase();
$globalAccess = new DemoAccess($globalPdo, 'production', password_hash('fictional-global-limit-key', PASSWORD_DEFAULT), '');
for ($i = 1; $i <= 40; $i++) {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;
    $id = answerSessionId($i + 100);
    $globalAccess->enforceEndpointLimit('/api/interviews/' . $id . '/answers', 'POST', $id);
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.41';
$lastId = answerSessionId(141);
expectAnswerRateLimit(
    static fn () => $globalAccess->enforceEndpointLimit('/api/interviews/' . $lastId . '/answers', 'POST', $lastId),
    'The existing global 40-answer daily cap changed.',
);

echo "Interview answer rate-limit tests passed (20-answer completion, per-interview/per-client caps, unchanged global cap; in-memory fixtures).\n";
