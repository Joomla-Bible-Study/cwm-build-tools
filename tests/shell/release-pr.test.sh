#!/usr/bin/env bash
#
# End-to-end tests for `release.sh --pr`.
#
# Real runs against throwaway projects whose origin is a local bare
# repository. The origin stands in for GitHub's rulesets with a pre-receive
# hook: main and development change only through a merged pull request, or, for
# a release branch, by an admin fast-forward to a commit already on the
# development branch. So a run that pushed a release commit straight to a
# protected branch fails here, which is the claim the mode exists to make.
#
# gh is a fake backed by those repositories. Its pull requests are real
# branches, `gh pr merge` really squash-merges into the origin, and
# `gh release create` really tags it, so what is asserted is the state of the
# origin rather than a log of intentions.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

RELEASE="$(cd "${SCRIPT_DIR}/../../scripts" && pwd)/release.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export GIT_CONFIG_GLOBAL="${WORK}/gitconfig"
export GIT_CONFIG_NOSYSTEM=1
git config --global user.name "Release Test"
git config --global user.email "release-test@example.invalid"
git config --global init.defaultBranch main

# Pull requests are polled without sleeping, and checks report at once.
export CWM_PR_POLL_SECONDS=0 CWM_PR_GRACE_SECONDS=0 CWM_PR_TIMEOUT_SECONDS=20

mkdir -p "${WORK}/bin"
for tool in op curl; do
    cat > "${WORK}/bin/${tool}" <<STUB
#!/usr/bin/env bash
echo "${tool} \$*" >> "${WORK}/unexpected"
exit 1
STUB
    chmod +x "${WORK}/bin/${tool}"
done

# --- The fake gh ------------------------------------------------------------------
# Per project <name>: origin ${WORK}/<name>.git, pull requests in <name>.prs/<n>/,
# check results in <name>.checks (pass, fail or none; pass by default), branch
# rules in <name>.rules/<branch>, releases logged to <name>.released. A file
# <name>.land makes the next `pr view` land another commit on the base first.
cat > "${WORK}/bin/gh" <<'FAKE'
#!/usr/bin/env bash
W="__WORK__"
echo "gh $*" >> "${W}/gh.log"

if [ "${1:-}" = "api" ]; then
    case "$2" in
        repos/*/rules/branches/*)
            name="$(echo "$2" | cut -d/ -f3)"
            branch="${2##*/rules/branches/}"
            if [ -f "${W}/${name}.rules/${branch}" ]; then cat "${W}/${name}.rules/${branch}"; else echo "[]"; fi
            exit 0
            ;;
        repos/*/releases/generate-notes)
            echo "* Generated notes."
            exit 0
            ;;
    esac
    echo "gh $*" >> "${W}/unexpected"
    exit 1
fi

cmd="${1:-} ${2:-}"
shift 2
repo="" base="" head="" title="" target="" match=""
positional=()
while [ $# -gt 0 ]; do
    case "$1" in
        --repo|-R) repo="$2"; shift 2 ;;
        --base) base="$2"; shift 2 ;;
        --head) head="$2"; shift 2 ;;
        --title) title="$2"; shift 2 ;;
        --target) target="$2"; shift 2 ;;
        --match-head-commit) match="$2"; shift 2 ;;
        --body|--notes|--json|--state|--jq) shift 2 ;;
        --squash|--prerelease) shift ;;
        *) positional+=("$1"); shift ;;
    esac
done
name="${repo#*/}"
origin="${W}/${name}.git"
prs="${W}/${name}.prs"
mkdir -p "$prs"

checks_json() {
    case "$(cat "${W}/${name}.checks" 2>/dev/null || echo pass)" in
        fail) echo '[{"__typename":"CheckRun","name":"tests","status":"COMPLETED","conclusion":"FAILURE"}]' ;;
        none) echo '[]' ;;
        *)    echo '[{"__typename":"CheckRun","name":"tests","status":"COMPLETED","conclusion":"SUCCESS"}]' ;;
    esac
}

