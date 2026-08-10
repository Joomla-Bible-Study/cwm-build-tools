<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

use CWM\BuildTools\Http\HttpResponse;
use CWM\BuildTools\Http\HttpTransport;

/**
 * Create or update a release and its download item in Akeeba Release System.
 *
 * Vetted against akeeba/release-system 7.5.0 (`development`, which is the
 * branch carrying the JSON:API — `main` is still the Joomla 3 layout and has
 * no `component/api` at all). Everything below that looks like a quirk is a
 * quirk of that code, with the reference alongside it.
 *
 * ## Why an upsert has to be exactly right
 *
 * Every write here is create-or-update against a live public download page,
 * and both halves report success. A lookup that wrongly misses takes the
 * create branch and publishes a *second* release for a version that already
 * exists (#37); a lookup that wrongly hits PATCHes over a different release.
 * The lookups are therefore the code that must not be wrong silently, and
 * the reason this class refuses to guess when it cannot read.
 *
 * ## What the API demands
 *
 * - **Bare query parameters, not JSON:API `filter[...]`.** Releases- and
 *   ItemsController map the input keys `category_id` / `release_id` onto
 *   `filter.*` themselves (ReleasesController::displayList). Sent as
 *   `filter[category_id]` the value arrives as a PHP array named `filter`,
 *   the mapping finds nothing, and the filter is silently not applied — over
 *   a response capped at 20 rows.
 * - **`page[limit]` raises that cap.** `list[limit]` is ignored.
 * - **A flat JSON body.** `AssertApiAccess::getRequestData()` json_decodes the
 *   raw body, so top-level keys are the record fields; there is no `data`
 *   envelope to wrap them in.
 * - **`Accept: application/vnd.api+json`.** Joomla's API app answers 406 to
 *   `application/json`.
 *
 * ## `notes` is HTML, not Markdown
 *
 * ARS renders a release's notes as HTML on the public download page, and that
 * page is the only changelog a site administrator following an update link
 * ever sees. Markdown handed over as-is is not converted: the public 10.3.6
 * page showed "## What's Changed * fix(api): ..." with every marker literal
 * and the whole changelog collapsed onto one line. Callers convert first —
 * scripts/render-notes.php does it for the shell pipeline — and
 * {@see assertRenderedNotes} refuses the obvious cases of forgetting.
 *
 * ## Two silent-coercion traps this refuses to walk into
 *
 * - **`maturity`.** ReleaseTable::check() replaces anything outside
 *   alpha/beta/rc/stable with **'beta'** — no error. A typo publishes a
 *   stable release as beta, and Joomla's updater hides it from sites that
 *   don't opt into pre-release updates.
 * - **`environments`.** An item with none makes ARS emit update XML with
 *   bogus php_minimum / targetplatform requirements that block the update on
 *   every real site; Proclaim 10.3.4-10.4.0 all shipped that way (#58).
 *   ItemTable stores the field as a JSON-encoded array, so a JSON array is
 *   what goes on the wire.
 * - **`ordering`.** ARS reads "latest release" as "lowest `ordering` in the
 *   category", never as newest version or date, and assigns nothing useful on
 *   its own — the column has no DEFAULT and ReleaseTable::check() only turns
 *   null into 0. Publish without setting it and every release ties at 0, so
 *   the Latest Releases page freezes on whichever row the database returns
 *   last. It is also the one field where *omitting* it on an update is
 *   destructive rather than neutral: release.xml declares `default="0"`, the
 *   form fills defaults for absent fields, so a PATCH that leaves it out drags
 *   that release to the front of its category. This class therefore always
 *   sends it — one below the category minimum when creating, unchanged when
 *   updating. See {@see orderingForNewest} (#77).
 *
 * ## Authorisation, as of ARS 7.5.0
 *
 * 7.5.0 added AssertApiAccess: every list and read now requires `core.manage`
 * on com_ars, and create/edit are additionally gated on
 * `core.create` / `core.edit` for the *specific category*. A token that
 * published happily against 7.4.x can start returning 403 after the site
 * upgrades. That is precisely the failure this class must not paper over —
 * a 403 on the lookup would otherwise read as "no such release" and publish
 * a duplicate, so a non-2xx read is a hard error, never "not found".
 */
