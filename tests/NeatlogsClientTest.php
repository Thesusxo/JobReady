<?php
declare(strict_types=1);

namespace JobReady\Observability {
    // All transport functions are intercepted before loading the real client.
    // This test never reads .env, invokes cURL's network transport, or runs the live test script.
    final class CurlFixture
    {
        public static array $options = [];
        public static array $logs = [];
        public static array $urls = [];
        public static int $requests = 0;
        public static int $closed = 0;
        public static int $status = 200;
        public static int $error = 0;
        public static string|false $body = '{"success":true,"spans":1}';
        public static bool $throw = false;

        public static function reset(int $status = 200, string|false $body = '{"success":true,"spans":1}', int $error = 0): void
        {
            self::$options = self::$logs = self::$urls = [];
            self::$requests = self::$closed = 0;
            self::$status = $status;
            self::$body = $body;
            self::$error = $error;
            self::$throw = false;
        }
    }

    function curl_init(string $url): object
    {
        CurlFixture::$urls[] = $url;
        return new \stdClass();
    }
    function curl_setopt_array(object $handle, array $options): bool
    {
        CurlFixture::$options = $options;
        return true;
    }
    function curl_exec(object $handle): string|false
    {
        CurlFixture::$requests++;
        if (CurlFixture::$throw) {
            throw new \RuntimeException('OPAQUE_FIXTURE_DETAIL');
        }
        return CurlFixture::$body;
    }
    function curl_getinfo(object $handle, int $option): int
    {
        return CurlFixture::$status;
    }
    function curl_errno(object $handle): int
    {
        return CurlFixture::$error;
    }
    function curl_close(object $handle): void
    {
        CurlFixture::$closed++;
    }
    function error_log(string $message): bool
    {
        CurlFixture::$logs[] = $message;
        return true;
    }
}

namespace {
    use JobReady\Observability\CurlFixture;
    use JobReady\Observability\NeatlogsClient;

    require dirname(__DIR__) . '/config/autoload.php';

    function traceCheck(bool $condition, string $description): void
    {
        if (!$condition) {
            throw new \RuntimeException($description);
        }
    }