head_sha() {
    local d="$1"
    git -C "$origin" rev-parse -q --verify "refs/heads/$(cat "${d}/head")" 2>/dev/null || cat "${d}/merged_head" 2>/dev/null
}

# commit_on <branch> <file> <subject>: a change reaching <branch> the way a
# merged pull request does.
commit_on() {
    local tmp
    tmp="$(mktemp -d)"
    git clone -q "$origin" "${tmp}/c" \
        && git -C "${tmp}/c" checkout -q "$1" \
        && echo "$2" > "${tmp}/c/$2" \
        && git -C "${tmp}/c" add -A \
        && git -C "${tmp}/c" commit -qm "$3" \
        && git -C "${tmp}/c" push -q -o cwm-test-merge origin "$1"
    rm -rf "$tmp"
}

case "$cmd" in
    "pr create")
        n=$(( $(find "$prs" -mindepth 1 -maxdepth 1 -type d | wc -l) + 1 ))
        mkdir -p "${prs}/${n}"
        echo "$base" > "${prs}/${n}/base"
        echo "$head" > "${prs}/${n}/head"
        echo "$title" > "${prs}/${n}/title"
        echo OPEN > "${prs}/${n}/state"
        echo "https://github.com/${repo}/pull/${n}"
        ;;
    "pr view")
        d="${prs}/${positional[0]}"
        if [ -f "${W}/${name}.land" ]; then
            rm -f "${W}/${name}.land"
            commit_on "$(cat "${d}/base")" other.txt "feat: something else"
        fi
        state="$(cat "${d}/state")"
        merge='null'
        [ -f "${d}/merge" ] && merge="{\"oid\":\"$(cat "${d}/merge")\"}"
        status=CLEAN
        [ "$(cat "${W}/${name}.checks" 2>/dev/null)" = "fail" ] && status=UNSTABLE
        printf '{"state":"%s","headRefOid":"%s","mergeStateStatus":"%s","statusCheckRollup":%s,"mergeCommit":%s}\n' \
            "$state" "$(head_sha "$d")" "$status" "$(checks_json)" "$merge"
        ;;
    "pr list")
        first=1
        printf '['
        for d in "${prs}"/*/; do
            [ -d "$d" ] || continue
            [ "$(cat "${d}head")" = "$head" ] || continue
            [ "$first" = 1 ] || printf ','
            first=0
            printf '{"number":%s,"state":"%s","headRefOid":"%s"}' "$(basename "$d")" "$(cat "${d}state")" "$(head_sha "${d%/}")"
        done
        printf ']\n'
        ;;
    "pr merge")
        n="${positional[0]}"
        d="${prs}/${n}"
        [ "$(cat "${d}/state")" = OPEN ] || { echo "not open" >&2; exit 1; }
        sha="$(head_sha "$d")"
        [ -z "$match" ] || [ "$match" = "$sha" ] || { echo "head moved" >&2; exit 1; }
        tmp="$(mktemp -d)"
        git clone -q "$origin" "${tmp}/c" \
            && git -C "${tmp}/c" checkout -q "$(cat "${d}/base")" \
            && git -C "${tmp}/c" merge -q --squash "origin/$(cat "${d}/head")" \
            && git -C "${tmp}/c" commit -qm "$(cat "${d}/title") (#${n})" \
            && git -C "${tmp}/c" push -q -o cwm-test-merge origin "$(cat "${d}/base")" \
            || { rm -rf "$tmp"; exit 1; }
        git -C "${tmp}/c" rev-parse HEAD > "${d}/merge"
        echo "$sha" > "${d}/merged_head"
        echo MERGED > "${d}/state"
        rm -rf "$tmp"
        ;;
    "release create")
        echo "release create ${positional[*]} --target ${target}" >> "${W}/${name}.released"
        git -C "$origin" tag "${positional[0]}" "$target"
        ;;
    *)
        echo "gh ${cmd} $*" >> "${W}/unexpected"
        exit 1
        ;;
esac
FAKE
sed -i.bak "s|__WORK__|${WORK}|" "${WORK}/bin/gh" && rm -f "${WORK}/bin/gh.bak"
chmod +x "${WORK}/bin/gh"
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

# make_project <name> [dual]
#
# A library at 1.2.2 with a placeholder planted in src/ and a versions.json,
# pushed to a bare origin that only takes protected changes by pull request.
# With "dual", the release commit belongs on development and main is the
# release branch, moved by admin fast-forward only; the checkout starts on
# development. Then the operator's part: this version's changelog entry and
# notes, written and left uncommitted.
make_project() {
    local name="$1" dual="${2:-}"
    local project="${WORK}/${name}" origin="${WORK}/${name}.git"
    local branches='"releaseBranch": "main"'
    [ "$dual" = "dual" ] && branches='"releaseBranch": "main", "developmentBranch": "development"'

    git init -q --bare "$origin"
    git -C "$origin" config receive.advertisePushOptions true
    mkdir -p "${project}/src" "${project}/build"

    cat > "${project}/cwm-build.config.json" <<JSON
{
    "extension": { "type": "library", "name": "lib_thing" },
    "manifests": { "extensions": [ { "type": "library", "path": "thing.xml" } ] },
    "versionTracking": { "versionsJson": "versions.json", "substituteTokens": { "paths": ["src/"] } },
    "build": {
        "command": "mkdir -p build/dist && zip -qr build/dist/lib_thing-1.2.3.zip src thing.xml",
        "outputGlob": "build/dist/lib_thing-*.zip"
    },
    "github": { "owner": "nobody", "repo": "${name}", ${branches} },
    "changelog": { "file": "build/changelog.xml" },
    "release": { "notesFile": "build/release-notes-{version}.md" }
}
JSON

    cat > "${project}/thing.xml" <<'XML'
<?xml version="1.0" encoding="utf-8"?>
<extension type="library" method="upgrade">
    <name>lib_thing</name>
    <version>1.2.2</version>
    <creationDate>2026-01-01</creationDate>
</extension>
XML

    cat > "${project}/src/Thing.php" <<'PHP'
<?php

/**
 * @since __DEPLOY_VERSION__
 */
