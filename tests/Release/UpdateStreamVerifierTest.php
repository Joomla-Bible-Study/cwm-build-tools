<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\UpdateStreamVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and assertion, against committed feeds — including wrong ones.
 *
 * A post-publish check that has only ever been run against a healthy
 * production stream is a check nobody knows can fail. The fixtures here include
 * a feed missing the metadata Joomla filters on, which is the exact shape that
 * shipped three Proclaim releases to nobody.
 */
final class UpdateStreamVerifierTest extends TestCase
{
    private function feed(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/updates/' . $name);
    }

    #[Test]
    public function finds_the_entry_for_the_released_version(): void
    {
        $entries = UpdateStreamVerifier::entriesFor($this->feed('sound.xml'), '1.3.0');

        self::assertCount(1, $entries);
        self::assertSame('pkg_example', $entries[0]['element']);
        self::assertSame('8.1', $entries[0]['php_minimum']);
        self::assertSame('5\\.[0-9]+', $entries[0]['targetplatform']);
    }

    #[Test]
    public function a_sound_feed_yields_no_findings(): void
    {
        $entries = UpdateStreamVerifier::entriesFor($this->feed('sound.xml'), '1.3.0');

        self::assertSame([], UpdateStreamVerifier::findings($entries, '1.3.0'));
    }

    #[Test]
    public function a_version_with_no_entry_is_the_headline_failure(): void
    {
        // Published, and offered to nobody. No pre-publish check can see this.
        $entries = UpdateStreamVerifier::entriesFor($this->feed('sound.xml'), '9.9.9');

        self::assertSame([], $entries);

        $findings = UpdateStreamVerifier::findings($entries, '9.9.9');

        self::assertCount(1, $findings);
        self::assertSame('fail', $findings[0]['level']);
        self::assertStringContainsString('no site will be offered it', $findings[0]['message']);
    }

    #[Test]
    public function missing_php_minimum_and_targetplatform_are_both_reported(): void
    {
        // The null-ARS-environment shape: the entry exists, the release looks
        // clean, and Joomla filters it out at every site.
        $entries  = UpdateStreamVerifier::entriesFor($this->feed('missing-metadata.xml'), '1.3.0');
        $findings = UpdateStreamVerifier::findings($entries, '1.3.0');

        self::assertCount(2, $findings);
        self::assertStringContainsString('php_minimum', $findings[0]['message']);
        self::assertStringContainsString('targetplatform', $findings[1]['message']);
    }

    #[Test]
    public function entries_keep_their_document_position(): void
    {
        /*
         * Document order decides a tie: Joomla keeps a single $latest per feed
         * and replaces it only on a strictly greater version, so at equal
         * versions the first block parsed wins. A project asserting on ordering
         * cannot recover the position afterwards, so it is returned.
         */
        $entries = UpdateStreamVerifier::entriesFor($this->feed('two-entries.xml'), '1.3.0');

        self::assertSame(['com_example', 'pkg_example'], array_column($entries, 'element'));
        self::assertSame([1, 2], array_column($entries, 'position'));
    }

    #[Test]
    public function two_entries_at_one_version_are_not_themselves_a_problem(): void
    {
        // Whether a second entry is expected is the project's business, not
        // this class's. Reporting it generically would fail every Proclaim
        // release, which serves a legacy component entry on purpose.
        $entries = UpdateStreamVerifier::entriesFor($this->feed('two-entries.xml'), '1.3.0');

        self::assertSame([], UpdateStreamVerifier::findings($entries, '1.3.0'));
    }

    #[Test]
    public function unparseable_xml_yields_no_entries_rather_than_an_error(): void
    {
        // An ARS error page is HTML, not XML. The caller reports "no entry",
        // which is the true statement about what sites will be offered.
        self::assertSame([], UpdateStreamVerifier::entriesFor('<html>500</html>', '1.3.0'));
        self::assertSame([], UpdateStreamVerifier::entriesFor('', '1.3.0'));
    }

    #[Test]
    public function reads_the_stream_url_from_the_package_manifest(): void
    {
        // From the manifest, not from separate config: a manifest pointing at
        // the wrong stream is a real failure, and a separately configured URL
        // would verify a stream the shipped package never consults.
        self::assertSame(
            'https://example.test/updates.xml',
            UpdateStreamVerifier::streamUrlFromManifest(__DIR__ . '/../fixtures/updates/manifest-with-server.xml')
        );
    }

    #[Test]
    public function a_manifest_with_no_update_server_reads_as_null(): void
    {
        self::assertNull(
            UpdateStreamVerifier::streamUrlFromManifest(__DIR__ . '/../fixtures/updates/manifest-no-server.xml')
        );
        self::assertNull(UpdateStreamVerifier::streamUrlFromManifest(__DIR__ . '/../fixtures/updates/nope.xml'));
    }
}
