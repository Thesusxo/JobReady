<?php
declare(strict_types=1);

namespace JobReady\Observability;

use Throwable;

final class NeatlogsClient implements StructuredTraceClientInterface
{
    private const ENDPOINT = 'https://ingest.neatlogs.com/v1/trace';
    private const USER_AGENT = 'JobReady/1.0';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const TIMEOUT_SECONDS = 5;
    private const SAFE_ROOT_ATTRIBUTES = ['neatlogs.session.id', 'neatlogs.workflow.name'];
    private const SAFE_AGENT_NAMES = ['jobready_interview_agent'];
    private const SAFE_AGENT_ROLES = ['adaptive_interview_coach'];
    private const SAFE_TASK_KEYS = [
        'persist_plan', 'plan_validation', 'decision_validation', 'claim_evaluation', 'persist_question',
        'persist_readiness_report', 'persist_answer', 'persist_claim_evaluation',
        'transaction_begin', 'transaction_commit', 'transaction_rollback',
        'ask_question', 'probe_answer', 'verify_claim', 'finish_interview',
    ];
    private const SAFE_ERROR_CODES = [
        'provider_call_failed', 'invalid_output', 'transaction_rollback', 'internal_failure',
    ];
    private ?array $lastFailureDiagnostic = null;

    public function __construct(
        private readonly ?string $writeKey,
        private readonly ?string $project,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function recordEvent(string $name, array $attributes = []): bool
    {
        $this->lastFailureDiagnostic = null;
        if ($this->writeKey === null || trim($this->writeKey) === '' || $this->project === null || trim($this->project) === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('JobReady neatlogs transport unavailable (cURL extension missing).');
            return false;
        }
        if (preg_match('/[\r\n]/', $this->writeKey) || trim($name) === '') {
            error_log('JobReady neatlogs event skipped (invalid configuration or event name).');
            return false;
        }

        try {
            $trace = ['name' => $name, 'kind' => 'WORKFLOW'];
            if ($attributes !== []) {
                $trace['metadata'] = $attributes;
            }
            $safe = $this->sanitizeTrace($trace);
            if ($safe === null) {
                error_log('JobReady neatlogs event skipped (invalid event name).');
                return false;
            }
            $safe['project'] = $this->project;
            return $this->sendTrace($safe);
        } catch (Throwable $exception) {
            // Observability must never interfere with application behavior. Do not log payloads or credentials.
            error_log('JobReady neatlogs request failed (' . get_class($exception) . ').');
            return false;
        }
    }

    /** @param array<string, mixed> $trace */
    public function recordTrace(array $trace): bool
    {
        $this->lastFailureDiagnostic = null;
        if ($this->writeKey === null || trim($this->writeKey) === '' || $this->project === null || trim($this->project) === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('JobReady neatlogs transport unavailable (cURL extension missing).');
            return false;
        }
        try {
            $safe = $this->sanitizeTrace($trace);
            if ($safe === null) {
                error_log('JobReady neatlogs trace skipped (invalid structured trace).');
                return false;
            }
            $safe['project'] = $this->project;
            return $this->sendTrace($safe);
        } catch (Throwable $exception) {
            error_log('JobReady neatlogs trace failed (' . get_class($exception) . ').');
            return false;
        }
    }

    /** @param array<string, mixed> $trace @return array<string, mixed>|null */
    private function sanitizeTrace(array $trace): ?array
    {
        $safe = $this->sanitizeNode($trace, true, 0);
        if ($safe === null || !preg_match('/^jobready\.[a-z0-9_.-]{1,80}$/', $safe['name'])) {
            return null;
        }
        return $safe;
    }

    /** @param array<string, mixed> $node @return array<string, mixed>|null */
    private function sanitizeNode(array $node, bool $root, int $depth): ?array
    {
        if ($depth > 3 || !is_string($node['name'] ?? null)
            || !preg_match('/^[a-zA-Z0-9_.-]{1,96}$/', $node['name'])) {
            return null;
        }
        $kinds = ['WORKFLOW', 'AGENT', 'LLM', 'TOOL', 'TASK', 'GUARDRAIL', 'CHAIN'];
        $kind = $root ? 'WORKFLOW' : ($node['kind'] ?? null);
        if (!in_array($kind, $kinds, true)) {
            return null;
        }
        $safe = ['name' => $node['name'], 'kind' => $kind];
        if (isset($node['provider']) && $kind === 'LLM'
            && in_array($node['provider'], ['gemini', 'openai', 'groq'], true)) {
            $safe['provider'] = $node['provider'];
        }
        if (isset($node['model']) && $kind === 'LLM' && is_string($node['model'])
            && preg_match('/^(?:gemini-[a-zA-Z0-9.-]{1,100}|gpt-[a-zA-Z0-9.-]{1,80}|openai\/gpt-oss-20b)$/D', $node['model'])) {
            $safe['model'] = $node['model'];
        }
        if (isset($node['tool_name']) && in_array($node['tool_name'], ['interview_plan', 'interview_decision', 'claim_verification'], true)) {
            $safe['tool_name'] = $node['tool_name'];
        }
        if (isset($node['status']) && in_array($node['status'], ['OK', 'ERROR'], true)) {
            $safe['status'] = $node['status'];
        }
        if (isset($node['passed']) && $kind === 'GUARDRAIL' && is_bool($node['passed'])) {
            $safe['passed'] = $node['passed'];
        }
        if (isset($node['error']) && in_array($node['error'], self::SAFE_ERROR_CODES, true)) {
            $safe['error'] = $node['error'];
            $safe['status'] = 'ERROR';
        }
        if (isset($node['duration_ms']) && (is_int($node['duration_ms']) || is_float($node['duration_ms']))
            && is_finite((float) $node['duration_ms']) && $node['duration_ms'] >= 0 && $node['duration_ms'] <= 60000) {
            $safe['duration_ms'] = $node['duration_ms'];
        }
        $attributes = [];
        if (isset($node['attributes']) && is_array($node['attributes'])) {
            foreach ($node['attributes'] as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    continue;
                }
                $allowedValues = match ($key) {
                    'neatlogs.session.id' => null,
                    'neatlogs.workflow.name' => ['jobready_interview'],
                    'neatlogs.agent.name' => self::SAFE_AGENT_NAMES,
                    'neatlogs.agent.role' => self::SAFE_AGENT_ROLES,
                    'neatlogs.task.key' => self::SAFE_TASK_KEYS,
                    default => null,
                };
                if ($key === 'neatlogs.session.id' && $root && preg_match('/^[a-f0-9-]{36}$/i', $value)) {
                    $attributes[$key] = $value;
                } elseif (is_array($allowedValues) && in_array($value, $allowedValues, true)) {
                    $attributes[$key] = $value;
                }
            }
        }
        if ($attributes !== []) {
            $safe['attributes'] = $attributes;
        }
        if ($root && isset($node['metadata']) && is_array($node['metadata'])) {
            $metadata = [];
            foreach ($node['metadata'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (in_array($key, ['interview_id', 'session_id'], true) && is_string($value)
                    && preg_match('/^[a-f0-9-]{36}$/i', $value)) {
                    $safe['attributes']['neatlogs.session.id'] = $value;
                } elseif ($key === 'answer_count' && is_int($value) && $value >= 0 && $value <= 20) {
                    $metadata[$key] = $value;
                } elseif ($key === 'action' && in_array($value, ['ask_question', 'probe_answer', 'verify_claim', 'finish_interview'], true)) {
                    $metadata[$key] = $value;
                } elseif ($key === 'outcome' && in_array($value, ['success', 'failure'], true)) {
                    $metadata[$key] = $value;
                }
            }
            if ($metadata !== []) {
                $safe['metadata'] = $metadata;
            }
        }
        if (isset($node['children'])) {
            if (!is_array($node['children']) || count($node['children']) > 32) {
                return null;
            }
            $children = [];
            foreach ($node['children'] as $child) {
                if (!is_array($child) || ($safeChild = $this->sanitizeNode($child, false, $depth + 1)) === null) {
                    return null;
                }
                $children[] = $safeChild;
            }
            $safe['children'] = $children;
        }
        return $safe;
    }

    /** @param array<string, mixed> $trace */
    private function sendTrace(array $trace): bool
    {
        if (preg_match('/[\r\n]/', $this->writeKey) || trim($trace['name'] ?? '') === '') {
            error_log('JobReady neatlogs event skipped (invalid configuration or trace name).');
            return false;
        }
        $handle = null;
        try {
            $payload = json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $handle = curl_init(self::ENDPOINT);
            if ($handle === false) {
                error_log('JobReady neatlogs request could not be initialized.');
                return false;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->writeKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $responseBody = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $errorNumber = curl_errno($handle);
            if ($errorNumber !== 0) {
                error_log('JobReady neatlogs request failed (cURL error ' . $errorNumber . ').');
                return false;
            }
            if ($status < 200 || $status >= 300) {
                if ($status === 403) {
                    $this->lastFailureDiagnostic = self::classifyForbiddenResponse(is_string($responseBody) ? $responseBody : '');
                }
                error_log('JobReady neatlogs request returned HTTP ' . $status . '.');
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            error_log('JobReady neatlogs request failed (' . get_class($exception) . ').');
            return false;
        } finally {
            if ($handle !== null && $handle !== false) {
                curl_close($handle);
            }
        }
    }

    /** @return array{category: string, code: string|null}|null */
    public function getLastFailureDiagnostic(): ?array
    {
        return $this->lastFailureDiagnostic;
    }

    /** @return array{category: string, code: string|null} */
    private static function classifyForbiddenResponse(string $body): array
    {
        // Inspect a bounded JSON response locally; never retain, return, or log its raw contents.
        $decoded = json_decode(substr($body, 0, 16384), true);
        if (!is_array($decoded)) {
            return ['category' => 'proxy_or_non_json_rejection', 'code' => null];
        }

        $code = null;
        foreach (['code', 'error_code'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $candidate = strtoupper($decoded[$key]);
                // A format regex alone could echo an opaque credential from an untrusted response.
                // Unknown codes are discarded; these fixed labels are safe even if the API omits them.
                if (in_array($candidate, [
                    'UNAUTHORIZED', 'FORBIDDEN', 'INVALID_TOKEN', 'INVALID_API_KEY',
                    'PROJECT_ACCESS_DENIED', 'PERMISSION_DENIED',
                    'AUTHENTICATION_FAILED', 'AUTHORIZATION_FAILED',
                ], true)) {
                    $code = $candidate;
                    break;
                }
            }
        }

        $descriptors = [];
        foreach (['code', 'error_code', 'error', 'message'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $descriptors[] = strtolower($decoded[$key]);
            }
        }
        $description = implode(' ', $descriptors);

        if (preg_match('/proxy|gateway|firewall|upstream|network|waf|blocked by policy/', $description)) {
            $category = 'proxy_or_network_rejection';
        } elseif (preg_match('/auth|credential|token|permission|forbidden|project_access|unauthorized/', $description)) {
            $category = 'neatlogs_auth_or_authorization';
        } else {
            $category = 'structured_http_403';
        }

        return ['category' => $category, 'code' => $code];
    }
}
