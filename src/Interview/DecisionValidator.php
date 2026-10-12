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
    public const MAX_ANSWERS = 10;

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
        if (!is_array($focusAreas) || !array_is_list($focusAreas)) {
            throw $this->invalidPlan('PLAN_FOCUS_AREAS_TYPE');
        }
        if (count($focusAreas) < 2 || count($focusAreas) > 5) {
            throw $this->invalidPlan('PLAN_FOCUS_AREAS_COUNT');
        }
        $focusAreas = array_map(fn ($value): string => $this->planText($value, 120, 'PLAN_FOCUS_ITEM'), $focusAreas);
        $depth = $plan['suggested_depth'] ?? null;
        if (!in_array($depth, ['beginner', 'intermediate', 'advanced'], true)) {
            throw $this->invalidPlan('PLAN_DEPTH_ENUM');
        }
        return [
            'focus_areas' => $focusAreas,
            'rationale' => $this->planText($plan['rationale'] ?? null, 600, 'PLAN_RATIONALE'),
            'suggested_depth' => $depth,
        ];
    }

    /** @param list<string> $allowedActions @param array<string, mixed>|null $latestAnswer @return array{action: string, rationale: string, arguments: array<string, mixed>} */
    public function validateDecision(array $decision, array $allowedActions, ?array $latestAnswer, array $reportContext = []): array
    {
        $action = $decision['action'] ?? null;
        if ($allowedActions === ['finish_interview'] && $action !== 'finish_interview') {
            throw $this->invalidCoreReport('ACTION');
        }
        $rationale = $action === 'finish_interview'
            ? $this->coreReportText($decision['rationale'] ?? null, 600, 'RATIONALE')
            : $this->modelText($decision['rationale'] ?? null, 600);
        $arguments = $decision['arguments'] ?? null;
        if (!is_string($action) || !in_array($action, $allowedActions, true) || !is_array($arguments)) {
            if ($action === 'finish_interview') throw $this->invalidCoreReport('ARGUMENTS_OR_ACTION');
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
                throw $this->invalidCoreReport('LATEST_ANSWER');
            }
            $extra = array_diff(array_keys($arguments), ['summary', 'readiness', 'strengths', 'areas_to_improve', 'next_steps']);
            if ($extra !== []) {
                throw $this->invalidCoreReport('ARGUMENT_KEYS');
            }
            $readiness = $arguments['readiness'] ?? null;
            if (!in_array($readiness, ['not_ready', 'developing', 'ready'], true)) {
                throw $this->invalidCoreReport('READINESS');
            }
            return ['action' => $action, 'rationale' => $rationale, 'arguments' => [
                'summary' => $this->coreReportText($arguments['summary'] ?? null, 1500, 'SUMMARY'),
                'readiness' => $readiness,
                'strengths' => $this->reportFindings($arguments['strengths'] ?? [], $reportContext),
                'areas_to_improve' => $this->reportFindings($arguments['areas_to_improve'] ?? [], $reportContext, true),
                'next_steps' => $this->nextSteps($arguments['next_steps'] ?? []),
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

    /** Optional findings never block a valid core report. Keep only exact, verifiable evidence. */
    private function reportFindings(mixed $items, array $context, bool $improvement = false): array
    {
        $section = $improvement ? 'AREAS_TO_IMPROVE' : 'STRENGTHS';
        if (!is_array($items) || !array_is_list($items)) {
            $this->discardOptionalReport($section, 'LIST_FORMAT');
            return [];
        }
        if (count($items) > 3) $this->discardOptionalReport($section, 'EXCESS_ITEMS');
        $result = [];
        // Bound validation/log work, and retain at most three supported findings per section.
        foreach (array_slice($items, 0, 12) as $item) {
            try {
                if (!is_array($item)) throw new HttpException(502, 'Invalid optional finding.');
                $this->requireKeys($item, $improvement ? ['text', 'answer_no', 'evidence', 'requirement'] : ['text', 'answer_no', 'evidence']);
                $text = $this->modelText($item['text'] ?? null, 400);
                $quote = $this->modelText($item['evidence'] ?? null, 400, 5);
                $requirement = $improvement ? $this->modelText($item['requirement'] ?? null, 400, 5) : null;
            } catch (HttpException) {
                $this->discardOptionalReport($section, 'ITEM_FORMAT');
                continue;
            }
            $number = $item['answer_no'] ?? null;
            // Only canonical positive decimal strings can be normalized; never floats or coercions.
            if (is_string($number) && preg_match('/^[1-9][0-9]{0,5}$/D', $number)) $number = (int) $number;
            $answer = null;
            foreach ($context['recent_answers'] ?? [] as $candidate) {
                if (is_int($number) && (int) $candidate['answer_no'] === $number) $answer = $candidate;
            }
            if ($answer === null) {
                $this->discardOptionalReport($section, 'ANSWER_REFERENCE');
                continue;
            }
            if (!str_contains($answer['answer_text'], $quote)) {
                $this->discardOptionalReport($section, 'EVIDENCE_MISMATCH');
                continue;
            }
            $finding = ['text' => $text, 'answer_no' => $number, 'evidence' => $quote];
            if ($improvement) {
                if (!str_contains($context['job_description'] ?? '', $requirement)) {
                    $this->discardOptionalReport($section, 'REQUIREMENT_MISMATCH');
                    continue;
                }
                $finding['requirement'] = $requirement;
            }
            $result[] = $finding;
            if (count($result) === 3) break;
        }
        return $result;
    }

    private function nextSteps(mixed $items): array
    {
        $result = [];
        if (!is_array($items) || !array_is_list($items)) {
            $this->discardOptionalReport('NEXT_STEPS', 'LIST_FORMAT');
            $items = [];
        }
        if (count($items) > 3) $this->discardOptionalReport('NEXT_STEPS', 'EXCESS_ITEMS');
        foreach (array_slice($items, 0, 12) as $item) {
            try { $result[] = $this->modelText($item, 300); }
            catch (HttpException) { $this->discardOptionalReport('NEXT_STEPS', 'ITEM_FORMAT'); }
            if (count($result) === 3) break;
        }
        // General preparation actions assert no candidate achievements or evidence.
        foreach ([
            'Review your saved answers against the job requirements and identify evidence to add.',
            'Practice one concrete example explaining your contribution, reasoning, and outcome.',
        ] as $fallback) {
            if (count($result) >= 2) break;
            if (!in_array($fallback, $result, true)) $result[] = $fallback;
        }
        return $result;
    }

    private function coreReportText(mixed $value, int $limit, string $requirement): string
    {
        try { return $this->modelText($value, $limit); }
        catch (HttpException) { throw $this->invalidCoreReport($requirement); }
    }

    private function invalidCoreReport(string $requirement): HttpException
    {
        error_log('JobReady report core validation failed (requirement=' . $requirement . ').');
        return new HttpException(502, 'The interview agent returned an invalid decision.');
    }

    private function discardOptionalReport(string $section, string $reason): void
    {
        // Both arguments are fixed internal labels. Never include item contents or model values.
        error_log('JobReady report optional finding discarded (section=' . $section . ', reason=' . $reason . ').');
    }

    private function requiredText(mixed $value, string $field, int $maxBytes, int $minBytes): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes || strlen(trim($value)) < $minBytes || preg_match('//u', $value) !== 1) {
            throw new HttpException(422, 'Invalid or missing field: ' . $field . '.');
        }
        return trim($value);
    }

    private function invalidPlan(string $reason): HttpException
    {
        return new HttpException(502, 'The interview plan could not be validated.', [
            'category' => 'invalid_output', 'stage' => 'plan', 'reason' => $reason,
        ]);
    }

    private function planText(mixed $value, int $maxBytes, string $prefix): string
    {
        if (!is_string($value)) throw $this->invalidPlan($prefix . '_TYPE');
        if (strlen($value) > $maxBytes || strlen(trim($value)) < 1) throw $this->invalidPlan($prefix . '_LENGTH');
        if (preg_match('//u', $value) !== 1) throw $this->invalidPlan($prefix . '_UTF8');
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