class Thing
{
}
PHP

    cat > "${project}/versions.json" <<'JSON'
{
    "current": { "version": "1.2.2" },
    "next": { "patch": "1.2.3", "minor": "1.3.0", "major": "2.0.0" },
    "active_development": { "version": "1.2.3" }
}
JSON

    write_changelog "$project" ""
    printf 'build/dist/\n' > "${project}/.gitignore"

    (
        cd "$project" \
            && git init -q \
            && git remote add origin "$origin" \
            && git add -A \
            && git commit -qm "initial" \
            && git push -q -u origin main
    )
    if [ "$dual" = "dual" ]; then
        (cd "$project" && git switch -q -c development && git push -q -u origin development)
        touch "${origin}/ff-only.main"
        mkdir -p "${WORK}/${name}.rules"
        echo '[{"type":"deletion"},{"type":"non_fast_forward"},{"type":"update"}]' > "${WORK}/${name}.rules/main"
    fi

    # From here on the origin behaves like a protected repository.
    cat > "${origin}/hooks/pre-receive" <<'HOOK'
#!/usr/bin/env bash
merge=0
i=0
while [ "$i" -lt "${GIT_PUSH_OPTION_COUNT:-0}" ]; do
    v="GIT_PUSH_OPTION_${i}"
    [ "${!v}" = "cwm-test-merge" ] && merge=1
    i=$((i + 1))
