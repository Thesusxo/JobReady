<?php
declare(strict_types=1);

namespace JobReady\AI;

use RuntimeException;

final class AIUnavailableException extends RuntimeException
{
    public const CATEGORIES = [
        'configuration', 'authentication', 'authorization', 'invalid_request',
        'model_unavailable', 'quota_or_rate_limit', 'provider_unavailable', 'transport', 'invalid_output',
    ];
    public const PROVIDER_CODES = [
        'INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'NOT_FOUND',
        'RESOURCE_EXHAUSTED', 'INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED',
    ];
    public const REASONS = [
        'API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_SERVICE_BLOCKED',
        'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED',
        'API_KEY_ANDROID_APP_BLOCKED', 'API_KEY_IOS_APP_BLOCKED',
        'SERVICE_DISABLED', 'BILLING_DISABLED', 'CONSUMER_INVALID',
        'MISSING_KEY', 'INVALID_KEY_FORMAT', 'INVALID_MODEL_FORMAT', 'CURL_UNAVAILABLE',
        'REQUEST_ENCODING_FAILED', 'TRANSPORT_INITIALIZATION_FAILED', 'RESPONSE_TOO_LARGE',
        'MALFORMED_JSON', 'INVALID_ENVELOPE', 'BLOCKED_OUTPUT', 'INCOMPLETE_OUTPUT',
        'MISSING_TEXT', 'INVALID_JSON_OBJECT', 'INVALID_PLAN', 'INVALID_DECISION', 'INVALID_EVALUATION',
        'PLAN_FOCUS_AREAS_TYPE', 'PLAN_FOCUS_AREAS_COUNT', 'PLAN_FOCUS_ITEM_TYPE',
        'PLAN_FOCUS_ITEM_LENGTH', 'PLAN_FOCUS_ITEM_UTF8', 'PLAN_DEPTH_ENUM',
        'PLAN_RATIONALE_TYPE', 'PLAN_RATIONALE_LENGTH', 'PLAN_RATIONALE_UTF8',
    ];

    /** @var array<string, string|int> */
    public readonly array $diagnostics;

    /** @param array<string, mixed> $diagnostics */
    public function __construct(string $message, array $diagnostics = [])
    {
        parent::__construct($message);
        $this->diagnostics = self::sanitizeDiagnostics($diagnostics);
    }

    /** @param array<string, mixed> $input @return array<string, string|int> */
    public static function sanitizeDiagnostics(array $input): array
    {
        $result = [];
        $allowlists = [
            'provider' => ['gemini', 'openai'],
            'category' => self::CATEGORIES,
            'stage' => ['plan', 'decision', 'claim_verification'],
            'provider_code' => self::PROVIDER_CODES,
            'reason' => self::REASONS,
        ];
        foreach ($allowlists as $field => $allowed) {
            if (isset($input[$field]) && in_array($input[$field], $allowed, true)) {
                $result[$field] = $input[$field];
            }
        }
        if (isset($input['upstream_http_status']) && is_int($input['upstream_http_status'])
            && $input['upstream_http_status'] >= 100 && $input['upstream_http_status'] <= 599) {
            $result['upstream_http_status'] = $input['upstream_http_status'];
        }
        if (isset($input['curl_error_code']) && is_int($input['curl_error_code'])
            && $input['curl_error_code'] >= 0 && $input['curl_error_code'] <= 999) {
            $result['curl_error_code'] = $input['curl_error_code'];
        }
        return $result;
    }

    public function withStage(string $stage): self
    {
        return new self($this->getMessage(), array_merge($this->diagnostics, ['stage' => $stage]));
    }
}
