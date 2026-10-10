<?php
declare(strict_types=1);

namespace JobReady\Observability;

use Throwable;

final class NeatlogsClient implements NeatlogsClientInterface
{
    private const ENDPOINT = 'https://ingest.neatlogs.com/v1/trace';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const TIMEOUT_SECONDS = 5;
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
            $trace = ['name' => $name, 'project' => $this->project];
            if ($attributes !== []) {
                $trace['metadata'] = $attributes;
            }
            $payload = json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $handle = curl_init(self::ENDPOINT);
            if ($handle === false) {
                error_log('JobReady neatlogs request could not be initialized.');
                return false;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
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
            curl_close($handle);

            if ($errorNumber !== 0) {
                error_log('JobReady neatlogs request failed (cURL error ' . $errorNumber . ').');
                return false;
            } elseif ($status < 200 || $status >= 300) {
                if ($status === 403) {
                    $this->lastFailureDiagnostic = self::classifyForbiddenResponse(
                        is_string($responseBody) ? $responseBody : '',
                    );
                }
                error_log('JobReady neatlogs request returned HTTP ' . $status . '.');
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            // Observability must never interfere with application behavior. Do not log payloads or credentials.
            error_log('JobReady neatlogs request failed (' . get_class($exception) . ').');
            return false;
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
                if (preg_match('/^[A-Z][A-Z0-9_]{0,47}$/', $candidate)) {
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
