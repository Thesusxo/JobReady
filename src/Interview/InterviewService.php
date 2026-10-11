<?php
declare(strict_types=1);

namespace JobReady\Interview;

use JobReady\AI\AIUnavailableException;
use JobReady\AI\AIModelMetadataInterface;
use JobReady\AI\AIProviderMetadataInterface;
use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Observability\NeatlogsClientInterface;
use JobReady\Observability\StructuredTraceClientInterface;
use JobReady\Storage\InterviewStore;
use PDO;
use Throwable;

final class InterviewService
{
    private const MAX_DECISIONS_PER_TURN = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly InterviewStore $store,
        private readonly InterviewAIClientInterface $openAI,
        private readonly DecisionValidator $validator,
        private readonly NeatlogsClientInterface $neatlogs,
    ) {
    }

    /** @return array<string, mixed> */
    public function start(array $input): array
    {
        $candidate = $this->validator->validateStart($input);
        $traceStarted = hrtime(true);
        $traceChildren = [];
        $id = $this->uuid();
        $planStarted = hrtime(true);
        try {
            $rawPlan = $this->openAI->createPlan($candidate);
        } catch (AIUnavailableException $exception) {
            $traceChildren[] = $this->llmSpan('generate_interview_plan', 'ERROR', $planStarted, 'provider_call_failed');
            $this->emitTrace('jobready.interview.started', $id, $traceChildren, 'ERROR', $traceStarted, 'provider_call_failed');
            throw new HttpException(503, 'The interview service is temporarily unavailable.', $exception->withStage('plan')->diagnostics);
        }
        $traceChildren[] = $this->llmSpan('generate_interview_plan', 'OK', $planStarted);
        $validationStarted = hrtime(true);
        try {
            $plan = $this->validator->validatePlan($rawPlan);
            $traceChildren[] = $this->guardrailSpan('validate_interview_plan', $validationStarted, true);
        } catch (HttpException $exception) {
            $traceChildren[] = $this->guardrailSpan('validate_interview_plan', $validationStarted, false, 'invalid_output');
            $this->emitTrace('jobready.interview.started', $id, $traceChildren, 'ERROR', $traceStarted, 'invalid_output');
            throw $this->invalidOutput($exception, 'plan', 'INVALID_PLAN');
        }

        $beginStarted = hrtime(true);
        $rolledBack = false;
        try {
            $this->pdo->beginTransaction();
            $traceChildren[] = $this->taskSpan('begin_interview_transaction', 'transaction_begin', $beginStarted);
            $persistPlanStarted = hrtime(true);
            $this->store->create($id, $candidate['cv_text'], $candidate['job_description'], $candidate['target_role'], $plan);
            $traceChildren[] = $this->taskSpan('persist_interview_plan', 'persist_plan', $persistPlanStarted);
            $this->makeNextDecision($id, true, $traceChildren);
            $commitStarted = hrtime(true);
            $this->pdo->commit();
            $traceChildren[] = $this->taskSpan('commit_interview_transaction', 'transaction_commit', $commitStarted);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $rollbackStarted = hrtime(true);
                $this->pdo->rollBack();
                $rolledBack = true;
                $traceChildren[] = $this->taskSpan('rollback_interview_transaction', 'transaction_rollback', $rollbackStarted, 'ERROR', 'transaction_rollback');
            }
            $this->emitTrace('jobready.interview.started', $id, $traceChildren, 'ERROR', $traceStarted,
                $rolledBack ? 'transaction_rollback' : 'internal_failure');
            if ($exception instanceof HttpException) {
                throw $exception;
            }
            throw new HttpException(503, 'The interview could not be started. Please try again.');
        }
        $this->emitTrace('jobready.interview.started', $id, $traceChildren, 'OK', $traceStarted);
        return $this->getProgress($id);
    }

    /** @return array<string, mixed> */
    public function submitAnswer(string $id, mixed $answer): array
    {
        if (!is_string($answer) || strlen($answer) > DecisionValidator::MAX_ANSWER_BYTES || strlen(trim($answer)) < 1 || preg_match('//u', $answer) !== 1) {
            throw new HttpException(422, 'Answer must be non-empty valid text within the size limit.');
        }
        $interview = $this->store->find($id);
        if ($interview === null) {
            throw new HttpException(404, 'Interview not found.');
        }
        if ($interview['status'] !== 'active' || !is_string($interview['current_question']) || $interview['current_question'] === '') {
            throw new HttpException(409, 'Interview is not awaiting an answer.');
        }

        $traceStarted = hrtime(true);
        $traceChildren = [];
        $beginStarted = hrtime(true);
        $rolledBack = false;
        try {
            $this->pdo->beginTransaction();
            $traceChildren[] = $this->taskSpan('begin_interview_transaction', 'transaction_begin', $beginStarted);
            $persistStarted = hrtime(true);
            $this->store->addAnswer($id, trim($answer));
            $traceChildren[] = $this->taskSpan('persist_candidate_answer', 'persist_answer', $persistStarted);
            $outcome = $this->makeNextDecision($id, false, $traceChildren);
            $commitStarted = hrtime(true);
            $this->pdo->commit();
            $traceChildren[] = $this->taskSpan('commit_interview_transaction', 'transaction_commit', $commitStarted);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $rollbackStarted = hrtime(true);
                $this->pdo->rollBack();
                $rolledBack = true;
                $traceChildren[] = $this->taskSpan('rollback_interview_transaction', 'transaction_rollback', $rollbackStarted, 'ERROR', 'transaction_rollback');
            }
            $this->emitTrace('jobready.interview.turn', $id, $traceChildren, 'ERROR', $traceStarted,
                $rolledBack ? 'transaction_rollback' : 'internal_failure');
            if ($exception instanceof HttpException) {
                throw $exception;
            }
            throw new HttpException(503, 'The interview turn could not be processed. Please retry your answer.');
        }
        $this->emitTrace('jobready.interview.turn', $id, $traceChildren, 'OK', $traceStarted);
        return $this->getProgress($id);
    }

    /** @return array<string, mixed> */
    public function getProgress(string $id): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            throw new HttpException(404, 'Interview not found.');
        }
        $interview = $this->store->find($id);
        if ($interview === null) {
            throw new HttpException(404, 'Interview not found.');
        }
        return [
            'id' => $interview['id'],
            'target_role' => $interview['target_role'],
            'plan' => $interview['plan'],
            'status' => $interview['status'],
            'answer_count' => (int) $interview['answer_count'],
            'current_question' => $interview['current_question'],
            'answers' => $this->store->getAnswers($id),
            'decisions' => $this->store->getDecisions($id),
            'evaluations' => $this->store->getEvaluations($id),
            'created_at' => $interview['created_at'],
            'updated_at' => $interview['updated_at'],
        ];
    }

    /** @return array{action: string} */
    private function makeNextDecision(string $id, bool $starting, array &$traceChildren): array
    {
        for ($iteration = 0; $iteration < self::MAX_DECISIONS_PER_TURN; $iteration++) {
            $interview = $this->store->find($id);
            if ($interview === null) {
                throw new HttpException(404, 'Interview not found.');
            }
            $latestAnswer = $this->store->getLatestAnswer($id);
            $allowedActions = $this->allowedActions($starting, (int) $interview['answer_count'], $iteration);
            $context = $this->buildContext($interview, $allowedActions);
            $agentStarted = hrtime(true);
            $agentChildren = [];
            $decisionStarted = hrtime(true);

            try {
                $rawDecision = $this->openAI->decide($context);
            } catch (AIUnavailableException $exception) {
                $agentChildren[] = $this->llmSpan('decide_next_interview_action', 'ERROR', $decisionStarted, 'provider_call_failed');
                $traceChildren[] = $this->agentSpan('decide_interview_turn', $agentStarted, $agentChildren, 'ERROR', 'provider_call_failed');
                throw new HttpException(503, 'The interview service is temporarily unavailable.', $exception->withStage('decision')->diagnostics);
            }
            $agentChildren[] = $this->llmSpan('decide_next_interview_action', 'OK', $decisionStarted);
            $validationStarted = hrtime(true);
            try {
                $decision = $this->validator->validateDecision($rawDecision, $allowedActions, $latestAnswer);
            } catch (HttpException $exception) {
                $agentChildren[] = $this->guardrailSpan('validate_agent_decision', $validationStarted, false, 'invalid_output');
                $traceChildren[] = $this->agentSpan('decide_interview_turn', $agentStarted, $agentChildren, 'ERROR', 'invalid_output');
                throw $this->invalidOutput($exception, 'decision', 'INVALID_DECISION');
            }
            $agentChildren[] = $this->guardrailSpan('validate_agent_decision', $validationStarted, true);

            if ($decision['action'] === 'ask_question' || $decision['action'] === 'probe_answer') {
                $arguments = $decision['arguments'];
                $persistStarted = hrtime(true);
                $decisionId = $this->store->addDecision($id, $decision['action'], $arguments, $decision['rationale'], [
                    'question' => $arguments['question'],
                    'focus' => $arguments['focus'],
                ]);
                $this->store->setCurrentQuestion($id, $arguments['question'], $decisionId);
                $agentChildren[] = $this->taskSpan('persist_selected_interview_question', $decision['action'], $persistStarted);
                $traceChildren[] = $this->agentSpan('select_' . $decision['action'], $agentStarted, $agentChildren);
                return ['action' => $decision['action']];
            }

            if ($decision['action'] === 'verify_claim') {
                $verificationStarted = hrtime(true);
                try {
                    $rawEvaluation = $this->openAI->verifyClaim($context, $decision['arguments']['claim']);
                } catch (AIUnavailableException $exception) {
                    $agentChildren[] = $this->llmSpan('verify_candidate_claim', 'ERROR', $verificationStarted, 'provider_call_failed');
                    $traceChildren[] = $this->agentSpan('select_verify_claim', $agentStarted, $agentChildren, 'ERROR', 'provider_call_failed');
                    throw new HttpException(503, 'The interview service is temporarily unavailable.', $exception->withStage('claim_verification')->diagnostics);
                }
                $agentChildren[] = $this->llmSpan('verify_candidate_claim', 'OK', $verificationStarted);
                $evaluationValidationStarted = hrtime(true);
                try {
                    $evaluation = $this->validator->validateClaimEvaluation($rawEvaluation);
                } catch (HttpException $exception) {
                    $agentChildren[] = $this->guardrailSpan('validate_claim_evaluation', $evaluationValidationStarted, false, 'invalid_output');
                    $traceChildren[] = $this->agentSpan('select_verify_claim', $agentStarted, $agentChildren, 'ERROR', 'invalid_output');
                    throw $this->invalidOutput($exception, 'claim_verification', 'INVALID_EVALUATION');
                }
                $agentChildren[] = $this->guardrailSpan('validate_claim_evaluation', $evaluationValidationStarted, true);
                $persistStarted = hrtime(true);
                $this->store->addDecision($id, 'verify_claim', $decision['arguments'], $decision['rationale'], $evaluation);
                $this->store->addEvaluation($id, (int) $latestAnswer['id'], 'claim_verification', $evaluation);
                $agentChildren[] = $this->taskSpan('persist_claim_verification', 'persist_claim_evaluation', $persistStarted);
                $traceChildren[] = $this->agentSpan('select_verify_claim', $agentStarted, $agentChildren);
                continue;
            }

            $arguments = $decision['arguments'];
            $persistStarted = hrtime(true);
            $this->store->addDecision($id, 'finish_interview', $arguments, $decision['rationale'], $arguments);
            $this->store->addEvaluation($id, null, 'readiness_report', $arguments);
            $this->store->finish($id);
            $agentChildren[] = $this->taskSpan('persist_readiness_report', 'persist_readiness_report', $persistStarted);
            $traceChildren[] = $this->agentSpan('select_finish_interview', $agentStarted, $agentChildren);
            return ['action' => 'finish_interview'];
        }

        throw new HttpException(502, 'The interview agent exceeded its per-turn decision limit. Please retry.');
    }

    /** @return list<string> */
    private function allowedActions(bool $starting, int $answerCount, int $iteration): array
    {
        if ($starting) {
            return ['ask_question'];
        }
        if ($answerCount >= DecisionValidator::MAX_ANSWERS) {
            return ['finish_interview'];
        }
        if ($iteration === self::MAX_DECISIONS_PER_TURN - 1) {
            return ['ask_question', 'probe_answer', 'finish_interview'];
        }
        return ['ask_question', 'probe_answer', 'verify_claim', 'finish_interview'];
    }

    /** @param array<string, mixed> $interview @param list<string> $allowedActions @return array<string, mixed> */
    private function buildContext(array $interview, array $allowedActions): array
    {
        $answers = array_slice($this->store->getAnswers($interview['id']), -8);
        $evaluations = array_slice($this->store->getEvaluations($interview['id']), -12);
        $decisions = array_slice($this->store->getDecisions($interview['id']), -12);
        return [
            'target_role' => $interview['target_role'],
            'cv_text' => $interview['cv_text'],
            'job_description' => $interview['job_description'],
            'plan' => $interview['plan'],
            'answer_count' => (int) $interview['answer_count'],
            'maximum_answers' => DecisionValidator::MAX_ANSWERS,
            'recent_answers' => $answers,
            'recent_evaluations' => $evaluations,
            'recent_decisions' => $decisions,
            'allowed_actions' => $allowedActions,
        ];
    }

    private function emitTrace(string $name, string $sessionId, array $children, string $status, int $started, ?string $error = null): void
    {
        try {
            if ($this->neatlogs instanceof StructuredTraceClientInterface) {
                $trace = [
                    'name' => $name,
                    'kind' => 'WORKFLOW',
                    'status' => $status,
                    'duration_ms' => $this->elapsedMs($started),
                    'attributes' => [
                        'neatlogs.session.id' => $sessionId,
                        'neatlogs.workflow.name' => 'jobready_interview',
                    ],
                    'children' => $children,
                ];
                if ($error !== null) {
                    $trace['error'] = $error;
                }
                $this->neatlogs->recordTrace($trace);
                return;
            }
            // Keep legacy/fake observers best-effort without sending private interview content.
            $this->neatlogs->recordEvent($name, [
                'session_id' => $sessionId,
                'outcome' => $status === 'OK' ? 'success' : 'failure',
            ]);
        } catch (Throwable) {
            // Telemetry is best-effort and must not affect interview progress.
        }
    }

    /** @return array<string, mixed> */
    private function agentSpan(string $name, int $started, array $children, string $status = 'OK', ?string $error = null): array
    {
        $span = [
            'name' => $name,
            'kind' => 'AGENT',
            'status' => $status,
            'duration_ms' => $this->elapsedMs($started),
            'attributes' => [
                'neatlogs.agent.name' => 'jobready_interview_agent',
                'neatlogs.agent.role' => 'adaptive_interview_coach',
            ],
            'children' => $children,
        ];
        if ($error !== null) {
            $span['error'] = $error;
        }
        return $span;
    }

    /** @return array<string, mixed> */
    private function llmSpan(string $name, string $status, int $started, ?string $error = null): array
    {
        $span = ['name' => $name, 'kind' => 'LLM', 'status' => $status, 'duration_ms' => $this->elapsedMs($started)];
        if ($this->openAI instanceof AIModelMetadataInterface) {
            $model = $this->openAI->modelName();
            if (preg_match('/^(?:gemini-[A-Za-z0-9.-]{1,100}|gpt-[A-Za-z0-9.-]{1,80}|openai\/gpt-oss-20b)$/D', $model)) {
                $span['model'] = $model;
            }
        }
        if ($this->openAI instanceof AIProviderMetadataInterface) {
            $provider = $this->openAI->providerName();
            if (in_array($provider, ['gemini', 'openai', 'groq'], true)) {
                $span['provider'] = $provider;
            }
        }
        if ($error !== null) {
            $span['error'] = $error;
        }
        return $span;
    }

    /** @return array<string, mixed> */
    private function guardrailSpan(string $name, int $started, bool $passed, ?string $error = null): array
    {
        $span = [
            'name' => $name,
            'kind' => 'GUARDRAIL',
            'passed' => $passed,
            'status' => $passed ? 'OK' : 'ERROR',
            'duration_ms' => $this->elapsedMs($started),
        ];
        if ($error !== null) {
            $span['error'] = $error;
        }
        return $span;
    }

    /** @return array<string, mixed> */
    private function taskSpan(string $name, string $task, int $started, string $status = 'OK', ?string $error = null): array
    {
        $span = [
            'name' => $name,
            'kind' => 'TASK',
            'status' => $status,
            'duration_ms' => $this->elapsedMs($started),
            'attributes' => ['neatlogs.task.key' => $task],
        ];
        if ($error !== null) {
            $span['error'] = $error;
        }
        return $span;
    }

    private function elapsedMs(int $started): float
    {
        return round(max(0, hrtime(true) - $started) / 1_000_000, 3);
    }

    private function invalidOutput(HttpException $exception, string $stage, string $reason): HttpException
    {
        return new HttpException($exception->status, $exception->getMessage(), [
            'category' => 'invalid_output', 'stage' => $stage, 'reason' => $reason,
        ]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
