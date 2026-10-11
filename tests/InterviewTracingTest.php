<?php
declare(strict_types=1);

use JobReady\AI\AIUnavailableException;
use JobReady\AI\AIModelMetadataInterface;
use JobReady\AI\AIProviderMetadataInterface;
use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NeatlogsClientInterface;
use JobReady\Observability\StructuredTraceClientInterface;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'JobReady\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) require $file;
    }
});

function interviewTraceCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$traceClient = new class implements StructuredTraceClientInterface {
    public array $traces = [];
    public int $legacyEvents = 0;
    public bool $fail = false;

    public function recordTrace(array $trace): bool
    {
        $this->traces[] = $trace;
        if ($this->fail) throw new RuntimeException('Synthetic observability failure.');
        return true;
    }

    public function recordEvent(string $name, array $attributes = []): bool
    {
        $this->legacyEvents++;
        return false;
    }
};
$step = 0;
$fakeModel = new class($step) implements InterviewAIClientInterface, AIModelMetadataInterface {
    public int $step = 0;
    public string $mode = 'normal';

    public function __construct(int $step) { $this->step = $step; }
    public function modelName(): string { return 'gemini-3.1-flash-lite'; }
    public function createPlan(array $candidate): array
    {
        if ($this->mode === 'plan_failure') throw new AIUnavailableException('Private plan error.', ['category' => 'quota_or_rate_limit']);
        return ['focus_areas' => ['Debugging', 'Security'], 'rationale' => 'Fictional fixtures.', 'suggested_depth' => 'beginner'];
    }
    public function decide(array $context): array
    {
        if ($this->mode === 'decision_failure' && $context['answer_count'] === 0) {
            throw new AIUnavailableException('Private decision error.', ['category' => 'provider_unavailable']);
        }
        if ($context['answer_count'] === 0) {
            return ['action' => 'ask_question', 'rationale' => 'Explore fictional experience.', 'arguments' => ['question' => 'Describe your fictional PHP project.', 'focus' => 'Debugging']];
        }
        if ($context['recent_evaluations'] === []) {
            return ['action' => 'verify_claim', 'rationale' => 'Review a provided fictional claim.', 'arguments' => ['claim' => 'reduced latency']];
        }
        return ['action' => 'probe_answer', 'rationale' => 'Ask for specific fictional evidence.', 'arguments' => ['question' => 'Which debugging steps did you take?', 'focus' => 'Debugging', 'probe_reason' => 'The answer was vague.']];
    }
    public function verifyClaim(array $context, string $claim): array
    {
        return ['evidence' => 'unclear', 'explanation' => 'The supplied fictional answer lacks detail.', 'follow_up_focus' => 'Debugging'];
    }
};

function interviewTraceFixture(
    InterviewAIClientInterface $model,
    StructuredTraceClientInterface $tracer,
): array {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    Database::initialize($pdo);
    $service = new InterviewService($pdo, new InterviewStore($pdo), $model, new DecisionValidator(), $tracer);
    $candidate = ['target_role' => 'Junior PHP Developer', 'cv_text' => 'Fictional developer built a PHP inventory tool.', 'job_description' => 'Fictional role using PHP, SQL, and debugging.'];
    return [$service, $candidate, $pdo];
}

$tracer = $traceClient;
$model = $fakeModel;
[$service, $candidate] = interviewTraceFixture($model, $tracer);
$tracer->fail = true; // Exceptions from observability must not interrupt interview work.
$started = $service->start($candidate);
interviewTraceCheck($started['status'] === 'active', 'Trace failure stopped interview creation.');
$tracer->fail = false;
$afterAnswer = $service->submitAnswer($started['id'], 'I reduced latency using caching.');
interviewTraceCheck($afterAnswer['current_question'] === 'Which debugging steps did you take?', 'Claim verification/adaptive follow-up failed.');
interviewTraceCheck(count($tracer->traces) === 2 && $tracer->legacyEvents === 0, 'A turn did not emit exactly one structured trace.');
foreach ($tracer->traces as $trace) {
    interviewTraceCheck(!isset($trace['project']), 'Client-only project should not be invented by the service.');
    interviewTraceCheck(($trace['attributes']['neatlogs.session.id'] ?? null) === $started['id'], 'Turns were not grouped by the interview session ID.');
    interviewTraceCheck(($trace['attributes']['neatlogs.workflow.name'] ?? null) === 'jobready_interview', 'Workflow attribute missing.');
    interviewTraceCheck(isset($trace['duration_ms']) && $trace['duration_ms'] >= 0, 'Measured root duration missing.');
}
$allTraceJson = json_encode($tracer->traces, JSON_THROW_ON_ERROR);
foreach (['Fictional developer', 'Fictional role', 'I reduced latency', 'Describe your fictional', 'Which debugging steps', 'Fictional fixtures'] as $privateValue) {
    interviewTraceCheck(!str_contains($allTraceJson, $privateValue), 'Candidate or generated content entered traces.');
}
$startChildren = $tracer->traces[0]['children'];
interviewTraceCheck(array_column($startChildren, 'kind') === ['LLM', 'GUARDRAIL', 'TASK', 'TASK', 'AGENT', 'TASK'], 'Plan/decision/transaction execution tree is incomplete.');
interviewTraceCheck($startChildren[0]['name'] === 'generate_interview_plan' && $startChildren[0]['status'] === 'OK', 'Actual plan LLM span missing.');
interviewTraceCheck($startChildren[0]['model'] === 'gemini-3.1-flash-lite', 'Safe model identifier missing from plan trace.');
interviewTraceCheck($startChildren[1]['kind'] === 'GUARDRAIL' && $startChildren[1]['passed'] === true, 'Plan validation span missing.');
$turnChildren = $tracer->traces[1]['children'];
$agent = null;
foreach ($turnChildren as $span) {
    if (($span['kind'] ?? null) === 'AGENT' && ($span['name'] ?? null) === 'select_probe_answer') {
        $agent = $span;
        break;
    }
}
interviewTraceCheck(is_array($agent), 'Selected adaptive probe action was not represented.');
interviewTraceCheck($agent['kind'] === 'AGENT' && $agent['name'] === 'select_probe_answer', 'Selected adaptive action not represented.');
$verificationAgent = null;
foreach ($turnChildren as $span) {
    if (($span['kind'] ?? null) === 'AGENT' && ($span['name'] ?? null) === 'select_verify_claim') {
        $verificationAgent = $span;
        break;
    }
}
interviewTraceCheck(is_array($verificationAgent), 'Claim-verification agent step missing.');
interviewTraceCheck(array_column($verificationAgent['children'], 'kind') === ['LLM', 'GUARDRAIL', 'LLM', 'GUARDRAIL', 'TASK'], 'Claim decision, evaluation, or persistence spans missing.');
interviewTraceCheck($verificationAgent['children'][2]['name'] === 'verify_candidate_claim', 'Claim verification LLM operation missing.');
interviewTraceCheck($verificationAgent['children'][3]['kind'] === 'GUARDRAIL' && $verificationAgent['children'][3]['passed'] === true, 'Claim evaluation validation missing.');
interviewTraceCheck($verificationAgent['children'][4]['attributes']['neatlogs.task.key'] === 'persist_claim_evaluation', 'Claim result persistence missing.');
interviewTraceCheck(end($turnChildren)['attributes']['neatlogs.task.key'] === 'transaction_commit', 'Successful transaction was not recorded as committed.');

