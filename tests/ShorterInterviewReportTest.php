<?php
declare(strict_types=1);

use JobReady\AI\InterviewAIClientInterface;
use JobReady\AI\AIUnavailableException;
use JobReady\Http\HttpException;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NullNeatlogsClient;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

require dirname(__DIR__) . '/config/autoload.php';

function reportCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function reportFixture(int $finishAt = 10): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    Database::initialize($pdo);
    $store = new InterviewStore($pdo);
    $model = new class($finishAt) implements InterviewAIClientInterface {
        public int $calls = 0;
        public string $failure = '';
        public array $finalContext = [];
        public function __construct(private int $finishAt) {}
        public function createPlan(array $candidate): array
        {
            return ['focus_areas' => ['Testing', 'Measurement'], 'rationale' => 'Fictional evidence.', 'suggested_depth' => 'beginner'];
        }
        public function decide(array $context): array
        {
            $this->calls++;
            reportCheck($context['maximum_answers'] === 10, 'Model receives the new cap.');
            if ($context['answer_count'] >= $this->finishAt) {
                $this->finalContext = $context;
                if ($this->failure === 'transport') throw new AIUnavailableException('Fictional transport failure.');
                if ($this->failure === 'extra_question') return ['action' => 'ask_question', 'rationale' => 'Invalid extra question.', 'arguments' => ['question' => 'An eleventh question?', 'focus' => 'Testing']];
                return ['action' => 'finish_interview', 'rationale' => 'Sufficient evidence.', 'arguments' => [
                    'summary' => 'Developing: concrete testing evidence, but impact measurement needs more detail.',
                    'readiness' => 'developing',
                    'strengths' => [['text' => 'Described a concrete testing technique.', 'answer_no' => 1, 'evidence' => 'I wrote unit tests']],
                    'areas_to_improve' => [['text' => 'Give measurable impact for the testing work.', 'answer_no' => 1, 'evidence' => 'I wrote unit tests', 'requirement' => 'measure impact']],
                    'next_steps' => ['Prepare one quantified before-and-after testing example.', 'Practice explaining how a test detects a regression.'],
                ]];
            }
            return ['action' => 'ask_question', 'rationale' => 'Explore relevant evidence.', 'arguments' => ['question' => 'Describe testing example ' . ($context['answer_count'] + 1) . '.', 'focus' => 'Testing']];
        }
        public function verifyClaim(array $context, string $claim): array { throw new RuntimeException('Unexpected claim call.'); }
    };
    $service = new InterviewService($pdo, $store, $model, new DecisionValidator(), new NullNeatlogsClient());
    return [$pdo, $store, $model, $service];
}

$candidate = ['cv_text' => 'Fictional QA developer with testing experience.', 'job_description' => 'Use unit tests and measure impact of quality improvements.', 'target_role' => 'QA Engineer'];
foreach ([2, 10] as $finishAt) {
    [$pdo, $store, $model, $service] = reportFixture($finishAt);
    $state = $service->start($candidate);
    for ($i = 1; $i <= $finishAt; $i++) $state = $service->submitAnswer($state['id'], 'I wrote unit tests for fictional example ' . $i . '.');
    reportCheck($state['status'] === 'finished' && $state['answer_count'] === $finishAt, 'Early/normal completion failed.');
    reportCheck(count($state['answers']) === $finishAt && str_contains($state['answers'][$finishAt - 1]['answer_text'], 'example ' . $finishAt), 'Final answer was discarded.');
    reportCheck($state['current_question'] === null, 'Completion left an extra question.');
    $report = $state['evaluations'][0]['data'];
    reportCheck(count($report['strengths']) === 1 && count($report['areas_to_improve']) === 1 && count($report['next_steps']) === 2, 'Structured report not persisted.');
    if ($finishAt === 10) {
        reportCheck($model->finalContext['allowed_actions'] === ['finish_interview'], 'Tenth answer permits another question.');
        reportCheck(count($model->finalContext['recent_answers']) === 10, 'Final report lost early evidence.');
    }
    $calls = $model->calls;
    try { $service->submitAnswer($state['id'], 'Duplicate answer.'); throw new RuntimeException('Completed session accepted another answer.'); }
    catch (HttpException $e) { reportCheck($e->status === 409 && $model->calls === $calls, 'Duplicate triggered a provider call.'); }
}

