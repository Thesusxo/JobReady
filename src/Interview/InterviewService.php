<?php
declare(strict_types=1);

namespace JobReady\Interview;

use JobReady\AI\AIUnavailableException;
use JobReady\AI\InterviewAIClientInterface;
use JobReady\Http\HttpException;
use JobReady\Observability\NeatlogsClientInterface;
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
        try {
            $plan = $this->validator->validatePlan($this->openAI->createPlan($candidate));
        } catch (AIUnavailableException $exception) {
            throw new HttpException(503, 'The interview service is temporarily unavailable.');
        }

        $id = $this->uuid();
        $this->pdo->beginTransaction();
        try {
            $this->store->create($id, $candidate['cv_text'], $candidate['job_description'], $candidate['target_role'], $plan);
            $this->makeNextDecision($id, true);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof HttpException) {
                throw $exception;
            }
            throw new HttpException(503, 'The interview could not be started. Please try again.');
        }
        $this->trace('jobready.interview.started', ['interview_id' => $id, 'action' => 'ask_question']);
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

        $this->pdo->beginTransaction();
        try {
            $this->store->addAnswer($id, trim($answer));
            $outcome = $this->makeNextDecision($id, false);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof HttpException) {
                throw $exception;
            }
            throw new HttpException(503, 'The interview turn could not be processed. Please retry your answer.');
        }
        $this->trace('jobready.interview.turn', [
            'interview_id' => $id,
            'answer_count' => (int) $this->store->find($id)['answer_count'],
            'action' => $outcome['action'],
        ]);
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
    private function makeNextDecision(string $id, bool $starting): array
    {
        for ($iteration = 0; $iteration < self::MAX_DECISIONS_PER_TURN; $iteration++) {
            $interview = $this->store->find($id);
            if ($interview === null) {
                throw new HttpException(404, 'Interview not found.');
            }
            $latestAnswer = $this->store->getLatestAnswer($id);
            $allowedActions = $this->allowedActions($starting, (int) $interview['answer_count'], $iteration);
            $context = $this->buildContext($interview, $allowedActions);

            try {
                $rawDecision = $this->openAI->decide($context);
            } catch (AIUnavailableException $exception) {
                throw new HttpException(503, 'The interview service is temporarily unavailable.');
            }
            $decision = $this->validator->validateDecision($rawDecision, $allowedActions, $latestAnswer);

            if ($decision['action'] === 'ask_question' || $decision['action'] === 'probe_answer') {
                $arguments = $decision['arguments'];
                $decisionId = $this->store->addDecision($id, $decision['action'], $arguments, $decision['rationale'], [
                    'question' => $arguments['question'],
                    'focus' => $arguments['focus'],
                ]);
                $this->store->setCurrentQuestion($id, $arguments['question'], $decisionId);
                return ['action' => $decision['action']];
            }

            if ($decision['action'] === 'verify_claim') {
                try {
                    $evaluation = $this->validator->validateClaimEvaluation(
                        $this->openAI->verifyClaim($context, $decision['arguments']['claim']),
                    );
                } catch (AIUnavailableException $exception) {
                    throw new HttpException(503, 'The interview service is temporarily unavailable.');
                }
                $this->store->addDecision($id, 'verify_claim', $decision['arguments'], $decision['rationale'], $evaluation);
                $this->store->addEvaluation($id, (int) $latestAnswer['id'], 'claim_verification', $evaluation);
                continue;
            }

            $arguments = $decision['arguments'];
            $this->store->addDecision($id, 'finish_interview', $arguments, $decision['rationale'], $arguments);
            $this->store->addEvaluation($id, null, 'readiness_report', $arguments);
            $this->store->finish($id);
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

    private function trace(string $event, array $attributes): void
    {
        try {
            $this->neatlogs->recordEvent($event, $attributes);
        } catch (Throwable) {
            // Telemetry is best-effort and must not affect interview progress.
        }
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
