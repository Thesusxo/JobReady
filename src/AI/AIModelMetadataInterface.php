<?php
declare(strict_types=1);

namespace JobReady\AI;

/** Optional, non-secret model identifier for privacy-safe observability. */
interface AIModelMetadataInterface
{
    public function modelName(): string;
}
