<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Downloads and extracts a Joomla full-package release into a target directory.
 *
 * Uses the joomla/joomla-cms GitHub releases by default. Callers can pass an
 * arbitrary URL when (e.g.) testing nightlies or release candidates.
 */
final class JoomlaInstaller
{
    private const RELEASE_URL_TEMPLATE
        = 'https://github.com/joomla/joomla-cms/releases/download/%s/Joomla_%s-Stable-Full_Package.zip';

    private const LATEST_RELEASE_API
        = 'https://api.github.com/repos/joomla/joomla-cms/releases/latest';

    public function __construct(private readonly string $userAgent = 'cwm-build-tools')
    {
    }

    /**
     * Returns the latest stable Joomla version tag and publish timestamp.
     *
     * @return array{tag: string, publishedAt: string}
     */
    public function latest(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . $this->userAgent,
            ],
        ]);

        $json = @file_get_contents(self::LATEST_RELEASE_API, false, $context);

        if ($json === false) {
            throw new \RuntimeException('Failed to query GitHub releases API.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new \RuntimeException('Unexpected response from GitHub releases API.');
        }

        return [
            'tag'         => (string) $data['tag_name'],
            'publishedAt' => (string) ($data['published_at'] ?? ''),
        ];
    }

    public function install(string $version, string $targetPath, ?string $url = null): void
    {
        if (is_dir($targetPath) && (new \FilesystemIterator($targetPath))->valid()) {
            throw new \RuntimeException(
                "Target directory is not empty: {$targetPath}. Remove it first or pick another path."
            );
        }

        if (!is_dir($targetPath) && !mkdir($targetPath, 0o777, true) && !is_dir($targetPath)) {
            throw new \RuntimeException("Failed to create target directory: {$targetPath}");
        }

        $url      = $url ?? sprintf(self::RELEASE_URL_TEMPLATE, $version, $version);
        $download = tempnam(sys_get_temp_dir(), 'cwm-joomla-') . '.zip';

        echo "Downloading {$url}\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . $this->userAgent,
                'follow_location' => 1,
                'max_redirects'   => 5,
            ],
        ]);

        $bytes = @file_put_contents($download, fopen($url, 'rb', false, $context));

        if ($bytes === false || $bytes === 0) {
            @unlink($download);

            throw new \RuntimeException("Download failed for {$url}");
        }

        $zip = new \ZipArchive();

        if ($zip->open($download) !== true) {
            @unlink($download);

            throw new \RuntimeException("Could not open downloaded zip {$download}");
        }

        if (!$zip->extractTo($targetPath)) {
            $zip->close();
            @unlink($download);

            throw new \RuntimeException("Failed to extract zip into {$targetPath}");
        }

        $zip->close();
        @unlink($download);

        echo "Installed Joomla {$version} to {$targetPath}\n";
    }
}