final class ArsPublisher
{
    /** Maturity values ReleaseTable::check() accepts without rewriting. */
    public const MATURITIES = ['alpha', 'beta', 'rc', 'stable'];

    /**
     * Rows requested per lookup. The API defaults to 20, which is fewer than
     * the releases in an active category and is not ordered by anything
     * useful, so the wanted version can simply fall outside the window.
     */
    private const PAGE_LIMIT = 200;

    private readonly string $apiBase;

    public function __construct(
        string $endpoint,
        private readonly string $apiToken,
        private readonly HttpTransport $http,
    ) {
        $this->apiBase = rtrim($endpoint, '/') . '/api/index.php/v1/ars';
    }

    /**
     * Publish a version: upsert the release, then upsert its download item.
     *
     * @param  array<string, mixed> $release Release fields; `category_id` and
     *                                       `version` are required.
     * @param  array<string, mixed> $item    Item fields; `url` and `environments`
     *                                       are required. `release_id` is filled
     *                                       in from the upserted release.
     *
     * @return array{releaseId: int, itemId: int, releaseCreated: bool, itemCreated: bool}
     */
    public function publish(array $release, array $item): array
    {
        $releaseResult = $this->upsertRelease($release);

        $item['release_id'] = $releaseResult['id'];

        $itemResult = $this->upsertItem($item);

        return [
            'releaseId'      => $releaseResult['id'],
            'itemId'         => $itemResult['id'],
            'releaseCreated' => $releaseResult['created'],
            'itemCreated'    => $itemResult['created'],
        ];
    }

    /**
     * Create the release for this version, or update it if it already exists.
     *
     * @param  array<string, mixed> $release
     *
     * @return array{id: int, created: bool}
     */
    public function upsertRelease(array $release): array
    {
        $categoryId = (int) ($release['category_id'] ?? 0);
        $version    = (string) ($release['version'] ?? '');

        if ($categoryId <= 0) {
            throw new \InvalidArgumentException('ARS release requires a positive category_id.');
        }

        if ($version === '') {
            throw new \InvalidArgumentException('ARS release requires a version.');
        }

        self::assertMaturity((string) ($release['maturity'] ?? 'stable'));
        self::assertRenderedNotes((string) ($release['notes'] ?? ''));

        $existing = $this->findRelease($categoryId, $version);

        if ($existing !== null) {
            $existingId = (int) $existing['id'];

            // Preserve the ordering this release already has. Omitting the key
            // does NOT leave it alone — release.xml declares ordering with
            // default="0", the form fills the default for absent fields, and
            // the release silently jumps to 0. Re-publishing a version to fix
            // its notes would otherwise drag it to the front of its category.
            $release['ordering'] ??= $existing['ordering'];

            // ReleaseTable::check() is a full-record check; PATCHing a subset
            // is how fields get reset to defaults. The caller passes the whole
            // record, and `id` goes with it so the body agrees with the route.
            $this->send('PATCH', "/releases/{$existingId}", ['id' => $existingId] + $release);

            return ['id' => $existingId, 'created' => false];
        }

        $release['ordering'] ??= $this->orderingForNewest($categoryId);

        $response = $this->send('POST', '/releases', $release);

        return ['id' => $this->idFromWriteResponse($response, 'release'), 'created' => true];
    }