$failureTracer = new class implements StructuredTraceClientInterface {
    public array $traces = [];
    public function recordTrace(array $trace): bool { $this->traces[] = $trace; return false; }
    public function recordEvent(string $name, array $attributes = []): bool { return false; }
};
$failureModel = clone $fakeModel;
$failureModel->mode = 'decision_failure';
[$failedService, $failedCandidate, $failedPdo] = interviewTraceFixture($failureModel, $failureTracer);
try {
    $failedService->start($failedCandidate);
    throw new RuntimeException('Synthetic decision failure was not propagated.');
} catch (HttpException $exception) {
    interviewTraceCheck($exception->status === 503, 'Provider failure HTTP status changed.');
}
interviewTraceCheck((int) $failedPdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Failed interview transaction was not rolled back.');
interviewTraceCheck(count($failureTracer->traces) === 1, 'Failed turn trace missing.');
$failedTrace = $failureTracer->traces[0];
interviewTraceCheck($failedTrace['status'] === 'ERROR' && $failedTrace['error'] === 'transaction_rollback', 'Failed transaction root status is inaccurate.');
interviewTraceCheck($failedTrace['children'][0]['status'] === 'OK', 'Completed plan call was mislabeled.');
interviewTraceCheck($failedTrace['children'][4]['kind'] === 'AGENT' && $failedTrace['children'][4]['status'] === 'ERROR', 'Failed decision stage was not marked as failed.');
interviewTraceCheck($failedTrace['children'][5]['attributes']['neatlogs.task.key'] === 'transaction_rollback', 'Actual rollback was not recorded.');
interviewTraceCheck(!str_contains(json_encode($failureTracer->traces), 'Private decision error.'), 'Provider exception text entered telemetry.');

$groqTracer = new class implements StructuredTraceClientInterface {
    public array $traces = [];
    public function recordTrace(array $trace): bool { $this->traces[] = $trace; return true; }
    public function recordEvent(string $name, array $attributes = []): bool { return false; }
};
$groqModel = new class implements InterviewAIClientInterface, AIModelMetadataInterface, AIProviderMetadataInterface {
    public function modelName(): string { return 'openai/gpt-oss-20b'; }
    public function providerName(): string { return 'groq'; }
    public function createPlan(array $candidate): array
    {
        return ['focus_areas' => ['Testing', 'Reliability'], 'rationale' => 'Fictional fixture.', 'suggested_depth' => 'beginner'];
    }
    public function decide(array $context): array
    {
        return ['action' => 'ask_question', 'rationale' => 'Fictional fixture.', 'arguments' => ['question' => 'Describe a test.', 'focus' => 'Testing']];
    }
    public function verifyClaim(array $context, string $claim): array
    {
        return ['evidence' => 'unclear', 'explanation' => 'Fictional fixture.', 'follow_up_focus' => 'Testing'];
    }
};
[$groqService, $groqCandidate] = interviewTraceFixture($groqModel, $groqTracer);
$groqService->start($groqCandidate);
$groqLlmSpan = $groqTracer->traces[0]['children'][0];
interviewTraceCheck(($groqLlmSpan['provider'] ?? null) === 'groq'
    && ($groqLlmSpan['model'] ?? null) === 'openai/gpt-oss-20b', 'Selected Groq provider/model metadata missing from trace.');

echo "Interview structured-tracing tests passed (nested operations, grouping, measured duration, privacy, rollback, best-effort failure).\n";
