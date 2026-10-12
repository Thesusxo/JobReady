<?php
declare(strict_types=1);

use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Http\ErrorResponse;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\StructuredTraceClientInterface;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;

require dirname(__DIR__) . '/config/autoload.php';

function completionCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function completionDecision(): array
{
    return ['action' => 'finish_interview', 'rationale' => 'Enough fictional practice evidence.', 'arguments' => [
        'summary' => 'Developing: scheduling evidence is present; impact measurement needs more detail.',
        'readiness' => 'developing',
        'strengths' => [['text' => 'Described content scheduling.', 'answer_no' => 1, 'evidence' => 'I scheduled weekly posts']],
        'areas_to_improve' => [['text' => 'Give a measurable campaign outcome.', 'answer_no' => 1, 'evidence' => 'reviewed engagement', 'requirement' => 'Analyse engagement metrics']],
        'next_steps' => ['Practice explaining a measured campaign result.', 'Review an engagement metric and its limitations.'],
    ]];
}

function completionFixture(): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    Database::initialize($pdo);
    $model = new class implements InterviewAIClientInterface {
        public int $calls = 0;
        public int $planCalls = 0;
        public array $final = [];
        public function createPlan(array $candidate): array
        {
            $this->planCalls++;
            return ['focus_areas' => ['Campaigns', 'Measurement'], 'rationale' => 'Explore fictional role evidence.', 'suggested_depth' => 'intermediate'];
        }
        public function decide(array $context): array
        {
            $this->calls++;
            if ($context['answer_count'] >= 10) {
                completionCheck($context['allowed_actions'] === ['finish_interview'], 'Question 11 allowed.');
                completionCheck(count($context['recent_answers']) === 10, 'Final evidence excludes accepted answer.');
                return $this->final;
            }
            return ['action' => 'ask_question', 'rationale' => 'Explore relevant fictional evidence.', 'arguments' => ['question' => 'Describe fictional campaign example ' . ($context['answer_count'] + 1) . '.', 'focus' => 'Campaigns']];
        }
        public function verifyClaim(array $context, string $claim): array { throw new RuntimeException('Unexpected extra provider call.'); }
    };
    $model->final = completionDecision();
    $observer = new class implements StructuredTraceClientInterface {
        public array $traces = [];
        public function recordTrace(array $trace): bool { $this->traces[] = $trace; return true; }
        public function recordEvent(string $name, array $attributes = []): bool { throw new RuntimeException('Structured tracing changed.'); }
    };
    $service = new InterviewService($pdo, new InterviewStore($pdo), $model, new DecisionValidator(), $observer);
    $state = $service->start(['cv_text' => 'Fictional marketer with a practice content calendar.',
        'job_description' => 'Analyse engagement metrics and plan campaigns.', 'target_role' => 'Social Media Manager']);
    for ($i = 1; $i <= 9; $i++) $state = $service->submitAnswer($state['id'], 'I scheduled weekly posts and reviewed engagement. Fictional example ' . $i . '.');
    completionCheck($state['answer_count'] === 9 && $state['status'] === 'active', 'Nine-answer fixture failed.');
    return [$pdo, $model, $observer, $service, $state];
}

$validator = new DecisionValidator();
$answer = ['answer_no' => 1, 'answer_text' => 'I scheduled weekly posts and reviewed engagement.'];
$context = ['recent_answers' => [$answer], 'job_description' => 'Analyse engagement metrics and plan campaigns.'];
$base = completionDecision();
$valid = $validator->validateDecision($base, ['finish_interview'], $answer, $context);
completionCheck($valid === $base, 'Valid report changed.');

