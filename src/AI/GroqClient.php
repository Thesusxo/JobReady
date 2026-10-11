<?php
declare(strict_types=1);

namespace JobReady\AI;

final class GroqClient extends OpenAICompatibleClient
{
    public const DEFAULT_MODEL = 'openai/gpt-oss-20b';

    public function __construct(
        ?string $apiKey,
        string $model = self::DEFAULT_MODEL,
    ) {
        parent::__construct($apiKey, $model, 'groq');
    }
}
