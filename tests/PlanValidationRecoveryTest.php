<?php
declare(strict_types=1);

use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\AI\AIUnavailableException;
use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\ErrorResponse;
use JobReady\Observability\StructuredTraceClientInterface;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

require dirname(__DIR__) . '/config/autoload.php';

function planCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$valid = ['focus_areas' => ['API design', 'Testing'], 'rationale' => 'Practice role-relevant evidence.', 'suggested_depth' => 'intermediate'];
$fixtures = [
    'missing_focus' => ['rationale' => 'A concise plan.', 'suggested_depth' => 'beginner'],
    'one_focus' => array_replace($valid, ['focus_areas' => ['Testing']]),
    'too_many_focuses' => array_replace($valid, ['focus_areas' => ['APIs', 'Testing', 'SQL', 'Security', 'Teamwork', 'Reliability']]),
    'focus_objects' => array_replace($valid, ['focus_areas' => [['topic' => 'APIs'], ['topic' => 'Testing']]]),
    'long_focus' => array_replace($valid, ['focus_areas' => [str_repeat('a', 121), 'Testing']]),
    'unicode_focus_bytes' => array_replace($valid, ['focus_areas' => [str_repeat('é', 61), 'Testing']]),
    'missing_depth' => ['focus_areas' => ['APIs', 'Testing'], 'rationale' => 'A concise plan.'],
    'depth_capitalization' => array_replace($valid, ['suggested_depth' => 'Intermediate']),
    'missing_rationale' => ['focus_areas' => ['APIs', 'Testing'], 'suggested_depth' => 'beginner'],
    'empty_rationale' => array_replace($valid, ['rationale' => '  ']),
    'long_rationale' => array_replace($valid, ['rationale' => str_repeat('a', 601)]),
    'wrapped_plan' => ['plan' => $valid],
];
$validator = new DecisionValidator();
planCheck($validator->validatePlan($valid) === $valid, 'Valid plan changed.');
$expected = [
    'missing_focus' => 'PLAN_FOCUS_AREAS_TYPE', 'one_focus' => 'PLAN_FOCUS_AREAS_COUNT',
    'too_many_focuses' => 'PLAN_FOCUS_AREAS_COUNT', 'focus_objects' => 'PLAN_FOCUS_ITEM_TYPE',
    'long_focus' => 'PLAN_FOCUS_ITEM_LENGTH', 'unicode_focus_bytes' => 'PLAN_FOCUS_ITEM_LENGTH',
    'missing_depth' => 'PLAN_DEPTH_ENUM', 'depth_capitalization' => 'PLAN_DEPTH_ENUM',
    'missing_rationale' => 'PLAN_RATIONALE_TYPE', 'empty_rationale' => 'PLAN_RATIONALE_LENGTH',
    'long_rationale' => 'PLAN_RATIONALE_LENGTH', 'wrapped_plan' => 'PLAN_FOCUS_AREAS_TYPE',
];
foreach ($fixtures as $label => $plan) {
    // Reproduce the production guardrail failure using decodable JSON, not a transport failure.
    $decoded = json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    try {
        $validator->validatePlan($decoded);
        throw new RuntimeException('Invalid fixture accepted: ' . $label);
    } catch (HttpException $e) {
        planCheck($e->status === 502, 'Unexpected guardrail status.');
        planCheck(($e->diagnostics['reason'] ?? null) === $expected[$label], 'Requirement diagnostic missing for ' . $label);
    }
}
echo "Decodable plan-validation failure reproduced for 12 malformed/incomplete fixtures; valid plan unchanged.\n";

foreach ([
    [array_replace($valid, ['focus_areas' => ['first' => 'APIs', 'second' => 'Testing']]), 'PLAN_FOCUS_AREAS_TYPE'],
    [array_replace($valid, ['focus_areas' => ['', 'Testing']]), 'PLAN_FOCUS_ITEM_LENGTH'],
    [array_replace($valid, ['focus_areas' => [123, 'Testing']]), 'PLAN_FOCUS_ITEM_TYPE'],
    [array_replace($valid, ['focus_areas' => ["\xFF", 'Testing']]), 'PLAN_FOCUS_ITEM_UTF8'],
    [array_replace($valid, ['rationale' => ['unexpected object']]), 'PLAN_RATIONALE_TYPE'],
    [array_replace($valid, ['rationale' => "\xFF"]), 'PLAN_RATIONALE_UTF8'],
] as [$plan, $reason]) {
    try { $validator->validatePlan($plan); throw new RuntimeException('Invalid type/UTF-8 plan accepted.'); }
    catch (HttpException $e) { planCheck(($e->diagnostics['reason'] ?? null) === $reason, 'Diagnostic mismatch.'); }
}
$boundary = array_replace($valid, ['focus_areas' => [str_repeat('é', 60), 'Testing'], 'rationale' => str_repeat('é', 300)]);
planCheck($validator->validatePlan($boundary) === $boundary, 'Valid UTF-8 byte boundary changed.');

