<?php
declare(strict_types=1);

namespace JobReady\Http;

use JobReady\AI\AIUnavailableException;
use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @var array<string, string|int> */
    public readonly array $diagnostics;

    /** @param array<string, mixed> $diagnostics */
    public function __construct(public readonly int $status, string $message, array $diagnostics = [])
    {
        parent::__construct($message);
        $this->diagnostics = AIUnavailableException::sanitizeDiagnostics($diagnostics);
    }
}
