<?php
declare(strict_types=1);

namespace JobReady\Observability;

interface StructuredTraceClientInterface extends NeatlogsClientInterface
{
    /** @param array<string, mixed> $trace A root and documented nested child spans. */
    public function recordTrace(array $trace): bool;
}
