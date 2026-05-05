#!/usr/bin/env bash
#
# Generate a Joomla changelog XML entry from a GitHub release and insert it
# into the project's changelog file.
#
# Reads from cwm-build.config.json:
#   changelog.file       Path to the project's changelog XML, relative to
#                        project root. Default: build/<extension>-changelog.xml
#   changelog.element    Joomla extension element name written into the
#                        <element> field. Default: extension.name
#   changelog.type       Joomla extension type written into the <type> field.
#                        Default: extension.type
#   github.owner / .repo Used to fetch release notes from GitHub.
#
# Parses the GitHub release body markdown into Joomla changelog types:
#   ### Fixes / Fixed / Bug Fixes      -> <fix>
#   ### New Features / Features / Added -> <addition>
#   ### Changes / Changed              -> <change>
#   ### Security                       -> <security>
#   ### Removed / Deprecated           -> <remove>
#   ### Language / Translations        -> <language>
#   ### Notes / Upgrade notes          -> <note>
#
# Usage:
#   bash scripts/generate-changelog-entry.sh <version> [--dry-run]
#
set -euo pipefail

PROJECT_ROOT="$(pwd)"
CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: $CONFIG_FILE not found"
    exit 1
fi

read_config() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo \$v ?? '';"
}

# --- Parse args ---
DRY_RUN=false
VERSION=""

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        *)         VERSION="$arg" ;;
    esac
done

if [ -z "$VERSION" ]; then
    echo "Error: version is required."
    echo "Usage: generate-changelog-entry.sh <version> [--dry-run]"
    exit 1
fi

EXT_NAME=$(read_config "extension.name")
EXT_TYPE=$(read_config "extension.type")
GH_OWNER=$(read_config "github.owner")
GH_REPO=$(read_config "github.repo")
CHANGELOG_FILE=$(read_config "changelog.file")
ELEMENT=$(read_config "changelog.element")
TYPE=$(read_config "changelog.type")

CHANGELOG_FILE="${CHANGELOG_FILE:-build/${EXT_NAME}-changelog.xml}"
ELEMENT="${ELEMENT:-$EXT_NAME}"
TYPE="${TYPE:-$EXT_TYPE}"

CHANGELOG_PATH="${PROJECT_ROOT}/${CHANGELOG_FILE}"

if [ -z "$GH_OWNER" ] || [ -z "$GH_REPO" ]; then
    echo "Error: github.owner and github.repo are required in cwm-build.config.json"
    exit 1
fi

TAG="v${VERSION}"

# --- Check if entry already exists ---
if [ -f "$CHANGELOG_PATH" ] && grep -q "<version>${VERSION}</version>" "$CHANGELOG_PATH" 2>/dev/null; then
    echo "Changelog entry for ${VERSION} already exists in $(basename "$CHANGELOG_PATH")."
    exit 0
fi

# --- Fetch release notes from GitHub ---
BODY=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json body --jq '.body' 2>/dev/null || echo "")
RELEASE_DATE=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json publishedAt --jq '.publishedAt' 2>/dev/null | cut -dT -f1 || echo "")

if [ -z "$BODY" ]; then
    echo "Error: Could not fetch release notes for ${TAG}."
    echo "Make sure the GitHub release exists: gh release view ${TAG} --repo ${GH_OWNER}/${GH_REPO}"
    exit 1
fi

if [ -z "$RELEASE_DATE" ]; then
    RELEASE_DATE=$(date +%Y-%m-%d)
fi