done
while read -r old new ref; do
    case "$ref" in
        refs/heads/main|refs/heads/development)
            [ "$merge" = 1 ] && continue
            b="${ref#refs/heads/}"
            if [ -f "ff-only.${b}" ] \
                && git merge-base --is-ancestor "$old" "$new" \
                && git merge-base --is-ancestor "$new" refs/heads/development; then
                continue
            fi
            echo "protected: ${ref} changes only through a pull request" >&2
            exit 1
            ;;
    esac
done
exit 0
HOOK
    chmod +x "${origin}/hooks/pre-receive"

    # The operator's part, left uncommitted on purpose.
    write_changelog "$project" "1.2.3"
    echo "Fixes a thing." > "${project}/build/release-notes-1.2.3.md"
}

# write_changelog <project> <newest-version or "">
write_changelog() {
    local entry=""
    if [ -n "$2" ]; then
        entry="    <changelog>
        <element>lib_thing</element>
        <type>library</type>
        <version>$2</version>
        <fix>
            <item>Fixes a thing.</item>
        </fix>
    </changelog>
"
    fi
    cat > "$1/build/changelog.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<changelogs>
${entry}    <changelog>
        <element>lib_thing</element>
        <type>library</type>
        <version>1.2.2</version>
        <change>
            <item>An earlier release.</item>
        </change>
    </changelog>
</changelogs>
XML
}

# run_release <project> <args...>: the combined output; returns the exit status.
run_release() {
    local project="$1"
    shift
    (cd "${WORK}/${project}" && bash "$RELEASE" "$@" 2>&1)
}

# origin_log <project> <git log arguments...>: subjects from the project's origin.
origin_log() {
    local name="$1"
    shift
    git -C "${WORK}/${name}.git" log --format=%s "$@"
}

# --- The harness: the origin really refuses a direct push ------------------------------
# Every case below leans on this. If the hook let a push through, a run that
# pushed its release commit straight to main would pass them all.
make_project guard dual
(cd "${WORK}/guard" && git add -A && git commit -qm "direct" && git push -q origin development) >/dev/null 2>&1
assert_equals "1" "$?" "the origin refuses a direct push to development"
(cd "${WORK}/guard" && git push -q origin HEAD:main) >/dev/null 2>&1
assert_equals "1" "$?" "and a push to main of a commit development doesn't have"

# --- A single-branch release, start to finish ---------------------------------------
make_project single
out="$(run_release single 1.2.3 --pr)"
status=$?

assert_equals "0" "$status" "a --pr release exits 0"
cut="$(git -C "${WORK}/single.git" log --format=%H --grep='^chore(release): cut v1.2.3 (#1)$' main)"
assert_equals "chore(release): cut v1.2.3 (#1)" "$(git -C "${WORK}/single.git" log -1 --format=%s "${cut:-HEAD}")" \
    "the release commit reached main as pull request #1"
assert_equals "$cut" "$(git -C "${WORK}/single.git" rev-parse 'v1.2.3^{commit}' 2>/dev/null)" \
    "the tag is on that merged commit"
assert_contains "$(cat "${WORK}/single.released" 2>/dev/null)" "--target ${cut}" \
    "the GitHub release names the exact commit, not the branch"
assert_contains "$(git -C "${WORK}/single.git" show v1.2.3:thing.xml 2>/dev/null)" "<version>1.2.3</version>" \
    "the tagged manifest declares 1.2.3"
assert_contains "$(git -C "${WORK}/single.git" show v1.2.3:src/Thing.php 2>/dev/null)" "@since 1.2.3" \
    "the placeholder is substituted in the tag"
assert_contains "$(git -C "${WORK}/single.git" show v1.2.3:build/changelog.xml 2>/dev/null)" "<version>1.2.3</version>" \
    "the uncommitted changelog entry went into the release commit"
assert_equals "Fixes a thing." "$(git -C "${WORK}/single.git" show v1.2.3:build/release-notes-1.2.3.md 2>/dev/null)" \
    "and so did the notes"
assert_equals "chore: update versions.json for v1.2.3 release (#2)" "$(origin_log single -1 main | head -1)" \
    "versions.json followed as pull request #2"