function planScenario(array $plans): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    Database::initialize($pdo);
    $model = new class($plans, $pdo) implements InterviewAIClientInterface {
        public int $planCalls = 0;
        public int $decisionCalls = 0;
        public bool $failDecision = false;
        public function __construct(private array $plans, private PDO $pdo) {}
        public function createPlan(array $candidate): array
        {
            $this->planCalls++;
            planCheck(!$this->pdo->inTransaction(), 'Plan retry inside database transaction.');
            planCheck((int) $this->pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Interview persisted before validation.');
            if ($this->plans === []) throw new RuntimeException('Unexpected extra plan call.');
            $result = array_shift($this->plans);
            if ($result instanceof Throwable) throw $result;
            return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        }
        public function decide(array $context): array
        {
            $this->decisionCalls++;
            if ($this->failDecision) throw new AIUnavailableException('Fictional decision failure.');
            if ($context['answer_count'] >= 10) {
                planCheck($context['allowed_actions'] === ['finish_interview'], 'Eleventh question permitted.');
                return ['action' => 'finish_interview', 'rationale' => 'Enough fictional evidence.', 'arguments' => [
                    'summary' => 'Developing: concrete testing evidence, but impact needs more detail.', 'readiness' => 'developing',
                    'strengths' => [['text' => 'Described unit testing.', 'answer_no' => 1, 'evidence' => 'I wrote unit tests']],
                    'areas_to_improve' => [['text' => 'Explain measurable impact.', 'answer_no' => 1, 'evidence' => 'I wrote unit tests', 'requirement' => 'measure impact']],
                    'next_steps' => ['Prepare a measurable testing example.', 'Practice explaining a regression test.'],
                ]];
            }
            return ['action' => 'ask_question', 'rationale' => 'Explore fictional testing evidence.', 'arguments' => ['question' => 'Describe testing example ' . ($context['answer_count'] + 1) . '.', 'focus' => 'Testing']];
        }
        public function verifyClaim(array $context, string $claim): array { throw new RuntimeException('Unexpected claim call.'); }
    };
    $trace = new class implements StructuredTraceClientInterface {
        public array $traces = [];
        public function recordTrace(array $trace): bool { $this->traces[] = $trace; return true; }
        public function recordEvent(string $name, array $attributes = []): bool { throw new RuntimeException('Structured observer required.'); }
    };
    $service = new InterviewService($pdo, new InterviewStore($pdo), $model, new DecisionValidator(), $trace);
    return [$pdo, $model, $trace, $service];
}

$candidate = ['cv_text' => 'Fictional QA developer with test automation experience.',
    'job_description' => 'Use unit tests and measure impact of quality improvements.', 'target_role' => 'QA Engineer'];
