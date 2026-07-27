#!/usr/bin/env bash
#
# Shared assertions for tests/shell. Sourced by the *.test.sh files; not a
# test itself, which is why the name doesn't match the runner's glob.
#
# Plain bash rather than bats, so the suite runs anywhere `composer test`
# does without adding a dependency. If shell coverage grows much past a
# handful of files, bats earns its place (issue #52).

PASS=0
FAIL=0

# assert_equals <expected> <actual> <description>
assert_equals() {
    if [ "$1" = "$2" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "  FAIL: $3"
        echo "        expected: $1"
        echo "        actual:   $2"
    fi
}

# assert_contains <haystack> <needle> <description>
assert_contains() {
    case "$1" in
        *"$2"*) PASS=$((PASS + 1)) ;;
        *)
            FAIL=$((FAIL + 1))
            echo "  FAIL: $3"
            echo "        expected to contain: $2"
            echo "        actual:              $1"
            ;;
    esac
}

# finish — print the tally and exit non-zero if anything failed.
finish() {
    echo "  $(basename "$0"): ${PASS} passed, ${FAIL} failed"
    [ "$FAIL" -eq 0 ]
}
