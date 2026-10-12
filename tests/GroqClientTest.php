<?php
declare(strict_types=1);

namespace JobReady\AI {
    final class OpenAICompatibleCurlFixture
    {
        public static string $url = '';
        public static array $options = [];
        public static string|false $body = '';
        public static int $status = 200;
        public static int $error = 0;
        public static int $requests = 0;
        public static int $closed = 0;

        public static function reset(string|false $body = '', int $status = 200, int $error = 0): void
        {
            self::$url = '';
            self::$options = [];
            self::$body = $body;
            self::$status = $status;
            self::$error = $error;
            self::$requests = self::$closed = 0;
        }
    }

    function curl_init(string $url): object
    {
        OpenAICompatibleCurlFixture::$url = $url;
        return new \stdClass();
    }
    function curl_setopt_array(object $handle, array $options): bool
    {
        OpenAICompatibleCurlFixture::$options = $options;
        return true;
    }
    function curl_exec(object $handle): string|false
    {
        OpenAICompatibleCurlFixture::$requests++;
        return OpenAICompatibleCurlFixture::$body;
    }
    function curl_getinfo(object $handle, int $option): int
    {
        return $option === CURLINFO_HTTP_CODE ? OpenAICompatibleCurlFixture::$status : 0;
    }
    function curl_errno(object $handle): int
    {
        return OpenAICompatibleCurlFixture::$error;
    }
    function curl_close(object $handle): void
    {
        OpenAICompatibleCurlFixture::$closed++;
    }
}

namespace {
    use JobReady\AI\AIUnavailableException;
    use JobReady\AI\GroqClient;
    use JobReady\AI\InterviewAIClientInterface;
    use JobReady\AI\OpenAIClient;
    use JobReady\AI\OpenAICompatibleCurlFixture;

    require dirname(__DIR__) . '/config/autoload.php';

