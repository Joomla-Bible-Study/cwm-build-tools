<?php

declare(strict_types=1);

/**
 * The ARS API half of `cwm-ars-reorder`.
 *
 * scripts/ars-reorder.sh owns the shell work — reading the config, getting the
 * token out of 1Password — and hands the result here, the same split as
 * scripts/ars-publish-api.php. The renumbering itself lives in
 * {@see ArsPublisher::reorderCategory} so the ordering decisions can be
 * exercised against canned API responses instead of against a live download
 * page.
 *
 * ## Why this command exists
 *
 * ARS reads "latest release" as the *lowest* `ordering` in a category, and the
 * publisher places each new release one below the current minimum. That works
 * until the minimum hits 0, because the column is unsigned. A category numbered
 * contiguously from 1 — which is what dragging a release to the top in the ARS
 * backend leaves, since Joomla's saveOrderAjax renumbers 1..N — therefore has
 * exactly one publish of headroom before the next one stops with an error.
 *
 * This spaces a category out so that stops being a concern.
 *
 * ## Why the environment rather than arguments
 *
 * The API token arrives in `CWM_ARS_TOKEN`: command-line arguments are world
 * readable through `ps` on a shared machine or CI runner, the environment of
 * another user's process is not.
 *
 * Exit codes:
 *   0  planned, or applied
 *   1  a required value was missing, or ARS rejected a write
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/../src/Http/HttpResponse.php';
require_once __DIR__ . '/../src/Http/HttpTransport.php';
require_once __DIR__ . '/../src/Http/StreamTransport.php';
require_once __DIR__ . '/../src/Release/ArsPublisher.php';

use CWM\BuildTools\Http\StreamTransport;
use CWM\BuildTools\Release\ArsPublisher;

function requiredEnv(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        fwrite(\STDERR, "Error: {$name} is not set. This script is called by scripts/ars-reorder.sh,\n");
        fwrite(\STDERR, "       which assembles the values it needs; it is not meant to be run by hand.\n");
        exit(1);
    }

    return $value;
}

try {
    $endpoint   = requiredEnv('CWM_ARS_ENDPOINT');
    $token      = requiredEnv('CWM_ARS_TOKEN');
    $categoryId = (int) requiredEnv('CWM_ARS_CATEGORY_ID');
    $stride     = (int) (getenv('CWM_ARS_STRIDE') ?: '100');
    $apply      = getenv('CWM_ARS_APPLY') === '1';

    if ($categoryId <= 0) {
        fwrite(\STDERR, "Error: CWM_ARS_CATEGORY_ID must be a positive category id.\n");
        exit(1);
    }

    $publisher = new ArsPublisher($endpoint, $token, new StreamTransport());
    $result    = $publisher->reorderCategory($categoryId, $stride, $apply);

    $changes = $result['changes'];

    printf(
        "%s ARS category %d @ %s (stride %d)\n\n",
        $apply ? 'Renumbering' : 'Planning',
        $categoryId,
        rtrim($endpoint, '/'),
        $stride
    );

    if ($changes === []) {
        print("  Already spaced as requested — nothing to change.\n");
        exit(0);
    }

    printf("  %-20s  %8s  %8s\n", 'Version', 'From', 'To');
    printf("  %-20s  %8s  %8s\n", str_repeat('-', 20), '--------', '--------');

    foreach ($changes as $change) {
        printf("  %-20s  %8d  %8d\n", substr($change['version'], 0, 20), $change['from'], $change['to']);
    }

    printf("\n  %d release(s) %s.\n", \count($changes), $apply ? 'renumbered' : 'would be renumbered');

    if (!$apply) {
        print("\n  Nothing was written. Re-run with --apply to make these changes.\n");
    } else {
        printf(
            "\n  The newest release now holds ordering %d, so it is what the Latest Releases\n"
            . "  page shows, and there is room for %d publishes below it.\n",
            $stride,
            $stride - 1
        );
    }

    exit(0);
} catch (\InvalidArgumentException $e) {
    fwrite(\STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(\STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
