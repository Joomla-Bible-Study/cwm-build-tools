---
name: cwm-release-troubleshooting
description: Diagnose and finish a cwm-build-tools release that failed or looks wrong — `composer release` / `cwm-release` stopping at step 7, `cwm-ars-publish` failing with HTTP 500/418 or exiting silently, sites not being offered a new version, update-feed checksum mismatches, or a stash that appeared or vanished during a release. Use before retrying a publish or editing anything on the ARS site.
---

<!-- Managed by cwm-sync-configs from cwm-build-tools/templates/claude-skills/.
     Edits here are overwritten on the next sync; change it in cwm-build-tools. -->

# Release & ARS publish troubleshooting

A release is only finished when the **update feed** offers it. Tag, GitHub
release and commits can all be public while sites are offered nothing. Work
out where the pipeline stopped before touching anything.

## 1. Establish what is already public

The release prints an INCOMPLETE summary; trust it, then confirm:

```
gh release view vX.Y.Z --json assets --jq '.assets[] | "\(.name) \(.size) \(.digest)"'
composer exec -- cwm-ars-list releases <categoryId>     # is the ARS release there?
composer exec -- cwm-verify-update-stream X.Y.Z         # is it offered?
```

An ARS **release with no item** is the common half-state: the feed is built
from items, so sites still see the previous version. `cwm-ars-publish` is an
upsert — re-running it reuses the release and creates only what is missing.
It never creates a duplicate.

## 2. Always publish the bytes GitHub serves

CI's tag-push job rebuilds the package and **replaces the release asset** about
a minute after the tag. gzip timestamps and bundler paths make that build
differ from yours, so local checksums would advertise bytes no site receives.
Publish from the served asset:

```
gh release download vX.Y.Z --pattern '*.zip' --dir "$(mktemp -d)"
composer ars-publish -- -v X.Y.Z -f <that dir>/<package>-X.Y.Z.zip
```

## 3. ARS write fails with HTTP 500 (Apache HTML page, not JSON)

Joomla's API answers its own errors in JSON. A bare HTML 500 means the request
never reached PHP, or PHP died hard. Check the site's **access log** for that
request:

- **418 logged, 500 served → ModSecurity blocked it.** The site's error log
  names the rule (`[id "…"]`, `Inbound Anomaly Score Exceeded`).
  - **Rule 930120 matching `.env` in `ARGS_NAMES:environments.environments`**:
    the OWASP CRS flattens a JSON array into that name. build-tools ≥ the
    version carrying `ArsPublisher::environmentsForTransport()` sends
    environments as an object and is not affected. On older build-tools, POST
    the item yourself with `"environments":{"0":"44","1":"45"}`; PHP decodes it
    to the same list ARS stores.
  - Another rule: change the payload's shape, not the site's security. Turn
    off the host's firewall only as a last resort, and say so.
- **500 logged** → a real PHP failure; read the PHP error log for that second.

**Probing safely.** ARS refuses an item with `"release_id": 0` (403) before any
save. Send the suspect payload that way: a 403 means it got past the firewall,
the 500 page means it was blocked. Nothing is created either way. Don't
experiment with real release ids on a live site.

Never print the API token. `cwm_op_token` (scripts/lib/optoken.sh) reads it
into a variable; pass it in a header from there.

## 4. Other known traps

- **`op whoami` says "not signed in."** That's normal with the 1Password
  desktop integration; `op` asks for an unlock when the token is read. It is
  not a reason to abort a release.
- **`ars-publish` stops after "Computing checksums..." with no message.**
  This was `set -e` killing the script when the local zip differed from the
  asset; it's fixed in current build-tools. Publish from the served asset
  (section 2).
- **A stash appeared or vanished around step 8.** Older `release.sh` ran
  `git stash pop` even when its own stash saved nothing, which popped the
  developer's older stash. Check `git stash list` after a release. A popped
  stash's commit is still reachable: `git stash store -m "<msg>" <sha>`.
- **Ordering at 0** (`no room to place this one below it`):
  `composer ars-reorder -- --category <id>`, then re-publish.

## 5. Done means verified

```
composer exec -- cwm-verify-update-stream X.Y.Z   # entry exists, php_minimum + targetplatform
```

Then confirm the feed describes the served bytes. If the project defines
`verify-update-element` / `verify-update-digest` composer scripts, run them.
Otherwise compare by hand:

```
curl -s '<stream url>' | php -r '$x = simplexml_load_string(stream_get_contents(STDIN));
  foreach ($x->update as $u) { if ((string) $u->version === "X.Y.Z") { echo $u->sha512, " ", $u->length, "\n"; } }'
shasum -a 512 <served asset>; wc -c < <served asset>
```

A wrong element name means no site matches the entry at all. A wrong
`sha512`/`length` means every site's update fails its integrity check.

Then record anything new you learned in this skill, in cwm-build-tools, so the
next release doesn't repeat it.