# --- Parse markdown into changelog XML ---
export CHANGELOG_BODY="$BODY"
ENTRY=$(python3 - "$VERSION" "$RELEASE_DATE" "$ELEMENT" "$TYPE" <<'PYTHON_SCRIPT'
import os, sys, re
from html import escape

version = sys.argv[1]
date    = sys.argv[2]
element = sys.argv[3]
ext_type = sys.argv[4]
body    = os.environ.get('CHANGELOG_BODY', '')

HEADING_MAP = {
    'fixes': 'fix', 'fix': 'fix', 'fixed': 'fix', 'bug fixes': 'fix', 'bug fix': 'fix',
    'new features': 'addition', 'features': 'addition', 'additions': 'addition', 'added': 'addition',
    'changes': 'change', 'changed': 'change', 'modifications': 'change',
    'security': 'security', 'security fixes': 'security',
    'removed': 'remove', 'remove': 'remove', 'deprecated': 'remove',
    'language': 'language', 'translations': 'language',
    'notes': 'note', 'note': 'note', 'upgrade notes': 'note',
    'requirements': 'note', 'testing': 'note',
    # Less-conventional headings that show up in older release notes;
    # treat as <note> so their bullets land somewhere instead of being
    # silently dropped.
    'highlights': 'note', 'overview': 'note', 'summary': 'note',
}

sections = {}
current_type = None
current_items = []

for line in body.split('\n'):
    line = line.strip()
    heading_match = re.match(r'^#{2,3}\s+(.+)', line)
    if heading_match:
        if current_type and current_items:
            sections.setdefault(current_type, []).extend(current_items)
        heading = heading_match.group(1).strip().rstrip(':').lower()
        heading = re.sub(r'^v?\d+\.\d+\S*\s*[—–-]\s*', '', heading).strip().lower()
        current_type = HEADING_MAP.get(heading)
        current_items = []
        continue
    item_match = re.match(r'^[-*]\s+(.+)', line)
    if item_match and current_type:
        text = item_match.group(1).strip()
        text = re.sub(r'\*\*([^*]+)\*\*', r'\1', text)
        text = re.sub(r'`([^`]+)`', r'\1', text)
        current_items.append(escape(text))

if current_type and current_items:
    sections.setdefault(current_type, []).extend(current_items)

if not sections:
    items = []
    for line in body.split('\n'):
        line = line.strip()
        item_match = re.match(r'^[-*]\s+(.+)', line)
        if item_match:
            text = re.sub(r'\*\*([^*]+)\*\*', r'\1', item_match.group(1).strip())
            text = re.sub(r'`([^`]+)`', r'\1', text)
            items.append(escape(text))
    if items:
        sections['note'] = items

TYPE_ORDER = ['security', 'fix', 'addition', 'change', 'remove', 'language', 'note']

print(f'    <!-- ============================================================ -->')
print(f'    <!-- {version:<57s}-->')
print(f'    <!-- ============================================================ -->')
print(f'    <changelog>')
print(f'        <element>{element}</element>')
print(f'        <type>{ext_type}</type>')
print(f'        <version>{version}</version>')
print(f'        <date>{date}</date>')

for change_type in TYPE_ORDER:
    if change_type in sections:
        print(f'        <{change_type}>')
        for item in sections[change_type]:
            print(f'            <item>{item}</item>')
        print(f'        </{change_type}>')

print(f'    </changelog>')
PYTHON_SCRIPT
)

if [ -z "$ENTRY" ]; then
    echo "Error: Failed to generate changelog entry."
    exit 1
fi

if [ "$DRY_RUN" = true ]; then
    echo "$ENTRY"
    exit 0
fi

if [ ! -f "$CHANGELOG_PATH" ]; then
    echo "Error: changelog file not found: $CHANGELOG_PATH"
    echo "Create the file with a <changelogs> root element first."
    exit 1
fi

export CHANGELOG_ENTRY="$ENTRY"
python3 - "$CHANGELOG_PATH" <<'INSERT_SCRIPT'
import os, sys

changelog_file = sys.argv[1]
entry = os.environ.get('CHANGELOG_ENTRY', '')

with open(changelog_file, 'r') as f:
    content = f.read()

marker = '<changelogs>'
pos = content.find(marker)
if pos == -1:
    print("Error: Could not find <changelogs> tag in changelog file.", file=sys.stderr)
    sys.exit(1)

insert_pos = content.index('\n', pos) + 1
new_content = content[:insert_pos] + '\n' + entry + '\n' + content[insert_pos:]

with open(changelog_file, 'w') as f:
    f.write(new_content)
INSERT_SCRIPT

echo "Changelog entry for ${VERSION} added to $(basename "$CHANGELOG_PATH")."
