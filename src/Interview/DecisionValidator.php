<?php
declare(strict_types=1);

namespace JobReady\Interview;

use JobReady\Http\HttpException;

final class DecisionValidator
{
    public const MAX_CV_BYTES = 20000;
    public const MAX_JOB_BYTES = 16000;
    public const MAX_ROLE_BYTES = 200;
    public const MAX_ANSWER_BYTES = 8000;
    public const MAX_ANSWERS = 20;

    /** @return array{cv_text: string, job_description: string, target_role: string} */
    public function validateStart(array $input): array
    {
        return [
            'cv_text' => $this->requiredText($input['cv_text'] ?? null, 'cv_text', self::MAX_CV_BYTES, 30),
            'job_description' => $this->requiredText($input['job_description'] ?? null, 'job_description', self::MAX_JOB_BYTES, 30),
            'target_role' => $this->requiredText($input['target_role'] ?? null, 'target_role', self::MAX_ROLE_BYTES, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function validatePlan(array $plan): array
    {
        $focusAreas = $plan['focus_areas'] ?? null;
        if (!is_array($focusAreas) || count($focusAreas) < 2 || count($focusAreas) > 5) {
            throw new HttpException(502, 'The interview plan could not be validated.');
        }
        $focusAreas = array_values(array_map(fn ($value): string => $this->modelText($value, 120), $focusAreas));
        $depth = $plan['suggested_depth'] ?? null;
        if (!in_array($depth, ['beginner', 'intermediate', 'advanced'], true)) {
            throw new HttpException(502, 'The interview plan could not be validated.');
        }
        return [
            'focus_areas' => $focusAreas,
            'rationale' => $this->modelText($plan['rationale'] ?? null, 600),
            'suggested_depth' => $depth,
        ];
    }

    /** @param list<string> $allowedActions @param array<string, mixed>|null $latestAnswer @return array{action: string, rationale: string, arguments: array<string, mixed>} */
    public function validateDecision(array $decision, array $allowedActions, ?array $latestAnswer): array
    {
        $action = $decision['action'] ?? null;
        $rationale = $this->modelText($decision['rationale'] ?? null, 600);
        $arguments = $decision['arguments'] ?? null;
        if (!is_string($action) || !in_array($action, $allowedActions, true) || !is_array($arguments)) {
            throw new HttpException(502, 'The interview agent returned an invalid decision.');
        }

        if ($action === 'ask_question') {
            $this->requireKeys($arguments, ['question', 'focus']);
            return ['action' => $action, 'rationale' => $rationale, 'arguments' => [
                'question' => $this->modelText($arguments['question'] ?? null, 900, 8),
                'focus' => $this->modelText($arguments['focus'] ?? null, 120),
            ]];
        }
        if ($action === 'probe_answer') {
            if ($latestAnswer === null) {
                throw new HttpException(502, 'The interview agent returned an invalid decision.');
            }
            $this->requireKeys($arguments, ['question', 'focus', 'probe_reason']);
            return ['action' => $action, 'rationale' => $rationale, 'arguments' => [
                'question' => $this->modelText($arguments['question'] ?? null, 900, 8),
                'focus' => $this->modelText($arguments['focus'] ?? null, 120),
                'probe_reason' => $this->modelText($arguments['probe_reason'] ?? null, 300),
            ]];
        }
        if ($action === 'verify_claim') {
            if ($latestAnswer === null) {
                throw new HttpException(502, 'The interview agent returned an invalid decision.');
            }
            $this->requireKeys($arguments, ['claim']);
            $claim = $this->modelText($arguments['claim'] ?? null, 400, 5);
            if (stripos($latestAnswer['answer_text'], $claim) === false) {
                throw new HttpException(502, 'The interview agent returned an invalid decision.');
            }
            return ['action' => $action, 'rationale' => $rationale, 'arguments' => ['claim' => $claim]];
        }
        if ($action === 'finish_interview') {
            if ($latestAnswer === null) {
                throw new HttpException(502, 'The interview agent returned an invalid decision.');
            }
            $this->requireKeys($arguments, ['summary', 'readiness']);
            $readiness = $arguments['readiness'] ?? null;
            if (!in_array($readiness, ['not_ready', 'developing', 'ready'], true)) {
                throw new HttpException(502, 'The interview agent returned an invalid decision.');
            }
            return ['action' => $action, 'rationale' => $rationale, 'arguments' => [
                'summary' => $this->modelText($arguments['summary'] ?? null, 1500),
                'readiness' => $readiness,
            ]];
        }
        throw new HttpException(502, 'The interview agent returned an invalid decision.');
    }

    /** @return array<string, mixed> */
    public function validateClaimEvaluation(array $evaluation): array
    {
        $evidence = $evaluation['evidence'] ?? null;
        if (!in_array($evidence, ['supported', 'unclear', 'unsupported'], true)) {
            throw new HttpException(502, 'The interview agent returned an invalid evaluation.');
        }
        return [
            'evidence' => $evidence,
            'explanation' => $this->modelText($evaluation['explanation'] ?? null, 500),
            'follow_up_focus' => $this->modelText($evaluation['follow_up_focus'] ?? null, 120),
        ];
    }

    private function requiredText(mixed $value, string $field, int $maxBytes, int $minBytes): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes || strlen(trim($value)) < $minBytes || preg_match('//u', $value) !== 1) {
            throw new HttpException(422, 'Invalid or missing field: ' . $field . '.');
        }
        return trim($value);
    }

    private function modelText(mixed $value, int $maxBytes, int $minBytes = 1): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes || strlen(trim($value)) < $minBytes || preg_match('//u', $value) !== 1) {
            throw new HttpException(502, 'The interview agent returned an invalid response.');
        }
        return trim($value);
    }

    /** @param list<string> $expected */
    private function requireKeys(array $arguments, array $expected): void
    {
        $keys = array_keys($arguments);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new HttpException(502, 'The interview agent returned an invalid decision.');
        }
    }
}
