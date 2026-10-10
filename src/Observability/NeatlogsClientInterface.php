<?php
declare(strict_types=1);

namespace JobReady\Observability;

interface NeatlogsClientInterface
{
    /** @param array<string, mixed> $attributes */
    public function recordEvent(string $name, array $attributes = []): bool;
}
