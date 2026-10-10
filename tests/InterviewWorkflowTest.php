<?php
declare(strict_types=1);

use JobReady\AI\OpenAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NeatlogsClientInterface;
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

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
Database::initialize($pdo);

$model = new class implements OpenAIClientInterface {
    public int $decisionCalls = 0;

    public function createPlan(array $candidate): array
    {
        check($candidate['target_role'] === 'Backend Engineer', 'Target role reaches the planner.');
        return ['focus_areas' => ['API design', 'Reliability'], 'rationale' => 'Emphasize role-specific evidence.', 'suggested_depth' => 'intermediate'];
    }

    public function decide(array $context): array
    {
        $this->decisionCalls++;
        if ($this->decisionCalls === 1) {
            check($context['allowed_actions'] === ['ask_question'], 'Initial decision is bounded to an opening question.');
            return ['action' => 'ask_question', 'rationale' => 'Begin with a relevant experience.', 'arguments' => ['question' => 'Describe the API project listed on your CV.', 'focus' => 'API design']];
        }
        if ($this->decisionCalls === 2) {
            check(($context['recent_answers'][0]['answer_text'] ?? '') === 'I reduced latency by 40% using caching.', 'Prior response reaches the decision step.');
            return ['action' => 'verify_claim', 'rationale' => 'Check the concrete impact claim.', 'arguments' => ['claim' => 'reduced latency by 40%']];
        }
        if ($this->decisionCalls === 3) {
            check(($context['recent_evaluations'][0]['data']['evidence'] ?? '') === 'supported', 'Verification result informs the next decision.');
            return ['action' => 'probe_answer', 'rationale' => 'Probe the candidate\'s measurement method.', 'arguments' => ['question' => 'How did you measure the latency change?', 'focus' => 'Performance measurement', 'probe_reason' => 'Explore evidence behind the quantified result.']];
        }
        if ($this->decisionCalls === 4) {
            check(count($context['recent_answers']) === 2, 'The following answer is included in subsequent context.');
            check(($context['allowed_actions'][0] ?? '') === 'ask_question', 'Decision loop exposes allowed actions explicitly.');
            return ['action' => 'finish_interview', 'rationale' => 'Enough role-relevant evidence has been gathered.', 'arguments' => ['summary' => 'Strong API experience with concrete performance evidence.', 'readiness' => 'ready']];
        }
        throw new RuntimeException('Unexpected extra model decision.');
    }

    public function verifyClaim(array $context, string $claim): array
    {
        check($claim === 'reduced latency by 40%', 'Claim tool receives the validated excerpt.');
        return ['evidence' => 'supported', 'explanation' => 'The answer states a measurable result.', 'follow_up_focus' => 'Measurement method'];
    }
};
$tracer = new class implements NeatlogsClientInterface {
    public array $events = [];
    public bool $throwOnRecord = true;

    public function recordEvent(string $name, array $attributes = []): bool
    {
        $this->events[] = [$name, $attributes];
        if ($this->throwOnRecord) {
            throw new RuntimeException('Simulated observability failure.');
        }
        return true;
    }
};

$service = new InterviewService($pdo, new InterviewStore($pdo), $model, new DecisionValidator(), $tracer);
$started = $service->start([
    'cv_text' => 'Built a PHP API and improved performance.',
    'job_description' => 'Seeking a backend engineer with API and reliability experience.',
    'target_role' => 'Backend Engineer',
]);
check($started['status'] === 'active' && str_contains($started['current_question'], 'API project'), 'Start returns an individualized opening question.');
check(!array_key_exists('cv_text', $started) && !array_key_exists('job_description', $started), 'Progress response excludes raw profile inputs.');

$afterFirst = $service->submitAnswer($started['id'], 'I reduced latency by 40% using caching.');
check($afterFirst['current_question'] === 'How did you measure the latency change?', 'Next question adapts as a probe after claim verification.');

$finished = $service->submitAnswer($started['id'], 'I compared p95 latency before and after rollout.');
check($finished['status'] === 'finished' && $finished['answer_count'] === 2, 'Agent can finish after gathering evidence.');
$actions = array_column($finished['decisions'], 'action');
check($actions === ['ask_question', 'verify_claim', 'probe_answer', 'finish_interview'], 'All agent decisions are persisted in order.');
check(count($finished['evaluations']) === 2, 'Claim verification and final readiness evaluation are persisted.');
check(count($tracer->events) === 3, 'Start and answer turns are traced best-effort.');

$invalidRejected = false;
try {
    (new DecisionValidator())->validateDecision(
        ['action' => 'verify_claim', 'rationale' => 'Test', 'arguments' => ['claim' => 'not in response']],
        ['verify_claim'],
        ['answer_text' => 'The candidate response has no such phrase.'],
    );
} catch (HttpException) {
    $invalidRejected = true;
}
check($invalidRejected, 'Tool argument validation rejects claims not found in the answer.');

echo "Interview workflow tests passed.\n";
