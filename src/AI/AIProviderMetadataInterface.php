<?php
declare(strict_types=1);

namespace JobReady\AI;

/** Optional, non-secret provider identifier for privacy-safe observability. */
interface AIProviderMetadataInterface
{
    public function providerName(): string;
}
