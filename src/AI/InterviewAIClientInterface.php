<?php
declare(strict_types=1);

namespace JobReady\AI;

interface InterviewAIClientInterface
{
    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    public function createPlan(array $candidate): array;

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function decide(array $context): array;

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function verifyClaim(array $context, string $claim): array;
}
