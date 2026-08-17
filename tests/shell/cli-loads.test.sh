#!/usr/bin/env bash
#
# Every CLI in scripts/ must be able to load its own class graph.
#
# scripts/ has no autoloader — each entry point requires the src files it needs
# by path. So a class that gains a new collaborator breaks every CLI that
# loaded it, at runtime, on the one code path that reaches it. PHPUnit cannot
# see this: it autoloads through Composer, so the class graph is always
# complete under test and never under the CLI.
#
# That is not hypothetical. v1.23.0 shipped `cwm-verify` fatal with
# "Class CWM\BuildTools\Dev\TestSite not found" because ExtensionVerifier was
# refactored onto TestSite and scripts/verify.php was never told. 525 unit
# tests passed. CLAUDE.md warns about exactly this, and 1.18.0 shipped a fatal
# in cwm-package the same way.
#
# The check: require each script's declared class files in a fresh PHP process,
# then confirm every CWM class those files reference actually resolves. No
# behaviour is invoked, so it is safe to run anywhere.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
ROOT="${SCRIPT_DIR}/../.."

for script in "${ROOT}"/scripts/*.php; do
    name="$(basename "$script")"

    # Scripts that load through Composer's autoloader use a different strategy
    # and guard for the class themselves (render-notes.php checks class_exists
    # and exits with a message). They are not path-require entry points, so
    # this check does not apply to them.
    if grep -q 'vendor/autoload.php' "$script"; then
        continue
    fi

    missing=$(php -r '
        $script = $argv[1];
        $body   = (string) file_get_contents($script);

        // Load exactly what the script declares, in its own order.
        preg_match_all("~require(?:_once)?\s+__DIR__\s*\.\s*\x27([^\x27]+)\x27~", $body, $m);

        $loaded = [];

        foreach ($m[1] as $rel) {
            $path = dirname($script) . $rel;

            if (is_file($path)) {
                require_once $path;
                $loaded[] = realpath($path);
            }
        }

        // Every CWM class referenced by the script or by anything it loaded.
        $referenced = [];

        foreach (array_merge([$script], $loaded) as $file) {
            $src = (string) file_get_contents($file);

            if (preg_match_all("~\b([A-Z][A-Za-z0-9_]+)::~", $src, $r)) {
                $referenced = array_merge($referenced, $r[1]);
            }

            if (preg_match_all("~new\s+([A-Z][A-Za-z0-9_]+)\s*\(~", $src, $r)) {
                $referenced = array_merge($referenced, $r[1]);
            }
        }

        // Only judge names this package actually defines.
        $ours = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($script) . "/../src")) as $f) {
            if ($f->getExtension() !== "php") {
                continue;
            }

            if (preg_match("~^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)~m", (string) file_get_contents($f->getPathname()), $c)) {
                $ours[$c[1]] = true;
            }
        }

        $missing = [];

        foreach (array_unique($referenced) as $class) {
            if (!isset($ours[$class])) {
                continue;
            }

            foreach (["CWM\\BuildTools\\Dev\\", "CWM\\BuildTools\\Build\\", "CWM\\BuildTools\\Config\\",
                      "CWM\\BuildTools\\Release\\", "CWM\\BuildTools\\Http\\", "CWM\\BuildTools\\Cli\\"] as $ns) {
                if (class_exists($ns . $class, false) || interface_exists($ns . $class, false)
                    || trait_exists($ns . $class, false) || enum_exists($ns . $class, false)) {
                    continue 2;
                }
            }

            $missing[] = $class;
        }

        echo $missing === [] ? "OK" : "MISSING:" . implode(", ", $missing);
    ' "$script" 2>&1)

    # Only the literal sentinel passes. Empty output means the harness itself
    # died, which must not read as a clean result — that is how a check ends up
    # proving nothing.
    case "$missing" in
        OK)
            PASS=$((PASS + 1))
            ;;
        MISSING:*)
            FAIL=$((FAIL + 1))
            echo "  FAIL: ${name} references ${missing#MISSING:} but never requires it"
            ;;
        *)
            FAIL=$((FAIL + 1))
            echo "  FAIL: ${name} — loader check produced no verdict: ${missing:-(no output)}"
            ;;
    esac
done

finish
