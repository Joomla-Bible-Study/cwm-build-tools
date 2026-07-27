<?php

declare(strict_types=1);

/**
 * Install the built dist zip into every Joomla install marked role=test.
 *
 * Workflow: `cwm-build` (or `cwm-package`) produces the dist zip at the
 * path declared by `build.outputGlob` in cwm-build.config.json. This
 * script then deploys that zip via Joomla's `extension:install` CLI
 * into each test install configured in build.properties.
 *
 * Re-running on a test install with the extension already present
 * triggers Joomla's upgrade path (the install scriptfile's `update()`
 * method + any update.sql migrations declared in the manifest).
 *
 * This is the artifact-validation complement to `cwm-link`: where
 * cwm-link symlinks the dev source for fast iteration, install-zip
 * deploys the SHIPPED artifact for end-to-end checks (dist exclusions,
 * install scriptfile, manifest declarations, schema updates).
 */

require_once __DIR__ . '/../src/Build/DistZipResolver.php';
require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/ExtensionInstaller.php';

use CWM\BuildTools\Build\DistZipResolver;
use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Dev\ExtensionInstaller;
use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-install-zip — install the built extension zip into every test install.

WHAT IT DOES
  Finds the most recent dist zip produced by `cwm-build` (using
  `build.outputGlob` from cwm-build.config.json) and invokes Joomla's
  `extension:install --path=<zip>` against every Joomla install in
  build.properties with `role = test`.

  When the extension is already installed in the target site, Joomla
  routes the same call to the adapter's `update()` path — install
  scriptfile's `update()` runs, and any `update.sql` migrations from
  the manifest are applied. This is the right way to exercise the
  upgrade flow before cutting a release.

PREREQUISITES
  - cwm-build.config.json with `build.outputGlob` set
  - build.properties with at least one section declaring `role = test`
  - Joomla's bundled CLI at <install>/cli/joomla.php

USAGE
  composer cwm-build && composer cwm-install-zip
  composer cwm-install-zip -- --zip path/to/explicit.zip
  composer cwm-install-zip -- -v        # echo each shelled command

OPTIONS
  --zip <path>     Use this zip explicitly instead of resolving via
                   build.outputGlob. Path is resolved against the
                   project root.
  -v, --verbose    Echo each Joomla CLI invocation and its full output.

EXIT CODE
  0 when every test install accepted the zip.
  1 when no test install is configured, no zip was found, or any
  install returned a non-zero exit code.

RELATED
  composer cwm-build         # produce the dist zip
  composer cwm-verify --target test
                             # confirm #__extensions reflects the install
HELP;

    exit(0);
}

$projectRoot = getcwd() ?: '.';
$verbose     = Flags::has($argv, ['-v', '--verbose']);
$explicitZip = Flags::value($argv, '--zip');

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(\STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$testInstalls = $reader->installsFor(InstallConfig::ROLE_TEST);

if ($testInstalls === []) {
    fwrite(\STDERR, "No install configured with `role = test` in build.properties.\n");
    fwrite(\STDERR, "Add a section with `role = test` to deploy the dist zip there.\n");

    exit(1);
}

// Resolution logic lives in Build\DistZipResolver so it can be tested — #32.
$resolver = new DistZipResolver();

try {
    $zipPath = $explicitZip !== null
        ? $resolver->resolveExplicit($projectRoot, $explicitZip)
        : $resolver->resolveFromGlob($projectRoot, (string) ($config['build']['outputGlob'] ?? ''));
} catch (\RuntimeException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(1);
}

echo "Installing: {$zipPath}\n";

$installer = new ExtensionInstaller($verbose);
$failed    = 0;

foreach ($testInstalls as $install) {
    echo "\n→ {$install->id} ({$install->path})\n";

    if (!is_dir($install->path)) {
        echo "  SKIP: install path not found on disk\n";
        $failed++;

        continue;
    }

    $result = $installer->install($zipPath, $install->path);

    if ($result->ok) {
        echo "  ✓ extension:install exit 0\n";

        if (!$verbose && $result->stdout !== '') {
            echo '  ' . str_replace("\n", "\n  ", trim($result->stdout)) . "\n";
        }

        continue;
    }

    $failed++;

    echo "  ✗ extension:install exit {$result->exitCode}\n";

    if ($result->stderr !== '') {
        echo '  STDERR: ' . str_replace("\n", "\n  STDERR: ", trim($result->stderr)) . "\n";
    }

    if ($result->stdout !== '' && !$verbose) {
        echo '  STDOUT: ' . str_replace("\n", "\n  STDOUT: ", trim($result->stdout)) . "\n";
    }
}

if ($failed > 0) {
    fwrite(\STDERR, "\n{$failed} install(s) failed.\n");

    exit(1);
}

echo "\nDone. Installed into " . count($testInstalls) . " test install(s).\n";

exit(0);

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        fwrite(\STDERR, "cwm-build.config.json not found in {$projectRoot}\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(\STDERR, "cwm-build.config.json is not valid JSON.\n");

        exit(1);
    }

    return $config;
}

// (Zip resolution and flag parsing extracted to Build\DistZipResolver and
// Cli\Flags — see #32.)
