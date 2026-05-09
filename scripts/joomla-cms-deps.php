<?php

declare(strict_types=1);

/**
 * Ensure a Joomla CMS source clone exists for unit testing.
 *
 * The clone provides real Joomla classes (libraries/loader.php and friends)
 * that PHPUnit tests can require directly — the alternative is mocking
 * core, which (per the project's testing posture) gets brittle quickly.
 *
 * Resolution order for the clone path:
 *   1. --path / -p CLI argument (explicit override)
 *   2. testing.joomlaCmsPath in cwm-build.config.json (when present)
 *   3. tests.joomla_cms_path in build.properties (legacy compat with the
 *      Proclaim-style `composer install` post-install-cmd flow)
 *   4. <cwd-parent>/joomla-cms (default — sibling of the project root)
 *
 * Resolution order for the branch/tag to clone:
 *   1. --version / -v CLI argument
 *   2. testing.joomlaCmsVersion in cwm-build.config.json
 *   3. Hard-coded default 5.4.3 (known-stable framework v4.0 baseline)
 *
 * No-op when the target path already exists; otherwise runs:
 *   git clone --depth 1 --branch <version> https://github.com/joomla/joomla-cms.git <path>
 */

$projectRoot = getcwd() ?: '.';

$DEFAULT_VERSION = '5.4.3';
$REPO_URL        = 'https://github.com/joomla/joomla-cms.git';

// --- CLI parsing ---
$args = $argv;
array_shift($args);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo <<<HELP
cwm-joomla-cms-deps — clone joomla-cms source for unit testing.

WHAT IT DOES
  Clones https://github.com/joomla/joomla-cms.git at a known-stable tag into
  a sibling directory (or a configured path) so PHPUnit tests can `require`
  real Joomla classes. No-op when the target directory already exists.

PREREQUISITES
  - git on PATH

USAGE
  cwm-joomla-cms-deps                          # use config / default
  cwm-joomla-cms-deps -v 5.4.5                 # override version
  cwm-joomla-cms-deps -p ../joomla-cms-canary  # override clone path

OPTIONS
  -v, --version <tag>   Joomla CMS branch or tag to clone. Default 5.4.3.
                        Override via testing.joomlaCmsVersion in
                        cwm-build.config.json or this CLI flag.
  -p, --path <dir>      Clone target directory. Override via
                        testing.joomlaCmsPath in cwm-build.config.json,
                        tests.joomla_cms_path in build.properties, or this
                        CLI flag. Default: <cwd-parent>/joomla-cms.
  -h, --help            Show this help.

WIRING
  Typical use is from a consumer's composer.json post-install-cmd:
      "scripts": {
          "post-install-cmd": ["cwm-joomla-cms-deps"]
      }

RELATED
  cwm-joomla-install   # download a Joomla full-package zip into install paths
  cwm-joomla-latest    # print the latest stable Joomla version

HELP;
    exit(0);
}

$cliVersion = '';
$cliPath    = '';

for ($i = 0, $n = count($args); $i < $n; $i++) {
    $arg = $args[$i];

    if (($arg === '-v' || $arg === '--version') && isset($args[$i + 1])) {
        $cliVersion = $args[++$i];
        continue;
    }

    if (($arg === '-p' || $arg === '--path') && isset($args[$i + 1])) {
        $cliPath = $args[++$i];
        continue;
    }

    fwrite(STDERR, "Error: unrecognized argument '$arg'. Run with --help for usage.\n");
    exit(1);
}

// --- Resolve version ---
$version = $cliVersion;

if ($version === '') {
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (is_file($configFile)) {
        $config = json_decode((string) file_get_contents($configFile), true);

        if (is_array($config)) {
            $version = (string) ($config['testing']['joomlaCmsVersion'] ?? '');
        }
    }
}

if ($version === '') {
    $version = $DEFAULT_VERSION;
}

// --- Resolve clone path ---
$joomlaDir = $cliPath;

if ($joomlaDir === '') {
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (is_file($configFile)) {
        $config = json_decode((string) file_get_contents($configFile), true);

        if (is_array($config)) {
            $joomlaDir = (string) ($config['testing']['joomlaCmsPath'] ?? '');
        }
    }
}

if ($joomlaDir === '') {
    // Legacy compat: read tests.joomla_cms_path from build.properties so
    // existing Proclaim setups keep working without config-file changes.
    $propsFile = $projectRoot . '/build.properties';

    if (file_exists($propsFile)) {
        $lines = file($propsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
                continue;
            }

            $eq = strpos($trimmed, '=');

            if ($eq === false) {
                continue;
            }

            $key   = trim(substr($trimmed, 0, $eq));
            $value = trim(substr($trimmed, $eq + 1));

            if ($key === 'tests.joomla_cms_path' && $value !== '') {
                $joomlaDir = $value;
                break;
            }
        }
    }
}

if ($joomlaDir === '') {
    // Default to <cwd-parent>/joomla-cms. Anyone who wants a custom location
    // sets testing.joomlaCmsPath, tests.joomla_cms_path, or passes --path.
    $joomlaDir = \dirname($projectRoot) . '/joomla-cms';
}

// --- Clone if missing ---
if (!is_dir($joomlaDir)) {
    echo "  Cloning joomla-cms {$version} into {$joomlaDir}..." . PHP_EOL;

    // Array-form proc_open: no shell, no metachar interpretation. Inherit
    // stdout/stderr so the user sees git's progress output directly.
    $process = proc_open(
        ['git', 'clone', '--depth', '1', '--branch', $version, $REPO_URL, $joomlaDir],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes
    );

    if (!is_resource($process)) {
        echo "  \033[31m✗ Failed to spawn git\033[0m" . PHP_EOL;
        exit(1);
    }

    $code = proc_close($process);

    if ($code !== 0) {
        echo "  \033[31m✗ git clone failed (exit code: $code)\033[0m" . PHP_EOL;
        exit($code);
    }

    echo "  \033[32m✓ joomla-cms cloned to $joomlaDir\033[0m" . PHP_EOL;
}

// --- Verify ---
$loaderFile = rtrim($joomlaDir, '/') . '/libraries/loader.php';

if (file_exists($loaderFile)) {
    echo "  \033[32m✓ joomla-cms source ready (no composer install needed)\033[0m" . PHP_EOL;
    exit(0);
}

echo "  \033[31m✗ joomla-cms clone appears incomplete — missing libraries/loader.php\033[0m" . PHP_EOL;
exit(1);
