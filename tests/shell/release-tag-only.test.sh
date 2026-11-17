#!/usr/bin/env bash
#
# End-to-end tests for `release.sh --tag-only` (#176).
#
# Real runs, not dry ones, against throwaway projects whose origin is a local
# bare repository. gh, op and curl are stubbed to record any call and fail, so
# a tag-only run that reached GitHub or ARS fails here rather than publishing.
#
# What a tag-only release is for, and so what is asserted: the tag lands on the
# release commit, a planted __DEPLOY_VERSION__ is substituted, the token gate
# runs, and nothing is published. Tag-only releases used to be cut by hand, and
# the hand path skipped both the substitution and the gate.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

RELEASE="$(cd "${SCRIPT_DIR}/../../scripts" && pwd)/release.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Git sees only this test's configuration: no signing key is needed, and none
# of the developer's hooks, aliases or signing settings take part.
export GIT_CONFIG_GLOBAL="${WORK}/gitconfig"
export GIT_CONFIG_NOSYSTEM=1
git config --global user.name "Release Test"
git config --global user.email "release-test@example.invalid"
git config --global init.defaultBranch main

mkdir -p "${WORK}/bin"
for tool in gh op curl; do
    cat > "${WORK}/bin/${tool}" <<STUB
#!/usr/bin/env bash
echo "${tool} \$*" >> "${WORK}/published"
exit 1
STUB
    chmod +x "${WORK}/bin/${tool}"
done
export PATH="${WORK}/bin:${PATH}"

# assert_not_contains <haystack> <needle> <description>
assert_not_contains() {
    case "$1" in
        *"$2"*)
            FAIL=$((FAIL + 1))
            echo "  FAIL: $3"
            echo "        expected not to contain: $2"
            ;;
        *) PASS=$((PASS + 1)) ;;
    esac
}

# make_project <name> [changelog-version]
#
# A library project at 1.2.2 with a placeholder planted in src/, pushed to a
# bare origin. Its changelog carries an entry for <changelog-version>, 1.2.3 by
# default. It also has everything a full release would use to publish and
# announce, so a step that ignored --tag-only would act.
make_project() {
    local name="$1" entry="${2:-1.2.3}"
    local project="${WORK}/${name}" remote="${WORK}/${name}.git"

    git init -q --bare "$remote"
    mkdir -p "${project}/src" "${project}/build"

    cat > "${project}/cwm-build.config.json" <<'JSON'
{
    "extension": { "type": "library", "name": "lib_thing" },
    "manifests": {
        "package": "pkg_thing.xml",
        "extensions": [ { "type": "library", "path": "thing.xml" } ]
    },
    "versionTracking": { "substituteTokens": { "paths": ["src/"] } },
    "build": {
        "command": "mkdir -p build/dist && zip -qr build/dist/lib_thing-1.2.3.zip src thing.xml",
        "outputGlob": "build/dist/lib_thing-*.zip"
    },
    "github": { "owner": "nobody", "repo": "nothing", "releaseBranch": "main" },
    "ars": { "endpoint": "http://127.0.0.1:9" },
    "changelog": { "file": "build/changelog.xml" },
    "announcement": { "command": "touch announced" }
}
JSON

    for manifest in thing.xml pkg_thing.xml; do
        cat > "${project}/${manifest}" <<'XML'
<?xml version="1.0" encoding="utf-8"?>
<extension type="library" method="upgrade">
    <name>lib_thing</name>
    <version>1.2.2</version>
    <creationDate>2026-01-01</creationDate>
</extension>
XML
    done

    cat > "${project}/src/Thing.php" <<'PHP'
<?php

/**
 * @since __DEPLOY_VERSION__
 */
class Thing
{
}
PHP

    cat > "${project}/build/changelog.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<changelogs>
    <changelog>
        <element>lib_thing</element>
        <type>library</type>
        <version>${entry}</version>
        <change>
            <item>Maintenance release.</item>
        </change>
    </changelog>
</changelogs>
XML

    echo "A bullet a full release would announce." > "${project}/build/release-bullets-1.2.3.txt"
    printf 'build/dist/\nannounced\n' > "${project}/.gitignore"

    (
        cd "$project" \
            && git init -q \
            && git remote add origin "$remote" \
            && git add -A \
            && git commit -qm "initial" \
            && git push -q -u origin main
    )
}

# run_release <project> <args...> — prints the combined output and returns the
# release's exit status.
run_release() {
    local project="$1"
    shift
    (cd "${WORK}/${project}" && bash "$RELEASE" "$@" 2>&1)
}