    /**
     * The `ordering` value that makes a new release the latest in its category.
     *
     * ARS decides "latest" with an anti-join in ReleasesModel::getListQuery()
     * (`filter.latest`): it keeps the releases with **no published sibling of a
     * smaller `ordering`**, i.e. the *minimum* ordering in the category. Version
     * numbers and dates are never consulted. Whatever holds the lowest ordering
     * is what the Latest Releases page shows, forever, no matter what is
     * published afterwards.
     *
     * ARS itself never assigns a useful value: `#__ars_releases.ordering` is
     * `bigint unsigned NOT NULL` with no DEFAULT, and ReleaseTable::check() only
     * does `$this->ordering = $this->ordering ?? 0`. So every release published
     * without an explicit ordering lands on 0, they all tie for the minimum, the
     * anti-join returns all of them, and Latest\HtmlView keeps whichever row the
     * database happened to return last. That is how christianwebministries.org
     * ended up serving Proclaim 10.3.1 for three months while 10.5.7 was out
     * (cwm-build-tools#77).
     *
     * Going one below the current minimum makes the new release the unique
     * minimum and touches no other record — which matters, because "just bump
     * the old one out of the way" would mean PATCHing a live release, and a
     * PATCH has to carry the whole record (see the ordering note above) so it
     * re-runs check() over that release's notes. One release, one write.
     *
     * @throws \RuntimeException When the category has no room below its minimum.
     */
    private function orderingForNewest(int $categoryId): int
    {
        $query = http_build_query([
            'category_id' => $categoryId,
            'page'        => ['limit' => self::PAGE_LIMIT],
        ]);

        $rows = $this->readList("/releases?{$query}", 'releases');

        // A full page means the list was probably cut off, and a minimum taken
        // from part of a category is not a minimum. Guessing here would place
        // the release above a sibling we never saw and quietly reinstate the
        // bug this method exists to prevent.
        if (\count($rows) >= self::PAGE_LIMIT) {
            throw new \RuntimeException(sprintf(
                'ARS category %d returned the full %d-row page, so this may not be all of it and the lowest '
                . '`ordering` cannot be established. Refusing to guess: a release ordered above an unseen '
                . 'sibling would not become the latest, and nothing about the publish would say so. '
                . 'Raise ArsPublisher::PAGE_LIMIT above the number of releases in the category.',
                $categoryId,
                self::PAGE_LIMIT
            ));
        }

        $orderings = array_map(
            static fn (array $row): int => (int) (($row['attributes'] ?? [])['ordering'] ?? 0),
            $rows
        );

        // An empty category: start at 1 rather than 0, so the next release has
        // somewhere to go.
        if ($orderings === []) {
            return 1;
        }

        $minimum = min($orderings);

        if ($minimum <= 0) {
            throw new \RuntimeException(sprintf(
                'ARS category %d has a release at ordering 0, so there is no room to place this one below it. '
                . '`ordering` is unsigned, 0 is the floor, and a tie for the lowest value is exactly the bug '
                . 'that made the Latest Releases page stick on an old version (cwm-build-tools#77). '
                . 'Renumber the category once so the newest release has the lowest ordering and nothing sits '
                . 'at 0 — in the ARS backend: Releases, filter to the category, sort by Ordering, drag the '
                . 'newest release to the top. Leave a gap by numbering in steps if you publish often. '
                . 'Then re-run this publish.',
                $categoryId
            ));
        }

        return $minimum - 1;
    }

    /**
     * Create the download item for this artifact, or update it if present.
     *
     * @param  array<string, mixed> $item
     *
     * @return array{id: int, created: bool}
     */
    public function upsertItem(array $item): array
    {
        $releaseId = (int) ($item['release_id'] ?? 0);
        $url       = (string) ($item['url'] ?? '');

        if ($releaseId <= 0) {
            throw new \InvalidArgumentException('ARS item requires a positive release_id.');
        }

        if ($url === '') {
            throw new \InvalidArgumentException('ARS item requires a download url.');
        }

        self::assertEnvironments($item['environments'] ?? null);

        $existingId = $this->findItemId($releaseId, self::basename($url));

        if ($existingId !== null) {
            $this->send('PATCH', "/items/{$existingId}", ['id' => $existingId] + $item);

            return ['id' => $existingId, 'created' => false];
        }

        $response = $this->send('POST', '/items', $item);

        return ['id' => $this->idFromWriteResponse($response, 'item'), 'created' => true];
    }

    /**
     * The id of the release with exactly this version, or null if there is none.
     *
     * The API-side `search` is a substring match — searching 10.3.1 also
     * returns 10.3.10 — so the version comparison happens here, on the
     * exact string.
     */
    public function findReleaseId(int $categoryId, string $version): ?int
    {
        $release = $this->findRelease($categoryId, $version);

        return $release === null ? null : $release['id'];
    }

