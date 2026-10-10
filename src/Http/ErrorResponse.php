<?php
declare(strict_types=1);

namespace JobReady\Http;

final class ErrorResponse
{
    /** @return array<string, mixed> */
    public static function fromException(HttpException $exception, string $environment, bool $debug): array
    {
        $payload = ['error' => $exception->getMessage()];
        if ($debug && in_array($environment, ['local', 'development'], true) && $exception->diagnostics !== []) {
            $payload['diagnostics'] = $exception->diagnostics;
        }
        return $payload;
    }
}