    CurlFixture::reset();
    $offlineCredentialFixture = 'offline-placeholder-only';
    $client = new NeatlogsClient($offlineCredentialFixture, "Fictional team's project");
    traceCheck($client->recordEvent('jobready.integration_test'), 'Successful trace fixture was rejected.');
    traceCheck(CurlFixture::$requests === 1 && CurlFixture::$closed === 1, 'Trace request was retried or its handle not closed.');
    traceCheck(CurlFixture::$urls === ['https://ingest.neatlogs.com/v1/trace'], 'Ingest endpoint changed.');
    $options = CurlFixture::$options;
    traceCheck($options[CURLOPT_USERAGENT] === 'JobReady/1.0', 'Explicit standards-compliant User-Agent is missing.');
    traceCheck($options[CURLOPT_HTTP_VERSION] === CURL_HTTP_VERSION_1_1, 'HTTP/1.1 compatibility is not selected.');
    traceCheck($options[CURLOPT_POST] === true, 'Trace must be a POST.');
    traceCheck($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS verification was weakened.');
    traceCheck($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_FOLLOWLOCATION] === false, 'HTTPS-only/redirect protections were changed.');
    traceCheck($options[CURLOPT_CONNECTTIMEOUT] === 2 && $options[CURLOPT_TIMEOUT] === 5, 'Bounded timeouts changed.');
    traceCheck(!isset($options[CURLOPT_PROXY]) && !isset($options[CURLOPT_NOPROXY]), 'Proxy policy was overridden.');
    traceCheck($options[CURLOPT_HTTPHEADER] === [
        'Authorization: ' . 'Bearer ' . $offlineCredentialFixture, 'Content-Type: application/json', 'Accept: application/json',
    ], 'Authentication or content headers changed.');
    $trace = json_decode($options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    traceCheck($trace === ['name' => 'jobready.integration_test', 'kind' => 'WORKFLOW', 'project' => "Fictional team's project"], 'Synthetic payload structure changed.');
    traceCheck(!str_contains($options[CURLOPT_POSTFIELDS], 'offline-placeholder-only'), 'Authentication placeholder leaked into the payload.');
    traceCheck($client->getLastFailureDiagnostic() === null && CurlFixture::$logs === [], 'Success produced failure diagnostics.');

    CurlFixture::reset();
    $client->recordTrace([
        'name' => 'jobready.interview.turn', 'kind' => 'WORKFLOW', 'status' => 'OK', 'duration_ms' => 12.5,
        'attributes' => [
            'neatlogs.session.id' => '123e4567-e89b-42d3-a456-426614174000',
            'neatlogs.workflow.name' => 'jobready_interview',
            'untrusted.extra' => 'OPAQUE_FIXTURE_DETAIL',
        ],
        'children' => [[
            'name' => 'select_probe_answer', 'kind' => 'AGENT', 'duration_ms' => 10,
            'attributes' => [
                'neatlogs.agent.name' => 'jobready_interview_agent',
                'neatlogs.agent.role' => 'adaptive_interview_coach',
                'neatlogs.agent.input' => 'OPAQUE_FICTIONAL_ANSWER',
            ],
            'children' => [
                ['name' => 'decide_next_interview_action', 'kind' => 'LLM', 'provider' => 'groq', 'model' => 'openai/gpt-oss-20b', 'duration_ms' => 7],
                ['name' => 'validate_agent_decision', 'kind' => 'GUARDRAIL', 'passed' => true, 'duration_ms' => 0.2],
                ['name' => 'persist_selected_interview_question', 'kind' => 'TASK', 'attributes' => ['neatlogs.task.key' => 'probe_answer'], 'duration_ms' => 0.1],
            ],
        ]],
        'input' => 'OPAQUE_CV_AND_ANSWER', 'output' => 'OPAQUE_GENERATED_QUESTION',
    ]);
    $trace = json_decode(CurlFixture::$options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    traceCheck($trace['name'] === 'jobready.interview.turn' && $trace['project'] === "Fictional team's project", 'Structured root/project malformed.');
    traceCheck($trace['attributes'] === [
        'neatlogs.session.id' => '123e4567-e89b-42d3-a456-426614174000',
        'neatlogs.workflow.name' => 'jobready_interview',
    ], 'Session grouping or root attribute allowlist failed.');
    traceCheck($trace['children'][0]['kind'] === 'AGENT', 'Nested agent span missing.');
    traceCheck(array_column($trace['children'][0]['children'], 'kind') === ['LLM', 'GUARDRAIL', 'TASK'], 'Nested LLM/validation/task steps were lost.');
    traceCheck($trace['children'][0]['children'][0]['provider'] === 'groq'
        && $trace['children'][0]['children'][0]['model'] === 'openai/gpt-oss-20b', 'Safe provider/model identifiers missing.');
    traceCheck(!str_contains(CurlFixture::$options[CURLOPT_POSTFIELDS], 'OPAQUE_'), 'Sensitive inputs/outputs were transmitted.');
    traceCheck(CurlFixture::$requests === 1, 'Structured trace submit retried.');
    CurlFixture::reset();
    $client->recordTrace(['name' => 'jobready.bad', 'children' => [['name' => 'bad span', 'kind' => 'SHELL', 'input' => 'OPAQUE']] ]);
    traceCheck(CurlFixture::$requests === 0, 'Invalid or unsafe span reached transport.');

    CurlFixture::reset();
    traceCheck($client->recordEvent('jobready.interview.turn', ['answer_count' => 4, 'action' => 'probe_answer']), 'Safe turn-metadata fixture failed.');
    $trace = json_decode(CurlFixture::$options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    traceCheck($trace['metadata'] === ['answer_count' => 4, 'action' => 'probe_answer'], 'Turn metadata serialization changed.');

    CurlFixture::reset();
    $client->recordEvent('jobready.interview.turn', [
        'session_id' => '123e4567-e89b-42d3-a456-426614174000',
        'answer_count' => 4,
        'action' => 'probe_answer',
        'answer' => 'OPAQUE_CANDIDATE_ANSWER',
        'candidate_answer' => 'OPAQUE_CANDIDATE_ANSWER',
        'prompt' => 'OPAQUE_PROMPT',
    ]);
    $trace = json_decode(CurlFixture::$options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    traceCheck($trace['attributes']['neatlogs.session.id'] === '123e4567-e89b-42d3-a456-426614174000', 'Legacy session ID was not mapped to its canonical grouping key.');
    traceCheck($trace['metadata'] === ['answer_count' => 4, 'action' => 'probe_answer'], 'Legacy event included non-allowlisted metadata.');
    traceCheck(!str_contains(CurlFixture::$options[CURLOPT_POSTFIELDS], 'OPAQUE_'), 'Legacy event transmitted candidate or prompt data.');

    foreach ([
        ['<html>OPAQUE_FIXTURE_DETAIL</html>', 'proxy_or_non_json_rejection', null],
        ['{"error":"Forbidden","code":"FORBIDDEN"}', 'neatlogs_auth_or_authorization', 'FORBIDDEN'],
        ['{"message":"blocked by policy","error_code":"OPAQUE_FIXTURE_DETAIL"}', 'proxy_or_network_rejection', null],
        ['{"code":"OPAQUE_FIXTURE_DETAIL"}', 'structured_http_403', null],
        ['{"error":"Forbidden","code":{"unknown":"OPAQUE_FIXTURE_DETAIL"}}', 'neatlogs_auth_or_authorization', null],
    ] as [$body, $category, $code]) {
        CurlFixture::reset(403, $body);
        traceCheck($client->recordEvent('jobready.integration_test') === false, '403 fixture did not fail safely.');
        traceCheck($client->getLastFailureDiagnostic() === ['category' => $category, 'code' => $code], 'Unsafe or incorrect 403 diagnostics.');
        traceCheck(CurlFixture::$requests === 1, 'Forbidden request was retried.');
        traceCheck(!str_contains(json_encode(CurlFixture::$logs), 'OPAQUE_FIXTURE_DETAIL'), 'Raw response contents escaped into logs.');
    }
    foreach ([429, 500, 503] as $status) {
        CurlFixture::reset($status, 'OPAQUE_FIXTURE_DETAIL');
        traceCheck($client->recordEvent('jobready.integration_test') === false, 'HTTP failure fixture escaped best-effort handling.');
        traceCheck(CurlFixture::$requests === 1 && $client->getLastFailureDiagnostic() === null, 'HTTP failure was retried or retained unsafe diagnostics.');
    }
    CurlFixture::reset(0, false, 28);
    traceCheck($client->recordEvent('jobready.integration_test') === false, 'Transport timeout did not fail safely.');
    traceCheck(CurlFixture::$requests === 1 && CurlFixture::$closed === 1, 'Timeout triggered a retry or leaked its handle.');

    CurlFixture::reset();
    CurlFixture::$throw = true;
    traceCheck($client->recordEvent('jobready.integration_test') === false, 'Unexpected transport exception escaped.');
    traceCheck(!str_contains(json_encode(CurlFixture::$logs), 'OPAQUE_FIXTURE_DETAIL'), 'Exception text escaped into logs.');

    CurlFixture::reset();
    traceCheck((new NeatlogsClient(null, 'Fictional project'))->recordEvent('jobready.integration_test') === false, 'Missing credentials did not remain a no-op.');
    traceCheck((new NeatlogsClient('offline-placeholder-only', null))->recordEvent('jobready.integration_test') === false, 'Missing project did not remain a no-op.');
    traceCheck((new NeatlogsClient("offline\r\nplaceholder", 'Fictional project'))->recordEvent('jobready.integration_test') === false, 'Invalid header value was accepted.');
    traceCheck(CurlFixture::$requests === 0, 'Invalid configuration reached transport.');

    CurlFixture::reset();
    traceCheck($client->recordEvent('jobready.integration_test', ['invalid_utf8' => "\xFF", 'answer' => 'OPAQUE_CANDIDATE_ANSWER']), 'Safe event should not fail on discarded metadata.');
    $trace = json_decode(CurlFixture::$options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    traceCheck(!isset($trace['metadata']) && !str_contains(CurlFixture::$options[CURLOPT_POSTFIELDS], 'OPAQUE_'), 'Unrecognized metadata was serialized.');
    traceCheck(!str_contains(json_encode(CurlFixture::$logs), 'offline-placeholder-only'), 'Authentication placeholder escaped into logs.');
    echo "Neatlogs offline fixtures passed (request identity, HTTP/1.1, HTTPS/TLS protections, payloads, safe diagnostics, best-effort failures).\n";
}