    /**
     * The id and current ordering of the release with exactly this version.
     *
     * `ordering` comes back with the id because the update path has to send it
     * again to keep it — see the note in {@see upsertRelease}. Reading it here
     * keeps that to the one lookup the upsert already performs.
     *
     * @return array{id: int, ordering: int}|null
     */
    public function findRelease(int $categoryId, string $version): ?array
    {
        $query = http_build_query([
            'category_id' => $categoryId,
            'search'      => $version,
            'page'        => ['limit' => self::PAGE_LIMIT],
        ]);

        $rows = $this->readList("/releases?{$query}", 'releases');

        foreach ($rows as $row) {
            $attributes = $row['attributes'] ?? [];

            if (($attributes['version'] ?? null) === $version) {
                return [
                    'id'       => (int) $attributes['id'],
                    'ordering' => (int) ($attributes['ordering'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * The id of the download item pointing at this file name, or null.
     *
     * Matched on the exact basename of the item's URL. Items point at GitHub
     * download URLs whose path carries the tag and can be rewritten, so the
     * basename is the stable part — but a suffix match would also claim
     * `other-pkg_x-1.0.zip` when looking for `pkg_x-1.0.zip`.
     */
    public function findItemId(int $releaseId, string $zipName): ?int
    {
        $query = http_build_query([
            'release_id' => $releaseId,
            'page'       => ['limit' => self::PAGE_LIMIT],
        ]);

        $rows = $this->readList("/items?{$query}", 'items');

        foreach ($rows as $row) {
            $attributes = $row['attributes'] ?? [];

            if (self::basename((string) ($attributes['url'] ?? '')) === $zipName) {
                return (int) $attributes['id'];
            }
        }

        return null;
    }

    /**
     * Reject a maturity ARS would silently rewrite.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertMaturity(string $maturity): void
    {
        if (!\in_array($maturity, self::MATURITIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'ARS maturity must be one of %s, got "%s". ARS would silently store this as "beta", '
                . 'and Joomla hides beta updates from sites that have not opted into pre-release updates.',
                implode(', ', self::MATURITIES),
                $maturity
            ));
        }
    }

    /**
     * Reject release notes that are plainly still Markdown.
     *
     * ARS renders this field as HTML. Proclaim 10.3.6's download page shipped
     * with literal `##` and `**` markers and the entire changelog on one line
     * because the conversion step was skipped, and nothing about the publish
     * reported a problem.
     *
     * The test is deliberately narrow — Markdown block markers present AND no
     * HTML element anywhere — so it catches "forgot to render" without
     * second-guessing hand-written HTML that happens to contain an asterisk.
     * Empty notes are allowed: a release with none is a choice, not a mistake.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertRenderedNotes(string $notes): void
    {
        if (trim($notes) === '' || preg_match('/<[a-z][a-z0-9]*\b[^>]*>/i', $notes) === 1) {
            return;
        }

        // Leading #/*/- at the start of a line, or a [link](url) span.
        $markdown = preg_match('/^\s{0,3}(#{1,6}\s|[*+-]\s|\d+\.\s)/m', $notes) === 1
            || preg_match('/\[[^]]+]\([^)]+\)/', $notes) === 1
            || preg_match('/\*\*[^*]+\*\*/', $notes) === 1;

        if ($markdown) {
            throw new \InvalidArgumentException(
                'ARS release notes must be HTML, and these look like unrendered Markdown. ARS renders the '
                . 'field verbatim, so the download page would show the "##" and "**" markers literally with '
                . 'the changelog collapsed onto one line (this is what shipped on Proclaim 10.3.6). '
                . 'Convert first — scripts/render-notes.php reads Markdown on stdin and writes the HTML '
                . 'fragment ARS expects.'
            );
        }
    }

    /**
     * Reject an environments value that would produce unusable update XML.
     *
     * @param  mixed $environments
     *
     * @throws \InvalidArgumentException
     */
    public static function assertEnvironments(mixed $environments): void
    {
        $valid = \is_array($environments)
            && $environments !== []
            && array_reduce(
                $environments,
                static fn (bool $ok, mixed $id): bool => $ok
                    && (\is_int($id) || (\is_string($id) && ctype_digit(trim($id)))),
                true
            );

        if (!$valid) {
            throw new \InvalidArgumentException(
                'ars.environments must be a non-empty array of ARS environment ids. Publishing an item '
                . 'without environments makes ARS derive php_minimum and targetplatform from nothing, '
                . 'which blocks the update on every real site (Proclaim 10.3.4-10.4.0 shipped this way). '
                . 'Copy the ids from a known-good item: GET {endpoint}/api/index.php/v1/ars/items and read '
                . 'its "environments" attribute — the /environments endpoint 404s.'
            );
        }
    }

    /**
     * GET a list endpoint and return its `data` rows.
     *
     * A non-2xx response, or a body that is not the expected envelope, is an
     * error rather than an empty list. The whole point: "I could not read the
     * releases" and "there are no releases" lead to opposite actions, and
     * since ARS 7.5.0 the first has become a live possibility for a token
     * that used to work (AssertApiAccess now requires core.manage).
     *
     * @return list<array<string, mixed>>
     */
    private function readList(string $path, string $what): array
    {
        $response = $this->http->request('GET', $this->apiBase . $path, $this->headers());

        if (!$response->isSuccess()) {
            throw new \RuntimeException($this->describeFailure("Could not read ARS {$what}", $response));
        }

        $decoded = $response->json();

        if ($decoded === null || !\array_key_exists('data', $decoded) || !\is_array($decoded['data'])) {
            throw new \RuntimeException(
                "Unexpected response reading ARS {$what}: no \"data\" array. "
                . 'Refusing to treat an unreadable response as "no results", because that would publish a '
                . 'duplicate. Body: ' . self::excerpt($response->body)
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($decoded['data'], is_array(...)));

        return $rows;
    }

    /**
     * Send a write request, failing loudly on a non-2xx status.
     *
     * @param array<string, mixed> $payload
     */
    private function send(string $method, string $path, array $payload): HttpResponse
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $response = $this->http->request(
            $method,
            $this->apiBase . $path,
            $this->headers(['Content-Type' => 'application/json']),
            $body
        );

        if (!$response->isSuccess()) {
            throw new \RuntimeException($this->describeFailure("ARS {$method} {$path} failed", $response));
        }

        return $response;
    }

    /**
     * Pull the new record's id out of a create response.
     */
    private function idFromWriteResponse(HttpResponse $response, string $what): int
    {
        $decoded = $response->json();
        $id      = (int) ($decoded['data']['attributes']['id'] ?? 0);

        if ($id <= 0) {
            throw new \RuntimeException(
                "ARS accepted the {$what} but returned no id. Body: " . self::excerpt($response->body)
            );
        }

        return $id;
    }

    /**
     * Build an error message that names the likely cause.
     *
     * 401/403 gets spelled out because the 7.5.0 hardening makes it the most
     * probable new failure on a previously working setup, and "403" on its
     * own sends people looking at their token rather than at the category
     * permissions that actually changed.
     */
    private function describeFailure(string $prefix, HttpResponse $response): string
    {
        $message = "{$prefix} (HTTP {$response->status}).";

        if ($response->status === 401 || $response->status === 403) {
            $message .= ' Since ARS 7.5.0 the JSON:API enforces the component permissions: the token'
                . ' user needs "core.manage" on com_ars, plus "core.create"/"core.edit" on the target'
                . ' category. Check Users -> Manage -> the API user, and com_ars -> Options ->'
                . ' Permissions for that category.';
        }

        if ($response->status === 406) {
            $message .= ' Joomla answers 406 unless the request asks for application/vnd.api+json.';
        }

        return $message . ' Body: ' . self::excerpt($response->body);
    }

    /**
     * @param  array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        return [
            'X-Joomla-Token' => $this->apiToken,
            'Accept'         => 'application/vnd.api+json',
        ] + $extra;
    }

    /**
     * The file name part of a URL, ignoring any query string.
     */
    private static function basename(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        if (!\is_string($path) || $path === '') {
            $path = $url;
        }

        $segments = explode('/', $path);

        return (string) end($segments);
    }

    /**
     * Trim a response body for an error message — ARS can answer with a full
     * HTML error page, and that should not become the exception text.
     */
    private static function excerpt(string $body, int $max = 400): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        if ($body === '') {
            return '(empty)';
        }

        return \strlen($body) <= $max ? $body : substr($body, 0, $max) . '…';
    }
}