assert_contains "$(git -C "${WORK}/single.git" show main:versions.json)" '"version": "1.2.3"' \
    "recording 1.2.3 as current"
assert_equals "" "$(git -C "${WORK}/single.git" branch --list 'chore/*')" "both pull request branches are deleted"
assert_contains "$out" "Rebuilding from the merged release commit" "the merged commit was rebuilt"
assert_contains "$out" "Release 1.2.3 complete!" "the run ends complete"
assert_equals "main" "$(git -C "${WORK}/single" branch --show-current)" "the checkout is handed back on main"
assert_equals "$(git -C "${WORK}/single.git" rev-parse main)" "$(git -C "${WORK}/single" rev-parse HEAD)" \
    "at origin's tip"
assert_equals "" "$(git -C "${WORK}/single" status --porcelain)" "and clean"
assert_equals "0" "$([ -s "${WORK}/unexpected" ] && echo 1 || echo 0)" "no unexpected gh, op or curl call"

# --- A project with a development branch ---------------------------------------------
make_project dual dual
out="$(run_release dual 1.2.3 --pr)"
status=$?

assert_equals "0" "$status" "a --pr release with a development branch exits 0"
cut="$(git -C "${WORK}/dual.git" log --format=%H --grep='^chore(release): cut v1.2.3 (#1)$' development)"
assert_equals "chore(release): cut v1.2.3 (#1)" "$(git -C "${WORK}/dual.git" log -1 --format=%s "${cut:-HEAD}")" \
    "the release commit went into development by pull request"
assert_equals "$cut" "$(git -C "${WORK}/dual.git" rev-parse main)" "main was fast-forwarded to it"
assert_equals "$cut" "$(git -C "${WORK}/dual.git" rev-parse 'v1.2.3^{commit}' 2>/dev/null)" "and tagged there"
assert_equals "chore: update versions.json for v1.2.3 release (#2)" "$(origin_log dual -1 development | head -1)" \
    "versions.json followed into development"
assert_contains "$out" "Fast-forwarding main" "the fast-forward is reported"
assert_equals "development" "$(git -C "${WORK}/dual" branch --show-current)" "the checkout is handed back on development"

# --- Failing checks: nothing is published ----------------------------------------------
make_project failing
echo fail > "${WORK}/failing.checks"
before="$(git -C "${WORK}/failing.git" rev-parse main)"
out="$(run_release failing 1.2.3 --pr)"
status=$?

assert_equals "1" "$status" "failing checks stop the release"
assert_contains "$out" "nothing has been tagged or published" "and it says nothing went out"
assert_equals "$before" "$(git -C "${WORK}/failing.git" rev-parse main)" "main is untouched"
assert_equals "" "$(git -C "${WORK}/failing.git" tag)" "there is no tag"
assert_equals "0" "$([ -e "${WORK}/failing.released" ] && echo 1 || echo 0)" "and no GitHub release"
assert_equals "OPEN" "$(cat "${WORK}/failing.prs/1/state")" "the pull request is left open"
assert_equals "main" "$(git -C "${WORK}/failing" branch --show-current)" "the checkout is back on main"

# --- Resumed after the pull request is merged by hand ----------------------------------
echo pass > "${WORK}/failing.checks"
gh pr merge 1 --repo nobody/failing --squash >/dev/null
out="$(run_release failing 1.2.3 --pr)"
status=$?

assert_equals "0" "$status" "running again after a hand merge finishes the release"
assert_contains "$out" "already merged" "it notices the merged pull request"
assert_contains "$out" "already on main — nothing to commit, no pull request needed" \
    "and finds the release commit in place"
assert_equals "$(cat "${WORK}/failing.prs/1/merge")" "$(git -C "${WORK}/failing.git" rev-parse 'v1.2.3^{commit}' 2>/dev/null)" \
    "the tag is on the commit merged by hand"

