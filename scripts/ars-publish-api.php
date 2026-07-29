<?php

declare(strict_types=1);

/**
 * The ARS API half of `cwm-ars-publish`.
 *
 * scripts/ars-publish.sh still owns everything that is genuinely shell work
 * — reading the config, getting the token out of 1Password, asking `gh` about
 * the release asset, rendering the notes — and hands the result here. This
 * script turns that into the two upserts and nothing else, so the
 * create-or-update decisions live in {@see ArsPublisher} where the test suite
 * can reach them, rather than in a curl pipeline that can only be exercised
 * by publishing something.
 *
 * ## Why the environment rather than arguments
 *
 * The API token arrives in `CWM_ARS_TOKEN`. Command-line arguments are world
 * readable through `ps` on a shared machine or CI runner; the environment of
 * another user's process is not. The remaining values come the same way for
 * consistency and to avoid a second layer of shell quoting around release
 * notes that contain arbitrary Markdown.
 *
 * Everything else is read from cwm-build.config.json directly.
 *
 * Exit codes:
 *   0  published
 *   1  a required value was missing, or ARS rejected the publish
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/../src/Config/ProjectConfig.php';
require_once __DIR__ . '/../src/Http/HttpResponse.php';
require_once __DIR__ . '/../src/Http/HttpTransport.php';
require_once __DIR__ . '/../src/Http/StreamTransport.php';
require_once __DIR__ . '/../src/Release/ArsPublisher.php';

use CWM\BuildTools\Config\ProjectConfig;
use CWM\BuildTools\Http\StreamTransport;
use CWM\BuildTools\Release\ArsPublisher;

/**
 * Read a required environment variable, or fail with a message naming it.
 */
function requiredEnv(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        fwrite(\STDERR, "Error: {$name} is not set. This script is called by scripts/ars-publish.sh,\n");
        fwrite(\STDERR, "       which assembles the values it needs; it is not meant to be run by hand.\n");
        exit(1);
    }

    return $value;
}

function optionalEnv(string $name, string $default = ''): string
{
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
}

try {
    $config = ProjectConfig::loadFromCwd();

    $endpoint     = (string) $config->get('ars.endpoint', '');
    $categoryId   = (int) $config->get('ars.categoryId', 0);
    $updateStream = (int) $config->get('ars.updateStreamId', 0);
    $environments = $config->get('ars.environments');

    if ($endpoint === '' || $categoryId <= 0 || $updateStream <= 0) {
        fwrite(\STDERR, "Error: ars.endpoint, ars.categoryId and ars.updateStreamId are required in cwm-build.config.json\n");
        exit(1);
    }

    // Fails before the first request rather than after the release exists.
    ArsPublisher::assertEnvironments($environments);

    $version   = requiredEnv('CWM_ARS_VERSION');
    $zipName   = requiredEnv('CWM_ARS_ZIP_NAME');
    $token     = requiredEnv('CWM_ARS_TOKEN');
    $publisher = new ArsPublisher($endpoint, $token, new StreamTransport());

    $release = [
        'category_id'       => $categoryId,
        'version'           => $version,
        'alias'             => requiredEnv('CWM_ARS_ALIAS'),
        'maturity'          => requiredEnv('CWM_ARS_MATURITY'),
        'notes'             => optionalEnv('CWM_ARS_NOTES_HTML'),
        'created'           => optionalEnv('CWM_ARS_RELEASE_DATE'),
        'published'         => 1,
        'access'            => 1,
        'show_unauth_links' => 0,
        'redirect_unauth'   => '',
        'language'          => '*',
    ];

    $title = preg_replace('/\.zip$/', '', $zipName) ?? $zipName;

    $item = [
        'title'             => $title,
        'alias'             => $title,
        'description'       => optionalEnv('CWM_ARS_ITEM_DESCRIPTION', $title),
        'type'              => 'link',
        'url'               => requiredEnv('CWM_ARS_DOWNLOAD_URL'),
        'updatestream'      => $updateStream,
        'md5'               => optionalEnv('CWM_ARS_MD5'),
        'sha1'              => optionalEnv('CWM_ARS_SHA1'),
        'sha256'            => optionalEnv('CWM_ARS_SHA256'),
        'sha384'            => optionalEnv('CWM_ARS_SHA384'),
        'sha512'            => optionalEnv('CWM_ARS_SHA512'),
        'filesize'          => (int) optionalEnv('CWM_ARS_FILESIZE', '0'),
        'published'         => 1,
        'access'            => 1,
        'show_unauth_links' => 0,
        'redirect_unauth'   => '',
        'language'          => '*',
        'environments'      => $environments,
    ];

    $result = $publisher->publish($release, $item);

    printf(
        "Release %s (ID: %d).\n",
        $result['releaseCreated'] ? 'created' : 'updated',
        $result['releaseId']
    );
    printf(
        "Download item %s (ID: %d).\n",
        $result['itemCreated'] ? 'created' : 'updated',
        $result['itemId']
    );
    printf(
        "  ARS Release: %s/index.php?option=com_ars&view=items&release_id=%d\n",
        rtrim($endpoint, '/'),
        $result['releaseId']
    );

    exit(0);
} catch (\InvalidArgumentException $e) {
    fwrite(\STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(\STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
