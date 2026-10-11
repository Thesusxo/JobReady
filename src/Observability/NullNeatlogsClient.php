<?php
declare(strict_types=1);

namespace JobReady\Observability;

/** No-op implementation for installations without neatlogs credentials. */
final class NullNeatlogsClient implements StructuredTraceClientInterface
{
    public function recordEvent(string $name, array $attributes = []): bool
    {
        // Deliberately does not send or persist telemetry.
        return false;
    }

    public function recordTrace(array $trace): bool
    {
        // Deliberately does not send or persist telemetry.
        return false;
    }
}
