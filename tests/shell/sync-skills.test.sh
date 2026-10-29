#!/usr/bin/env bash
#
# Tests for the Claude Code skills that cwm-sync-configs distributes.
#
# A skill is the toolchain's own runbook, so unlike the compose file it is
# refreshed when the template changes — but only a copy that still carries the
# managed header. The cases that matter: --dry-run writes nothing, a fresh
# project gets the skill, an outdated managed copy is refreshed, a copy someone
# rewrote is left alone, and the project's own skills are never touched.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SYNC="${ROOT}/scripts/sync-configs.php"
NAME="cwm-release-troubleshooting"
TEMPLATE="${ROOT}/templates/claude-skills/${NAME}/SKILL.md"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- the template is a loadable skill ----------------------------------------

TPL="$(cat "$TEMPLATE")"
assert_equals "---" "$(head -1 "$TEMPLATE")" "skill starts with front matter"
assert_contains "$TPL" "name: ${NAME}" "front matter name matches the directory"
assert_contains "$TPL" "description: " "front matter has a description"
assert_contains "$TPL" "Managed by cwm-sync-configs" "carries the managed header the refresh relies on"

# --- dry run, create, idempotence ---------------------------------------------

PROJECT="${WORK}/project"
mkdir -p "${PROJECT}"
printf '{"name":"demo/x"}\n' > "${PROJECT}/composer.json"
printf '{"extension":{"name":"demo","type":"component"}}\n' > "${PROJECT}/cwm-build.config.json"
TARGET="${PROJECT}/.claude/skills/${NAME}/SKILL.md"

( cd "${PROJECT}" && php "${SYNC}" --dry-run >/dev/null 2>&1 )
[ -e "${TARGET}" ] && state="present" || state="absent"
assert_equals "absent" "$state" "--dry-run must not write the skill"

OUT="$( cd "${PROJECT}" && php "${SYNC}" 2>&1 )"
assert_contains "$OUT" ".claude/skills/${NAME}: created" "a fresh project gets the skill"
assert_equals "$(cat "$TEMPLATE")" "$(cat "$TARGET")" "the installed copy is the template"

OUT="$( cd "${PROJECT}" && php "${SYNC}" 2>&1 )"
assert_equals "0" "$(printf '%s' "$OUT" | grep -c ".claude/skills/${NAME}")" "an up-to-date copy is not rewritten or reported"

# --- an outdated managed copy is refreshed ------------------------------------

printf '%s\n<!-- Managed by cwm-sync-configs -->\nold advice\n' "---" > "${TARGET}"
OUT="$( cd "${PROJECT}" && php "${SYNC}" 2>&1 )"
assert_contains "$OUT" ".claude/skills/${NAME}: refreshed" "an outdated managed copy is refreshed"
assert_equals "$(cat "$TEMPLATE")" "$(cat "$TARGET")" "refresh restores the template"

# --- a rewritten copy (no managed header) is left alone -----------------------

printf 'my own notes\n' > "${TARGET}"
OUT="$( cd "${PROJECT}" && php "${SYNC}" 2>&1 )"
assert_contains "$OUT" "left alone" "a rewritten copy is reported, not overwritten"
assert_equals "my own notes" "$(cat "${TARGET}")" "a rewritten copy keeps its content"

# --- the project's own skills are never touched -------------------------------

mkdir -p "${PROJECT}/.claude/skills/project-own"
printf 'ours\n' > "${PROJECT}/.claude/skills/project-own/SKILL.md"
( cd "${PROJECT}" && php "${SYNC}" >/dev/null 2>&1 )
assert_equals "ours" "$(cat "${PROJECT}/.claude/skills/project-own/SKILL.md")" "a project's own skill is untouched"

finish
