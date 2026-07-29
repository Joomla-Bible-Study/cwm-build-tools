<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Http\HttpResponse;
use CWM\BuildTools\Http\HttpTransport;
use CWM\BuildTools\Release\ArsPublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every write ArsPublisher makes lands on a live public download page and
 * reports success either way, so these tests are about the decisions rather
 * than the plumbing: does it update the release that exists, refuse to guess
 * when it cannot read, and reject the two values ARS silently rewrites.
 *
 * Response shapes are taken from akeeba/release-system 7.5.0's
 * api/src/View/{Releases,Items}/JsonapiView.php.
 */
final class ArsPublisherTest extends TestCase
{
    private const ENDPOINT = 'https://example.org';

    #[Test]
    public function existing_release_is_updated_not_duplicated(): void
    {
        $http = new FakeTransport([
            self::releaseList([['id' => 42, 'version' => '1.2.0']]),
            self::ok(['data' => ['attributes' => ['id' => 42]]]),
        ]);

        $result = self::publisher($http)->upsertRelease(self::release('1.2.0'));

        self::assertSame(['id' => 42, 'created' => false], $result);
        self::assertSame('PATCH', $http->calls[1]['method']);
        self::assertStringContainsString('/releases/42', $http->calls[1]['url']);
    }

    #[Test]
    public function absent_release_is_created(): void
    {
        $http = new FakeTransport([
            self::releaseList([]),
            self::ok(['data' => ['attributes' => ['id' => 77]]]),
        ]);

        $result = self::publisher($http)->upsertRelease(self::release('1.2.0'));

        self::assertSame(['id' => 77, 'created' => true], $result);
        self::assertSame('POST', $http->calls[1]['method']);
    }

    #[Test]
    public function version_match_is_exact_not_substring(): void
    {
        // ARS `search` substring-matches, so a search for 10.3.1 returns
        // 10.3.10 too. Picking the wrong row PATCHes over a different release.
        $http = new FakeTransport([
            self::releaseList([
                ['id' => 10, 'version' => '10.3.10'],
                ['id' => 11, 'version' => '10.3.1'],
            ]),
            self::ok(['data' => ['attributes' => ['id' => 11]]]),
        ]);

        $result = self::publisher($http)->upsertRelease(self::release('10.3.1'));

        self::assertSame(11, $result['id']);
    }

    #[Test]
    public function lookup_uses_bare_query_params_not_jsonapi_filter_syntax(): void
    {
        // ReleasesController::displayList maps the input key `category_id`.
        // Sent as filter[category_id] it arrives as a PHP array named
        // `filter`, the filter is silently not applied, and the match runs
        // against an arbitrary window of every release on the site.
        $http = new FakeTransport([self::releaseList([]), self::ok(['data' => ['attributes' => ['id' => 1]]])]);

        self::publisher($http)->upsertRelease(self::release('1.2.0'));

        $url = $http->calls[0]['url'];

        self::assertStringContainsString('category_id=7', $url);
        self::assertStringNotContainsString('filter', $url);
        // page[limit] raises the 20-row default; list[limit] is ignored.
        self::assertStringContainsString('page%5Blimit%5D=200', $url);
    }

