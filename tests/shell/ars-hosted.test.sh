#!/usr/bin/env bash
#
# Tests for hosted ARS downloads (`ars.hosting: "ars"`): config validation and
# the upload into the ARS repository.
#
# ssh and scp are replaced by stubs that act on a local directory standing in
# for the server, so the real helper runs — including under the `set -euo
# pipefail` its caller uses — without a network. The cases that matter are
# the ones a release cannot undo: a different file under a published name is
# never replaced, and "could not connect" is never mistaken for "not uploaded".

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=../../scripts/lib/ars.sh
source "${ROOT}/scripts/lib/ars.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

SERVER="${WORK}/server"
mkdir -p "${SERVER}/site/arsfiles" "${WORK}/bin"

# ssh <opts…> host 'command' — run the command with the fake server as $HOME.
# SSH_DOWN=1 makes it behave like an unreachable host (exit 255).
cat > "${WORK}/bin/ssh" <<EOF
#!/usr/bin/env bash
[ "\${SSH_DOWN:-0}" = 1 ] && exit 255
while [ "\$1" = "-o" ]; do shift 2; done
shift
cd "${SERVER}" && bash -c "\$1"
EOF
# scp <opts…> src host:dest — copy into the fake server.
cat > "${WORK}/bin/scp" <<EOF
#!/usr/bin/env bash
while [[ "\$1" == -* ]]; do [ "\$1" = "-o" ] && shift; shift; done
cp "\$1" "${SERVER}/\${2#*:}"
EOF
chmod +x "${WORK}/bin/ssh" "${WORK}/bin/scp"
export PATH="${WORK}/bin:${PATH}"

sha() { shasum -a 256 "$1" | cut -d' ' -f1; }

# --- config validation ----------------------------------------------------------

cwm_ars_validate_hosting github "" 2>/dev/null; assert_equals "0" "$?" "github hosting needs no upload target"
cwm_ars_validate_hosting ars "me@host:site/arsfiles" 2>/dev/null; assert_equals "0" "$?" "ars hosting with an scp target is valid"
cwm_ars_validate_hosting ars "" 2>/dev/null; assert_equals "1" "$?" "ars hosting without a target is refused"
cwm_ars_validate_hosting ars "/local/path" 2>/dev/null; assert_equals "1" "$?" "a local path is not an scp target"
cwm_ars_validate_hosting s3 "" 2>/dev/null; assert_equals "1" "$?" "an unknown hosting value is refused"

# --- upload: fresh, re-run, conflict, unreachable -------------------------------

ZIP="${WORK}/pkg_x-1.0.0.zip"
printf 'release bytes' > "${ZIP}"
TARGET="me@host:site/arsfiles/"
REMOTE="${SERVER}/site/arsfiles/pkg_x-1.0.0.zip"

OUT="$( set -euo pipefail; cwm_ars_upload_hosted "${ZIP}" "${TARGET}" "$(sha "${ZIP}")" 2>&1 )"
assert_equals "0" "$?" "a fresh upload succeeds under set -euo pipefail"
assert_contains "$OUT" "sha256 verified" "the upload is verified on the server"
assert_equals "$(sha "${ZIP}")" "$(sha "${REMOTE}")" "the server holds exactly the local bytes"
[ -e "${REMOTE}.uploading" ] && left="yes" || left="no"
assert_equals "no" "$left" "no temporary file is left behind"

OUT="$( set -euo pipefail; cwm_ars_upload_hosted "${ZIP}" "${TARGET}" "$(sha "${ZIP}")" 2>&1 )"
assert_equals "0" "$?" "re-running with the same bytes succeeds"
assert_contains "$OUT" "not uploading again" "the same bytes are not uploaded twice"

printf 'rebuilt, different bytes' > "${ZIP}"
OUT="$( set -euo pipefail; cwm_ars_upload_hosted "${ZIP}" "${TARGET}" "$(sha "${ZIP}")" 2>&1 )"
assert_equals "1" "$?" "different bytes under a published name are refused"
assert_contains "$OUT" "Refusing to replace" "the refusal says why"
assert_equals "release bytes" "$(cat "${REMOTE}")" "the published file is untouched"

OUT="$( set -euo pipefail; SSH_DOWN=1 cwm_ars_upload_hosted "${ZIP}" "${TARGET}" "$(sha "${ZIP}")" 2>&1 )"
assert_equals "1" "$?" "an unreachable host is an error"
assert_contains "$OUT" "could not reach" "and is not mistaken for a missing file"

finish