$log = tempnam(sys_get_temp_dir(), 'jobready-plan-log-');
$previousLog = ini_get('error_log');
ini_set('error_log', $log);
try {
    [$pdo, $model, $trace, $service] = planScenario([$valid]);
    $state = $service->start($candidate);
    planCheck($model->planCalls === 1 && $model->decisionCalls === 1 && $state['plan'] === $valid, 'Valid output should pass unchanged without retry.');
    $bad = array_replace($valid, ['rationale' => str_repeat('SYNTHETIC_PRIVATE_MARKER', 40)]);
    [$pdo, $model, $trace, $service] = planScenario([$bad, $valid]);
    $state = $service->start($candidate);
    planCheck($model->planCalls === 2 && $model->decisionCalls === 1 && $state['plan'] === $valid, 'One bounded retry did not recover.');
    $guards = array_values(array_filter($trace->traces[0]['children'], static fn ($span) => $span['name'] === 'validate_interview_plan'));
    planCheck(array_column($guards, 'passed') === [false, true] && $trace->traces[0]['status'] === 'OK', 'Retry trace does not show recovered guardrail.');
    planCheck(!str_contains(json_encode($trace->traces), 'SYNTHETIC_PRIVATE_MARKER')
        && !str_contains(json_encode($trace->traces), $candidate['cv_text']), 'Recovered trace leaked private fields.');
    for ($i = 1; $i <= 10; $i++) $state = $service->submitAnswer($state['id'], 'I wrote unit tests for fictional example ' . $i . '.');
    planCheck($state['status'] === 'finished' && $state['answer_count'] === 10 && count($state['answers']) === 10 && $state['current_question'] === null, 'Recovered start broke final answer persistence/completion.');
    $report = $state['evaluations'][0]['data'];
    planCheck(count($report['strengths']) === 1 && count($report['areas_to_improve']) === 1 && count($report['next_steps']) === 2, 'Recovered start broke segmented report.');
    planCheck($model->planCalls === 2, 'Answer turns retried plan generation.');

    foreach ([$fixtures['focus_objects'], $bad] as $invalid) {
        [$pdo, $model, $trace, $service] = planScenario([$invalid, $invalid]);
        try { $service->start($candidate); throw new RuntimeException('Two invalid plans accepted.'); }
        catch (HttpException $e) {
            planCheck($e->status === 502 && isset($e->diagnostics['reason']), 'Safe final failure lost diagnostic.');
            planCheck(!isset(ErrorResponse::fromException($e, 'production', false)['diagnostics']), 'Production response exposed diagnostics.');
        }
        planCheck($model->planCalls === 2 && $model->decisionCalls === 0 && !$pdo->inTransaction(), 'Retry exceeded budget or generated opening question.');
        planCheck((int) $pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Invalid start persisted an interview.');
        planCheck($trace->traces[0]['status'] === 'ERROR', 'Final invalid-output trace missing.');
        planCheck(!str_contains(json_encode($trace->traces), 'SYNTHETIC_PRIVATE_MARKER'), 'Failed trace leaked model output.');
    }
    foreach ([
        [new AIUnavailableException('Fictional quota failure.', ['category' => 'quota_or_rate_limit', 'upstream_http_status' => 429])],
        [new AIUnavailableException('Fictional timeout.', ['category' => 'transport', 'curl_error_code' => 28])],
        [new AIUnavailableException('Fictional malformed JSON.', ['category' => 'invalid_output', 'reason' => 'MALFORMED_JSON'])],
        [$bad, new AIUnavailableException('Fictional retry transport failure.')],
    ] as $responses) {
        $calls = count($responses);
        [$pdo, $model, $trace, $service] = planScenario($responses);
        try { $service->start($candidate); throw new RuntimeException('Provider failure accepted.'); }
        catch (HttpException $e) { planCheck($e->status === 503, 'Provider failure status changed.'); }
        planCheck($model->planCalls === $calls && $model->decisionCalls === 0, 'Provider failure automatically retried.');
        planCheck((int) $pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Provider failure created records.');
    }
    [$pdo, $model, $trace, $service] = planScenario([$valid]);
    $model->failDecision = true;
    try { $service->start($candidate); throw new RuntimeException('Decision failure accepted.'); }
    catch (HttpException $e) { planCheck($e->status === 503, 'Decision failure status changed.'); }
    planCheck($model->planCalls === 1 && (int) $pdo->query('SELECT COUNT(*) FROM interviews')->fetchColumn() === 0, 'Opening decision failure retried plan or failed rollback.');
    $logText = file_get_contents($log);
    planCheck(str_contains($logText, 'requirement=PLAN_RATIONALE_LENGTH, attempt=1') && str_contains($logText, 'attempt=2'), 'Safe server diagnostics absent.');
    planCheck(!str_contains($logText, 'SYNTHETIC_PRIVATE_MARKER') && !str_contains($logText, $candidate['cv_text']) && !str_contains($logText, $candidate['job_description']), 'Server diagnostics leaked content.');
    planCheck(!str_contains(json_encode($trace->traces), 'SYNTHETIC_PRIVATE_MARKER'), 'Trace leaked plan content.');
} finally {
    ini_set('error_log', $previousLog);
    unlink($log);
}
echo "Plan recovery/privacy tests passed (one retry, no transport retries, 10-answer report, safe diagnostics and rollback).\n";