    #[Test]
    public function forbidden_lookup_is_an_error_not_an_empty_result(): void
    {
        // The 7.5.0 hardening (AssertApiAccess) can 403 a token that used to
        // work. Treating that as "no such release" would publish a duplicate.
        $http = new FakeTransport([new HttpResponse(403, '{"errors":[{"code":403}]}')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/core\.manage/');

        self::publisher($http)->upsertRelease(self::release('1.2.0'));
    }

    #[Test]
    public function unreadable_lookup_body_is_an_error_not_an_empty_result(): void
    {
        $http = new FakeTransport([new HttpResponse(200, '<html>maintenance</html>')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate/');

        self::publisher($http)->upsertRelease(self::release('1.2.0'));
    }

    #[Test]
    public function a_406_names_the_accept_header(): void
    {
        $http = new FakeTransport([new HttpResponse(406, '')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vnd\.api\+json/');

        self::publisher($http)->upsertRelease(self::release('1.2.0'));
    }

    #[Test]
    public function requests_carry_the_token_and_jsonapi_accept_header(): void
    {
        $http = new FakeTransport([self::releaseList([]), self::ok(['data' => ['attributes' => ['id' => 1]]])]);

        self::publisher($http)->upsertRelease(self::release('1.2.0'));

        self::assertSame('secret-token', $http->calls[0]['headers']['X-Joomla-Token']);
        self::assertSame('application/vnd.api+json', $http->calls[0]['headers']['Accept']);
        self::assertSame('application/json', $http->calls[1]['headers']['Content-Type']);
    }

    #[Test]
    public function write_payload_is_flat_json_with_no_data_envelope(): void
    {
        // AssertApiAccess::getRequestData() json_decodes the raw body and
        // treats its top-level keys as the record fields.
        $http = new FakeTransport([self::releaseList([]), self::ok(['data' => ['attributes' => ['id' => 1]]])]);

        self::publisher($http)->upsertRelease(self::release('1.2.0'));

        $sent = json_decode((string) $http->calls[1]['body'], true);

        self::assertSame('1.2.0', $sent['version']);
        self::assertSame(7, $sent['category_id']);
        self::assertArrayNotHasKey('data', $sent);
    }

    #[Test]
    public function unexpected_maturity_is_refused_before_any_request(): void
    {
        // ReleaseTable::check() rewrites anything outside the allowed set to
        // 'beta' without complaint, and Joomla then hides the update from
        // sites that have not opted into pre-release updates.
        $http = new FakeTransport([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/beta/');

        self::publisher($http)->upsertRelease(self::release('1.2.0', ['maturity' => 'production']));
    }

    #[Test]
    public function allowed_maturities_match_what_ars_accepts(): void
    {
        self::assertSame(['alpha', 'beta', 'rc', 'stable'], ArsPublisher::MATURITIES);

        foreach (ArsPublisher::MATURITIES as $maturity) {
            ArsPublisher::assertMaturity($maturity);
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function unrendered_markdown_notes_are_refused(): void
    {
        // Proclaim 10.3.6's download page shipped with literal "##" and "**"
        // because the Markdown was never converted, and the publish reported
        // success throughout.
        $markdown = "## What's Changed\n* fix(api): something\n* **breaking**: else\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/render-notes/');

        self::publisher(new FakeTransport([]))->upsertRelease(self::release('1.2.0', ['notes' => $markdown]));
    }

    #[Test]
    public function html_notes_pass_and_empty_notes_are_allowed(): void
    {
        // Hand-written HTML containing an asterisk or a hyphen list must not
        // trip the heuristic, and a release with no notes is a choice.
        foreach (['<p>Fixed <strong>a * b</strong></p>', '<ul><li>- dash</li></ul>', '', '   '] as $notes) {
            ArsPublisher::assertRenderedNotes($notes);
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function existing_item_is_updated_not_duplicated(): void
    {
        $http = new FakeTransport([
            self::itemList([['id' => 5, 'url' => 'https://github.com/o/r/releases/download/v1.2.0/pkg_x-1.2.0.zip']]),
            self::ok(['data' => ['attributes' => ['id' => 5]]]),
        ]);

        $result = self::publisher($http)->upsertItem(self::item());

        self::assertSame(['id' => 5, 'created' => false], $result);
        self::assertSame('PATCH', $http->calls[1]['method']);
    }

    #[Test]
    public function item_match_is_on_the_whole_basename(): void
    {
        // A suffix match would claim other-pkg_x-1.2.0.zip when looking for
        // pkg_x-1.2.0.zip, and PATCH the wrong download item.
        $http = new FakeTransport([
            self::itemList([['id' => 5, 'url' => 'https://github.com/o/r/releases/download/v1/other-pkg_x-1.2.0.zip']]),
            self::ok(['data' => ['attributes' => ['id' => 9]]]),
        ]);

        $result = self::publisher($http)->upsertItem(self::item());

        self::assertSame(['id' => 9, 'created' => true], $result);
    }

    #[Test]
    public function item_without_a_url_is_skipped_not_a_crash(): void
    {
        // ARS items of type "file" carry a filename instead of a url.
        $http = new FakeTransport([
            self::ok(['data' => [['type' => 'items', 'id' => '10', 'attributes' => ['id' => 10]]]]),
            self::ok(['data' => ['attributes' => ['id' => 11]]]),
        ]);

        self::assertSame(11, self::publisher($http)->upsertItem(self::item())['id']);
    }

    #[Test]
    public function item_match_ignores_a_query_string(): void
    {
        $http = new FakeTransport([
            self::itemList([['id' => 5, 'url' => 'https://cdn.example/pkg_x-1.2.0.zip?token=abc']]),
            self::ok(['data' => ['attributes' => ['id' => 5]]]),
        ]);

        self::assertSame(5, self::publisher($http)->upsertItem(self::item())['id']);
    }

    #[Test]
    public function item_without_environments_is_refused_before_any_request(): void
    {
        // #58: an item with no environments makes ARS emit update XML with
        // php_minimum 8.5 and a Joomla 6.1+/7-only targetplatform.
        $http = new FakeTransport([]);
        $item = self::item();
        unset($item['environments']);

        $this->expectException(\InvalidArgumentException::class);

        self::publisher($http)->upsertItem($item);
    }

    #[Test]
    public function environments_validation_matches_the_shell_contract(): void
    {
        foreach ([[1], ['1'], [1, '2', 3]] as $ok) {
            ArsPublisher::assertEnvironments($ok);
        }

        foreach ([null, [], 'null', ['abc'], [1.5], [null], 17] as $bad) {
            try {
                ArsPublisher::assertEnvironments($bad);
                self::fail('accepted invalid environments: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function publish_threads_the_release_id_into_the_item(): void
    {
        $http = new FakeTransport([
            self::releaseList([]),
            self::ok(['data' => ['attributes' => ['id' => 88]]]),
            self::itemList([]),
            self::ok(['data' => ['attributes' => ['id' => 99]]]),
        ]);

        $item = self::item();
        unset($item['release_id']);

        $result = self::publisher($http)->publish(self::release('1.2.0'), $item);

        self::assertSame(
            ['releaseId' => 88, 'itemId' => 99, 'releaseCreated' => true, 'itemCreated' => true],
            $result
        );
        self::assertStringContainsString('release_id=88', $http->calls[2]['url']);
        self::assertSame(88, json_decode((string) $http->calls[3]['body'], true)['release_id']);
    }

    #[Test]
    public function a_create_that_returns_no_id_is_an_error(): void
    {
        $http = new FakeTransport([self::releaseList([]), self::ok(['data' => ['attributes' => []]])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned no id/');

        self::publisher($http)->upsertRelease(self::release('1.2.0'));
    }

    // --- helpers ---------------------------------------------------------

    private static function publisher(HttpTransport $http): ArsPublisher
    {
        return new ArsPublisher(self::ENDPOINT, 'secret-token', $http);
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function release(string $version, array $overrides = []): array
    {
        return $overrides + [
            'category_id' => 7,
            'version'     => $version,
            'alias'       => 'x-' . str_replace('.', '-', $version),
            'maturity'    => 'stable',
            'notes'       => '<p>notes</p>',
            'published'   => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(): array
    {
        return [
            'release_id'   => 42,
            'title'        => 'pkg_x-1.2.0',
            'type'         => 'link',
            'url'          => 'https://github.com/o/r/releases/download/v1.2.0/pkg_x-1.2.0.zip',
            'environments' => [1, 2],
            'published'    => 1,
        ];
    }

    /**
     * @param list<array{id: int, version: string}> $releases
     */
    private static function releaseList(array $releases): HttpResponse
    {
        return self::ok(['data' => array_map(
            static fn (array $r): array => ['type' => 'releases', 'id' => (string) $r['id'], 'attributes' => $r],
            $releases
        )]);
    }

    /**
     * @param list<array{id: int, url: string}> $items
     */
    private static function itemList(array $items): HttpResponse
    {
        return self::ok(['data' => array_map(
            static fn (array $i): array => ['type' => 'items', 'id' => (string) $i['id'], 'attributes' => $i],
            $items
        )]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function ok(array $payload): HttpResponse
    {
        return new HttpResponse(200, json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}

/**
 * Replays canned responses in order and records what was sent.
 *
 * Running out of responses is a failure rather than a null: it means the
 * publisher made a request the test did not anticipate, which for this class
 * is the interesting kind of bug.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $calls = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new \RuntimeException("Unexpected {$method} {$url} — no canned response left.");
        }

        return $response;
    }
}