// Seed a pre-upgrade, fictional interview with 19 answers and an already-issued question.
[$pdo, $store, $model, $service] = reportFixture();
$id = '123e4567-e89b-42d3-a456-426614174019';
$store->create($id, $candidate['cv_text'], $candidate['job_description'], $candidate['target_role'], $model->createPlan($candidate));
for ($i = 1; $i <= 20; $i++) {
    $decision = $store->addDecision($id, 'ask_question', ['question' => 'Legacy fictional question ' . $i, 'focus' => 'Testing'], 'Legacy fixture.', []);
    $store->setCurrentQuestion($id, 'Legacy fictional question ' . $i, $decision);
    if ($i < 20) $store->addAnswer($id, 'I wrote unit tests for legacy example ' . $i . '.');
}
$legacy = $service->submitAnswer($id, 'I wrote unit tests for legacy final example.');
reportCheck($legacy['status'] === 'finished' && count($legacy['answers']) === 20, 'Legacy interview stranded or lost progress.');
reportCheck($model->finalContext['allowed_actions'] === ['finish_interview'] && count($model->finalContext['recent_answers']) === 20, 'Legacy completion did not retain evidence.');

// Failed final turns remain retryable at the same question; no automatic retry or extra question.
foreach (['transport' => 503, 'extra_question' => 502] as $failure => $status) {
    [$pdo, $store, $model, $service] = reportFixture();
    $state = $service->start($candidate);
    for ($i = 1; $i <= 9; $i++) $state = $service->submitAnswer($state['id'], 'I wrote unit tests for example ' . $i . '.');
    $model->failure = $failure;
    $calls = $model->calls;
    try { $service->submitAnswer($state['id'], 'I wrote unit tests for final example.'); throw new RuntimeException('Invalid final turn accepted.'); }
    catch (HttpException $e) { reportCheck($e->status === $status, 'Incorrect failure status.'); }
    $saved = $service->getProgress($state['id']);
    reportCheck($saved['answer_count'] === 9 && $saved['current_question'] === $state['current_question'] && $model->calls === $calls + 1, 'Failure discarded progress or automatically retried.');
    $model->failure = '';
    reportCheck($service->submitAnswer($state['id'], 'I wrote unit tests for final example.')['status'] === 'finished', 'Explicit recovery could not finish.');
}

$validator = new DecisionValidator();
$context = ['recent_answers' => [['answer_no' => 1, 'answer_text' => 'I wrote unit tests for the API.']], 'job_description' => $candidate['job_description']];
$base = ['action' => 'finish_interview', 'rationale' => 'Fictional conclusion.', 'arguments' => ['summary' => 'Evidence is limited.', 'readiness' => 'developing']];
$validated = $validator->validateDecision($base, ['finish_interview'], $context['recent_answers'][0], $context);
reportCheck($validated['arguments']['strengths'] === [] && count($validated['arguments']['next_steps']) === 2, 'Missing fields need honest evidence gaps and general preparation actions.');
$badOptional = [
    ['strengths' => [['text' => 'Unsupported skill.', 'answer_no' => 1, 'evidence' => 'I led a team']]],
    ['strengths' => [['text' => 'Testing.', 'answer_no' => 99, 'evidence' => 'I wrote unit tests']]],
    ['areas_to_improve' => [['text' => 'Impact.', 'answer_no' => 1, 'evidence' => 'I wrote unit tests', 'requirement' => 'manage a sales team']]],
    ['strengths' => 'not an array'], ['next_steps' => ['Only one action']], ['next_steps' => [false, 'Action']],
];
foreach ($badOptional as $fields) {
    $input = $base;
    $input['arguments'] = array_replace($input['arguments'], $fields);
    $result = $validator->validateDecision($input, ['finish_interview'], $context['recent_answers'][0], $context)['arguments'];
    reportCheck($result['strengths'] === [] && $result['areas_to_improve'] === [] && count($result['next_steps']) >= 2,
        'Invalid optional findings blocked completion or invented evidence.');
}
foreach ([['summary' => null], ['readiness' => 'hire']] as $fields) {
    $input = $base;
    $input['arguments'] = array_replace($input['arguments'], $fields);
    try { $validator->validateDecision($input, ['finish_interview'], $context['recent_answers'][0], $context); throw new RuntimeException('Invalid report accepted.'); }
    catch (HttpException $e) { reportCheck($e->status === 502, 'Report validation status changed.'); }
}
echo "Shorter interview/report tests passed (early/10-answer/legacy completion, evidence, final persistence, failure recovery).\n";