# --- Another change lands while the pull request waits -----------------------------------
make_project landing
touch "${WORK}/landing.land"
out="$(run_release landing 1.2.3 --pr)"
status=$?

assert_equals "0" "$status" "a change landing mid-wait doesn't stop the release"
assert_contains "$out" "gained other changes while the pull request waited" "but it is called out"
assert_contains "$out" "other.txt" "by file"
assert_equals "other.txt" "$(git -C "${WORK}/landing.git" show v1.2.3:other.txt 2>/dev/null)" \
    "the released commit includes it, as the merge did"

# --- A dry run opens nothing ----------------------------------------------------------------
make_project dry
before="$(git -C "${WORK}/dry.git" rev-parse main)"
out="$(run_release dry 1.2.3 --pr --dry-run)"
status=$?

assert_equals "0" "$status" "a --pr dry run exits 0"
assert_contains "$out" "would run: git switch -C chore/release-v1.2.3" "it describes the release branch"
assert_contains "$out" "would run: gh pr create" "the pull request"
assert_contains "$out" "would wait for every check on it to pass" "and the wait"
assert_equals "$before" "$(git -C "${WORK}/dry.git" rev-parse main)" "origin is untouched"
assert_equals "0" "$([ -d "${WORK}/dry.prs/1" ] && echo 1 || echo 0)" "no pull request was opened"
assert_contains "$(cat "${WORK}/dry/thing.xml")" "<version>1.2.2</version>" "the manifest is untouched"

# --- A plain run where a rule requires pull requests -----------------------------------------
make_project ruled
mkdir -p "${WORK}/ruled.rules"
echo '[{"type":"pull_request","parameters":{}}]' > "${WORK}/ruled.rules/main"
(cd "${WORK}/ruled" && git add -A && git commit -qm "notes")
before="$(git -C "${WORK}/ruled.git" rev-parse main)"
out="$(run_release ruled 1.2.3)"
status=$?

assert_equals "1" "$status" "a plain run stops where main requires a pull request"
assert_contains "$out" "main requires a pull request" "naming the branch"
assert_contains "$out" "--pr" "and pointing at --pr"
assert_not_contains "$out" "[1/9]" "before anything is bumped"
assert_contains "$(cat "${WORK}/ruled/thing.xml")" "<version>1.2.2</version>" "the manifest is untouched"
assert_equals "$before" "$(git -C "${WORK}/ruled.git" rev-parse main)" "origin is untouched"

# --- Only the changelog and this version's notes may be uncommitted -------------------------
make_project stray
echo "<?php // unfinished" > "${WORK}/stray/src/Other.php"
out="$(run_release stray 1.2.3 --pr)"
status=$?

assert_equals "1" "$status" "an unrelated uncommitted file stops a --pr release"
assert_contains "$out" "src/Other.php" "and is named"
assert_not_contains "$out" "[1/9]" "before anything is bumped"

# --- --pr with --tag-only --------------------------------------------------------------------
make_project tagonly
out="$(run_release tagonly 1.2.3 --pr --tag-only)"
status=$?

assert_equals "0" "$status" "a --pr tag-only release exits 0"
cut="$(git -C "${WORK}/tagonly.git" log --format=%H --grep='^chore(release): cut v1.2.3 (#1)$' main)"
assert_equals "$cut" "$(git -C "${WORK}/tagonly.git" rev-parse 'v1.2.3^{commit}' 2>/dev/null)" \
    "the tag is on the merged release commit"
assert_equals "tag" "$(git -C "${WORK}/tagonly.git" cat-file -t v1.2.3 2>/dev/null)" "and annotated"
assert_equals "0" "$([ -e "${WORK}/tagonly.released" ] && echo 1 || echo 0)" "nothing is published"
assert_equals "0" "$([ -d "${WORK}/tagonly.prs/2" ] && echo 1 || echo 0)" "and versions.json is left alone"

finish