$log = tempnam(sys_get_temp_dir(), 'jobready-report-log-');
$previousLog = ini_get('error_log');
ini_set('error_log', $log);
try {
    $finding = $base['arguments']['strengths'][0];
    // Positive canonical strings resolve only to supplied answer numbers.
    foreach ([1, '1'] as $number) {
        $input = $base;
        $input['arguments']['strengths'][0]['answer_no'] = $number;
        $report = $validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments'];
        completionCheck($report['strengths'][0]['answer_no'] === 1 && $report['strengths'][0]['evidence'] === $finding['evidence'], 'Canonical answer reference not normalized safely.');
    }
    foreach (['01', ' 1', '1 ', '1.0', '1e0', '+1', '0', '99', 1.0, true, null] as $number) {
        $input = $base;
        $input['arguments']['strengths'][0]['answer_no'] = $number;
        $report = $validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments'];
        completionCheck($report['strengths'] === [] && count($report['areas_to_improve']) === 1, 'Ambiguous reference accepted or unrelated valid section lost.');
    }
    $badItems = [
        false, 'SYNTHETIC_PRIVATE_MARKER', [],
        array_replace($finding, ['text' => ['unexpected']]),
        array_replace($finding, ['text' => str_repeat('é', 201)]),
        array_replace($finding, ['text' => "\xFF"]),
        array_replace($finding, ['evidence' => 'I planned weekly posts']),
        array_replace($finding, ['evidence' => 'I Scheduled weekly posts']),
        array_replace($finding, ['evidence' => 'SYNTHETIC_PRIVATE_MARKER']),
        array_replace($finding, ['evidence' => str_repeat('a', 401)]),
        array_replace($finding, ['evidence' => 'post']),
        array_replace($finding, ['extra' => 'SYNTHETIC_PRIVATE_MARKER']),
    ];
    foreach ($badItems as $item) {
        $input = $base;
        $input['arguments']['strengths'] = [$item, $finding];
        $report = $validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments'];
        completionCheck($report['strengths'] === [$finding], 'Bad finding blocked or displaced verified evidence.');
    }
    foreach ([false, 'malformed', ['named' => $finding]] as $items) {
        $input = $base;
        $input['arguments']['strengths'] = $items;
        completionCheck($validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments']['strengths'] === [], 'Invalid section shape blocked completion.');
    }
    $input = $base;
    $input['arguments']['strengths'] = array_fill(0, 50, $finding);
    completionCheck(count($validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments']['strengths']) === 3, 'Finding limit lost.');

    $improvement = $base['arguments']['areas_to_improve'][0];
    $input = $base;
    $input['arguments']['areas_to_improve'] = [array_replace($improvement, ['requirement' => 'Measure engagement metrics']), $improvement];
    completionCheck($validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments']['areas_to_improve'] === [$improvement], 'Paraphrased requirement retained or verified improvement lost.');

    foreach ([null, false, 'malformed', [], [false, '  ', 12, ['action' => 'x']], ['One practical action.'], ['Valid action.', "\xFF", str_repeat('a', 301)]] as $steps) {
        $input = $base;
        $input['arguments']['next_steps'] = $steps;
        $report = $validator->validateDecision($input, ['finish_interview'], $answer, $context)['arguments'];
        completionCheck(count($report['next_steps']) === 2 && count($report['strengths']) === 1, 'Recommendation fallback failed.');
        if (is_array($steps) && isset($steps[0]) && is_string($steps[0])) completionCheck($report['next_steps'][0] === $steps[0], 'Valid recommendation was replaced.');
    }

    // Every case starts with an existing nine-answer interview, then makes just one final call.
    $optionalCases = [
        ['strengths' => 'malformed'],
        ['strengths' => [array_replace($finding, ['evidence' => 'I planned weekly posts'])]],
        ['strengths' => [array_replace($finding, ['answer_no' => '1'])]],
        ['areas_to_improve' => [array_replace($improvement, ['requirement' => 'Measure engagement metrics'])]],
        ['next_steps' => ['One practical action.']],
        ['strengths' => [false, $finding], 'areas_to_improve' => [[], $improvement], 'next_steps' => [false, 'A practical action.']],
    ];
    foreach ($optionalCases as $fields) {
        [$pdo, $model, $observer, $service, $before] = completionFixture();
        $model->final['arguments'] = array_replace($model->final['arguments'], $fields);
        $calls = $model->calls;
        $finished = $service->submitAnswer($before['id'], 'I scheduled weekly posts and reviewed engagement. Fictional final example.');
        completionCheck($finished['status'] === 'finished' && $finished['answer_count'] === 10 && count($finished['answers']) === 10 && $finished['current_question'] === null, 'Optional findings stranded final answer.');
        completionCheck(str_contains($finished['answers'][9]['answer_text'], 'Fictional final example'), 'Final answer not persisted.');
        completionCheck($model->calls === $calls + 1 && $model->planCalls === 1, 'Extra provider/retry call introduced.');
        completionCheck((int) $pdo->query('SELECT COUNT(*) FROM interview_evaluations')->fetchColumn() === 1 && !$pdo->inTransaction(), 'Final report transaction incomplete.');
        $report = $finished['evaluations'][0]['data'];
        completionCheck(array_keys($report) === ['summary', 'readiness', 'strengths', 'areas_to_improve', 'next_steps'] && count($report['next_steps']) >= 2, 'Report section contract changed.');
        completionCheck($observer->traces[count($observer->traces) - 1]['status'] === 'OK', 'Optional discards broke successful trace.');
        completionCheck(!str_contains(json_encode($observer->traces), 'Fictional final example'), 'Trace leaked answer content.');
        try { $service->submitAnswer($before['id'], 'Duplicate final answer.'); throw new RuntimeException('Eleventh answer accepted.'); }
        catch (HttpException $e) { completionCheck($e->status === 409 && $model->calls === $calls + 1, 'Duplicate answer caused AI call.'); }
    }

    $invalidCore = [
        ['action' => 'ask_question'],
        ['rationale' => null], ['rationale' => str_repeat('a', 601)],
        ['arguments' => null],
        ['arguments' => array_replace($base['arguments'], ['summary' => null])],
        ['arguments' => array_replace($base['arguments'], ['summary' => '  '])],
        ['arguments' => array_replace($base['arguments'], ['summary' => str_repeat('a', 1501)])],
        ['arguments' => array_replace($base['arguments'], ['summary' => "\xFF"])],
        ['arguments' => array_replace($base['arguments'], ['readiness' => 'hire'])],
        ['arguments' => array_replace($base['arguments'], ['extra' => 'SYNTHETIC_PRIVATE_MARKER'])],
    ];
    foreach ($invalidCore as $changes) {
        [$pdo, $model, $observer, $service, $before] = completionFixture();
        $model->final = array_replace($model->final, $changes);
        $calls = $model->calls;
        try { $service->submitAnswer($before['id'], 'Fictional pending final answer.'); throw new RuntimeException('Invalid core accepted.'); }
        catch (HttpException $e) {
            completionCheck($e->status === 502 && !isset(ErrorResponse::fromException($e, 'production', false)['diagnostics']), 'Core failure not safe.');
        }
        $saved = $service->getProgress($before['id']);
        completionCheck($saved['status'] === 'active' && $saved['answer_count'] === 9 && count($saved['answers']) === 9 && $saved['current_question'] === $before['current_question'], 'Core failure did not roll back safely.');
        completionCheck($saved['evaluations'] === [] && $model->calls === $calls + 1 && !$pdo->inTransaction(), 'Core failure retried or persisted partial report.');
        completionCheck($observer->traces[count($observer->traces) - 1]['error'] === 'transaction_rollback', 'Rollback trace changed.');
        // Deliberate fixture retry, never an automatic retry or production submission.
        $model->final = completionDecision();
        completionCheck($service->submitAnswer($before['id'], 'I scheduled weekly posts and reviewed engagement. Fictional recovered final answer.')['status'] === 'finished', 'Existing nine-answer fixture not recoverable.');
    }
    $logs = file_get_contents($log);
    completionCheck(str_contains($logs, 'report core validation failed (requirement=SUMMARY)') && str_contains($logs, 'report optional finding discarded (section=STRENGTHS, reason=EVIDENCE_MISMATCH)'), 'Core/optional fixed-label diagnostics missing.');
    foreach (['SYNTHETIC_PRIVATE_MARKER', $answer['answer_text'], $context['job_description'], $base['arguments']['summary'], $finding['text']] as $privateText) {
        completionCheck(!str_contains($logs, $privateText), 'Diagnostic log leaked generated/candidate content.');
    }
} finally {
    ini_set('error_log', $previousLog);
    unlink($log);
}
echo "Completion-validation tests passed (filtered evidence, safe references/fallbacks, final commit, strict core rollback/recovery, diagnostics).\n";
