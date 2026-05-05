<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Uploads a release artifact to Akeeba Release System.
 *
 * Phase 1 stub. The real implementation needs to:
 *   1. Resolve the ARS category id from the configured category name (GET endpoint)
 *   2. Create a Release entity if absent for this version (POST)
 *   3. Upload the zip as an Item attached to the release (POST + multipart)
 *   4. Mark the release as Published (PATCH)
 *
 * Auth: token from 1Password CLI (`op read`) or env var ARS_API_TOKEN.
 */
final class ArsPublisher
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $category,
        private readonly string $apiToken,
    ) {
    }

    public function publish(string $version, string $zipPath): void
    {
        throw new \RuntimeException(
            'ArsPublisher::publish is a Phase 1 stub. Use scripts/ars-publish.php for now.'
        );
    }
}