    function groqTestCheck(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    function openAICompletion(array $content, array $extraMessage = []): string
    {
        return json_encode(['choices' => [['message' => [
            'role' => 'assistant',
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            ...$extraMessage,
        ]]]], JSON_THROW_ON_ERROR);
    }

    $candidate = [
        'target_role' => 'Fictional QA Engineer',
        'cv_text' => 'Fictional candidate with test automation experience.',
        'job_description' => 'Fictional role covering PHP APIs and testing.',
    ];
    $plan = ['focus_areas' => ['Testing', 'APIs'], 'rationale' => 'Matches the fictional role.', 'suggested_depth' => 'beginner'];
    $decision = ['action' => 'ask_question', 'rationale' => 'Explore experience.', 'arguments' => ['question' => 'Describe a test you wrote.', 'focus' => 'Testing']];
    $evaluation = ['evidence' => 'unclear', 'explanation' => 'More detail would help.', 'follow_up_focus' => 'Measurement'];
    $fakeKey = 'SYNTHETIC_GROQ_API_KEY_DO_NOT_LEAK';

    OpenAICompatibleCurlFixture::reset(openAICompletion($plan, ['reasoning' => 'SYNTHETIC_PRIVATE_REASONING']));
    $client = new GroqClient($fakeKey);
    groqTestCheck($client instanceof InterviewAIClientInterface, 'Groq client does not implement the provider-independent contract.');
    groqTestCheck($client->providerName() === 'groq', 'Groq provider metadata is inaccurate.');
    groqTestCheck($client->modelName() === 'openai/gpt-oss-20b', 'Groq default model is incorrect.');
    groqTestCheck($client->createPlan($candidate) === $plan, 'Groq plan JSON was not parsed from message.content.');

    $options = OpenAICompatibleCurlFixture::$options;
    groqTestCheck(OpenAICompatibleCurlFixture::$url === 'https://api.groq.com/openai/v1/chat/completions', 'Groq endpoint is incorrect.');
    groqTestCheck(OpenAICompatibleCurlFixture::$requests === 1 && OpenAICompatibleCurlFixture::$closed === 1, 'Groq request was retried or its cURL handle leaked.');
    groqTestCheck($options[CURLOPT_CONNECTTIMEOUT] === 3 && $options[CURLOPT_TIMEOUT] === 12, 'Groq network timeouts are not bounded.');
    groqTestCheck($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Groq transport followed a redirect or allowed non-HTTPS.');
    groqTestCheck($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'Groq TLS verification was weakened.');
    $headers = $options[CURLOPT_HTTPHEADER];
    groqTestCheck($headers === ['Authorization: Bearer ' . $fakeKey, 'Content-Type: application/json', 'Accept: application/json'], 'Groq authorization headers are incorrect.');
    $payload = json_decode($options[CURLOPT_POSTFIELDS], true, 32, JSON_THROW_ON_ERROR);
    groqTestCheck($payload['model'] === 'openai/gpt-oss-20b', 'Groq model was not sent.');
    groqTestCheck(($payload['response_format'] ?? null) === ['type' => 'json_object'], 'JSON-object mode is missing.');
    groqTestCheck(($payload['max_completion_tokens'] ?? null) === 1200 && !isset($payload['max_tokens']), 'Groq plan token budget is incompatible.');
    groqTestCheck(str_contains($payload['messages'][0]['content'], '120 UTF-8 bytes')
        && str_contains($payload['messages'][0]['content'], '600 UTF-8 bytes')
        && str_contains($payload['messages'][0]['content'], 'No wrapper object'), 'Exact plan validation constraints missing from prompt.');
    groqTestCheck(($payload['temperature'] ?? null) === 0.5 && ($payload['reasoning_effort'] ?? null) === 'low'
        && ($payload['include_reasoning'] ?? null) === false, 'Groq reasoning settings are not bounded.');
    groqTestCheck(!str_contains($options[CURLOPT_POSTFIELDS], $fakeKey), 'Groq key leaked into the request body.');
    groqTestCheck(($payload['messages'][0]['role'] ?? null) === 'system' && ($payload['messages'][1]['role'] ?? null) === 'user', 'Existing prompt/context separation changed.');

    OpenAICompatibleCurlFixture::reset(openAICompletion($decision));
    groqTestCheck($client->decide(['allowed_actions' => ['ask_question']]) === $decision, 'Groq decision JSON was not parsed.');
    $payload = json_decode(OpenAICompatibleCurlFixture::$options[CURLOPT_POSTFIELDS], true, 32, JSON_THROW_ON_ERROR);
    groqTestCheck(($payload['max_completion_tokens'] ?? null) === 1800, 'Groq report token budget is not bounded.');
    groqTestCheck(str_contains($payload['messages'][0]['content'], 'strengths') && str_contains($payload['messages'][0]['content'], 'verbatim'), 'Evidence-backed report instructions missing.');

    OpenAICompatibleCurlFixture::reset(openAICompletion($evaluation));
    groqTestCheck($client->verifyClaim(['answer' => 'Fictional answer.'], 'fictional claim') === $evaluation, 'Groq evaluation JSON was not parsed.');
    $payload = json_decode(OpenAICompatibleCurlFixture::$options[CURLOPT_POSTFIELDS], true, 32, JSON_THROW_ON_ERROR);
    groqTestCheck(($payload['max_completion_tokens'] ?? null) === 400, 'Groq evaluation token budget changed.');

    foreach ([
        ['body' => '{"error":"SYNTHETIC_PRIVATE_PROVIDER_BODY"}', 'status' => 429, 'curl_error' => 0],
        ['body' => '{malformed', 'status' => 200, 'curl_error' => 0],
        ['body' => false, 'status' => 0, 'curl_error' => 28],
    ] as $failure) {
        OpenAICompatibleCurlFixture::reset($failure['body'], $failure['status'], $failure['curl_error']);
        try {
            $client->createPlan($candidate);
            throw new RuntimeException('Groq failure fixture was accepted.');
        } catch (AIUnavailableException $exception) {
            groqTestCheck(!str_contains($exception->getMessage(), $fakeKey)
                && !str_contains($exception->getMessage(), 'SYNTHETIC_PRIVATE_PROVIDER_BODY')
                && !str_contains($exception->getMessage(), 'SYNTHETIC_PRIVATE_REASONING'), 'Groq credentials/provider data escaped the safe error.');
            groqTestCheck($exception->getPrevious() === null, 'Sensitive provider exception was retained.');
        }
        groqTestCheck(OpenAICompatibleCurlFixture::$requests === 1, 'Groq failure triggered an automatic retry.');
    }

    OpenAICompatibleCurlFixture::reset(openAICompletion($plan));
    $openAI = new OpenAIClient('offline-openai-placeholder', 'gpt-4o-mini');
    groqTestCheck($openAI->providerName() === 'openai' && $openAI->createPlan($candidate) === $plan, 'Existing OpenAI provider compatibility changed.');
    groqTestCheck(OpenAICompatibleCurlFixture::$url === 'https://api.openai.com/v1/chat/completions', 'OpenAI endpoint changed.');
    groqTestCheck(OpenAICompatibleCurlFixture::$options[CURLOPT_HTTPHEADER] === [
        'Authorization: Bearer offline-openai-placeholder', 'Content-Type: application/json',
    ], 'OpenAI headers changed.');
    $openAIPayload = json_decode(OpenAICompatibleCurlFixture::$options[CURLOPT_POSTFIELDS], true, 32, JSON_THROW_ON_ERROR);
    groqTestCheck(($openAIPayload['model'] ?? null) === 'gpt-4o-mini' && ($openAIPayload['max_tokens'] ?? null) === 700
        && !isset($openAIPayload['max_completion_tokens'], $openAIPayload['reasoning_effort']), 'OpenAI request parameters changed.');

    // The Groq adapter can decode a response successfully while the plan guardrail rejects it.
    foreach ([
        [array_replace($plan, ['suggested_depth' => 'Intermediate']), 'PLAN_DEPTH_ENUM'],
        [array_replace($plan, ['focus_areas' => [['topic' => 'Testing'], ['topic' => 'APIs']]]), 'PLAN_FOCUS_ITEM_TYPE'],
        [array_replace($plan, ['rationale' => str_repeat('x', 601)]), 'PLAN_RATIONALE_LENGTH'],
    ] as [$invalidPlan, $reason]) {
        OpenAICompatibleCurlFixture::reset(openAICompletion($invalidPlan));
        $decodedPlan = $client->createPlan($candidate);
        groqTestCheck($decodedPlan === $invalidPlan, 'Decodable malformed plan was not reproduced through Groq adapter.');
        try {
            (new JobReady\Interview\DecisionValidator())->validatePlan($decodedPlan);
            throw new RuntimeException('Malformed Groq plan passed the guardrail.');
        } catch (JobReady\Http\HttpException $e) {
            groqTestCheck($e->status === 502 && ($e->diagnostics['reason'] ?? null) === $reason, 'Groq guardrail reason lost.');
        }
        groqTestCheck(OpenAICompatibleCurlFixture::$requests === 1, 'Transport itself retried a decodable plan.');
    }

    echo "Groq/OpenAI-compatible adapter tests passed (endpoint, model, JSON, bounded TLS transport, error redaction; offline only).\n";
}
