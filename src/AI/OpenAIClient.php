<?php
declare(strict_types=1);

namespace JobReady\AI;

final class OpenAIClient extends OpenAICompatibleClient implements OpenAIClientInterface
{
    public function __construct(
        ?string $apiKey,
        string $model = 'gpt-4o-mini',
    ) {
        parent::__construct($apiKey, $model, 'openai');
    }
}
