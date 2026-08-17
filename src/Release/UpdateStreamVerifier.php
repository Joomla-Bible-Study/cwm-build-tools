<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

use DOMDocument;
use DOMXPath;

/**
 * Checks that a published update stream can actually reach sites.
 *
 * Everything else in the release pipeline runs before publish and therefore
 * cannot see what the stream serves afterwards. The failure this catches is
 * silent by construction: valid XML, a clean release, and some population of
 * sites simply never sees an update again. Null ARS environments mis-shipped
 * three Proclaim releases that way — published fine, offered to nobody.
 *
 * ## Parsing is separate from fetching
 *
 * {@see entriesFor()} takes XML and returns rows; {@see findings()} takes rows
 * and returns problems. Neither touches the network, so both are tested against
 * committed fixture feeds — including feeds that are *wrong*, which is the only
 * way to know the assertions can fail. Fetching lives in the CLI.
 *
 * ## Project-specific assertions belong to the project
 *
 * A feed can carry entries that only one extension cares about. Proclaim serves
 * a legacy `com_proclaim` entry for sites installed before it shipped a package,
 * and requires it to sort *before* the package entry of the same version,
 * because Joomla keeps a single `$latest` per feed and replaces it only on a
 * strictly greater version — so at equal versions the first block parsed wins.
 *
 * That is one extension's migration history, and it does not belong in an
 * extension-agnostic build tool. So `entriesFor()` returns the parsed rows,
 * in document order with their positions, and a project asserts whatever else
 * it needs on top rather than forking the fetcher to get at them.
 *
 * Feeds are semi-trusted (TLS), and parsed with the default libxml options —
 * external entities are disabled by default on libxml ≥ 2.9, and nothing here
 * passes `LIBXML_NOENT` or `LIBXML_DTDLOAD`.
 */
final class UpdateStreamVerifier
{
    /**
     * The update-server URL declared by a package manifest.
     *
     * Read from the manifest rather than configured separately, so a manifest
     * pointing at the wrong stream is caught by the same check — that is a real
     * failure mode, and a separately configured URL would verify a stream the
     * shipped package never consults.
     *
     * @return string|null Null when the file is unreadable, unparseable, or
     *                     declares no update server at all — which means the
     *                     package cannot self-update.
     */
    public static function streamUrlFromManifest(string $manifestPath): ?string
    {
        if (!is_file($manifestPath)) {
            return null;
        }

        $doc = new DOMDocument();

        if (!@$doc->load($manifestPath)) {
            return null;
        }

        $node = (new DOMXPath($doc))->query('/extension/updateservers/server')?->item(0);

        if ($node === null) {
            return null;
        }

        $url = trim($node->textContent);

        return $url === '' ? null : $url;
    }

    /**
     * Every `<update>` entry in the feed carrying this exact version.
     *
     * `position` is the entry's 1-based index across the whole feed, in document
     * order. It is kept because document order is what decides a tie between two
     * entries at the same version, so a project asserting on ordering needs it
     * and cannot recover it afterwards.
     *
     * @return list<array{element: string, type: string, version: string, position: int, php_minimum: string, targetplatform: string}>
     */
    public static function entriesFor(string $xml, string $version): array
    {
        $doc = new DOMDocument();

        if ($xml === '' || !@$doc->loadXML($xml)) {
            return [];
        }

        $xpath    = new DOMXPath($doc);
        $matching = [];
        $position = 0;

        foreach ($xpath->query('//update') ?: [] as $update) {
            $position++;
            $versionNode = $xpath->query('version', $update)?->item(0);

            if ($versionNode === null || trim($versionNode->textContent) !== $version) {
                continue;
            }

            $platformNode = $xpath->query('targetplatform', $update)?->item(0);

            $matching[] = [
                'element'        => self::text($xpath, $update, 'element'),
                'type'           => self::text($xpath, $update, 'type'),
                'version'        => $version,
                'position'       => $position,
                'php_minimum'    => self::text($xpath, $update, 'php_minimum'),
                'targetplatform' => $platformNode === null ? '' : trim($platformNode->getAttribute('version')),
            ];
        }

        return $matching;
    }

    /**
     * Generic problems with a released version's entries.
     *
     * Three assertions, each of which fails silently in production if broken:
     *
     *   - an entry exists at all. Without one the release is published and no
     *     site is ever offered it, which no pre-publish check can see;
     *   - `php_minimum` is present. Joomla filters entries by it, and an absent
     *     value is not "any version" on every code path;
     *   - `targetplatform` is present. Same shape — the entry is served and
     *     matches nobody.
     *
     * @param  list<array{element: string, type: string, version: string, position: int, php_minimum: string, targetplatform: string}>  $entries
     *
     * @return list<array{level: string, message: string}> Empty when the feed is sound.
     */
    public static function findings(array $entries, string $version): array
    {
        if ($entries === []) {
            return [[
                'level'   => 'fail',
                'message' => "No <update> entry at version {$version}. The release is published and no site will be offered it.",
            ]];
        }

        $findings = [];

        foreach ($entries as $entry) {
            $name = $entry['element'] === '' ? '(no element)' : $entry['element'];

            if ($entry['php_minimum'] === '') {
                $findings[] = [
                    'level'   => 'fail',
                    'message' => "Entry '{$name}' at {$version} has no <php_minimum>. Joomla filters on it, and an absent value is not 'any version' everywhere.",
                ];
            }

            if ($entry['targetplatform'] === '') {
                $findings[] = [
                    'level'   => 'fail',
                    'message' => "Entry '{$name}' at {$version} has no <targetplatform version=\"...\">. It will be served and match no site.",
                ];
            }
        }

        return $findings;
    }

    /** Trimmed text of a child element, or '' when absent. */
    private static function text(DOMXPath $xpath, \DOMNode $context, string $child): string
    {
        $node = $xpath->query($child, $context)?->item(0);

        return $node === null ? '' : trim($node->textContent);
    }
}