# --- A real tag-only release ---------------------------------------------------
make_project ok
initial="$(git -C "${WORK}/ok.git" rev-parse main)"
out="$(run_release ok 1.2.3 --tag-only)"
status=$?

assert_equals "0" "$status" "a tag-only release exits 0"
assert_equals \
    "$(git -C "${WORK}/ok.git" rev-parse main)" \
    "$(git -C "${WORK}/ok.git" rev-parse 'v1.2.3^{commit}' 2>/dev/null)" \
    "the tag is on origin, on the release commit"
assert_equals "tag" "$(git -C "${WORK}/ok.git" cat-file -t v1.2.3 2>/dev/null)" "the tag is annotated"
assert_equals \
    "chore: bump version to 1.2.3" \
    "$(git -C "${WORK}/ok.git" log -1 --format=%s main)" \
    "the release commit is the version bump"
assert_equals "$initial" "$(git -C "${WORK}/ok.git" rev-parse main^)" "and it sits directly on what was released"

tagged_source="$(git -C "${WORK}/ok.git" show v1.2.3:src/Thing.php 2>/dev/null)"
assert_contains "$tagged_source" "@since 1.2.3" "the planted placeholder is substituted in the tagged source"
assert_not_contains "$tagged_source" "__DEPLOY_VERSION__" "and no placeholder is left"
assert_contains "$(git -C "${WORK}/ok.git" show v1.2.3:thing.xml 2>/dev/null)" "<version>1.2.3</version>" \
    "the manifest in the tag declares the version"

assert_contains "$out" "[gate] Scanning" "the token gate ran on the built artifact"
assert_equals "0" "$([ -e "${WORK}/published" ] && echo 1 || echo 0)" "gh, op and curl were never called"
assert_equals "0" "$([ -e "${WORK}/ok/announced" ] && echo 1 || echo 0)" \
    "nothing was announced, though a bullets file and an announcement command exist"
assert_equals "4" "$(printf '%s\n' "$out" | grep -c 'Skipped (--tag-only)')" \
    "steps 5, 7, 8 and 9 each say they were skipped"
assert_not_contains "$out" "[post-flight]" "the update stream is not checked for a release nobody is offered"
assert_contains "$out" "nothing published" "the run ends by saying nothing was published"
assert_contains "$out" "Note: tag.gpgSign is not set" "an unsigned tag is called out"

# --- A dry run describes the tag and changes nothing ---------------------------
make_project dry
before="$(git -C "${WORK}/dry.git" rev-parse main)"
out="$(run_release dry 1.2.3 --tag-only --dry-run)"
status=$?

assert_equals "0" "$status" "a tag-only dry run exits 0"
assert_contains "$out" "would run: git tag -a v1.2.3 -m v1.2.3" "the dry run describes the tag"
assert_contains "$out" "would run: git push origin v1.2.3" "and pushing it"
assert_not_contains "$out" "--force" "nothing is force-pushed"
assert_not_contains "$out" "gh release create" "no GitHub release is described"
assert_equals "$before" "$(git -C "${WORK}/dry.git" rev-parse main)" "origin is untouched"
assert_equals "" "$(git -C "${WORK}/dry.git" tag)" "and has no tag"

# --- Refused before step 1: no changelog entry ---------------------------------
make_project noentry 1.2.1
before="$(git -C "${WORK}/noentry.git" rev-parse main)"
out="$(run_release noentry 1.2.3 --tag-only)"
status=$?

assert_equals "1" "$status" "a missing changelog entry stops a tag-only release"
assert_contains "$out" "has no 1.2.3 entry" "and says which entry is missing"
assert_not_contains "$out" "[1/9]" "before anything is bumped"
assert_contains "$(cat "${WORK}/noentry/thing.xml")" "<version>1.2.2</version>" "the manifest is untouched"
assert_equals "$before" "$(git -C "${WORK}/noentry.git" rev-parse main)" "origin is untouched"

# --- Refused before step 1: the tag already exists on origin -------------------
make_project taken
(
    cd "${WORK}/taken" \
        && git tag -a v1.2.3 -m "someone else's v1.2.3" \
        && git push -q origin v1.2.3 \
        && git tag -d v1.2.3 >/dev/null
)
tagged="$(git -C "${WORK}/taken.git" rev-parse 'v1.2.3^{commit}')"
out="$(run_release taken 1.2.3 --tag-only)"
status=$?

assert_equals "1" "$status" "an existing tag stops a tag-only release"
assert_contains "$out" "already exists" "and says so"
assert_not_contains "$out" "[1/9]" "before anything is bumped"
assert_equals "$tagged" "$(git -C "${WORK}/taken.git" rev-parse 'v1.2.3^{commit}')" "the existing tag is not moved"

finish
