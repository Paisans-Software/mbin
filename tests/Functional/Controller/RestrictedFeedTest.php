<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Magazine;
use App\Enum\MagazineAccess;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * RSS is never served for a restricted magazine, and the feed query always runs as an
 * anonymous viewer, whatever the session.
 */
class RestrictedFeedTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private Magazine $vault;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->vault = $this->getMagazineByName('vault', $admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();
        $this->createEntry('Vaulted entry title', $this->vault, $admin);
        $this->createEntry('Public entry title', $this->open, $admin);
        $this->createPost('Public post body', $this->open, $admin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function restrictedMagazines(): iterable
    {
        yield 'private' => ['circle'];
        yield 'hidden' => ['vault'];
    }

    #[DataProvider('restrictedMagazines')]
    public function testRssForRestrictedMagazineIs404(string $name): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/rss?magazine='.$name);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/rss?magazine='.$name.'&content=microblog');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRssForRestrictedMagazineIsLikeAMissingOne(): void
    {
        $this->client->loginUser($this->alice);
        foreach (['circle', 'vault'] as $name) {
            $this->client->request('GET', '/rss?magazine=nonexistent');
            $missing = $this->production404();
            self::assertSame(404, $missing[0]);
            self::assertStringEndsWith('NotFoundHttpException', $missing[2]);
            $this->client->request('GET', '/rss?magazine='.$name);

            self::assertSame($missing, $this->production404(), $name);
        }
    }

    /**
     * Debug mode dumps the exception with its message, file and line; production serves
     * a page chosen by the status code. Compare the status, the content type and the
     * exception class (the first line of the dump), which is what both answers share.
     *
     * @return array{int, ?string, string}
     */
    private function production404(): array
    {
        $response = $this->client->getResponse();
        preg_match('/^(\S+) \{#\d+/', (string) $response->getContent(), $class);

        return [$response->getStatusCode(), $response->headers->get('Content-Type'), $class[1] ?? ''];
    }

    public function testDomainAndTagRssOmitRestrictedContent(): void
    {
        $admin = $this->getUserByUsername('admin');
        $this->createEntry('Secret tagged entry', $this->circle, $admin, 'https://secret.example/tagged', body: 'about #feedtag');
        $this->createEntry('Public tagged entry', $this->open, $admin, 'https://secret.example/public', body: 'about #feedtag');

        $this->client->loginUser($this->alice);
        foreach (['/rss?domain=secret.example', '/rss?tag=feedtag'] as $path) {
            $body = $this->rss($path);
            self::assertStringContainsString('Public tagged entry', $body, $path);
            self::assertStringNotContainsString('Secret tagged entry', $body, $path);
            self::assertStringNotContainsString('Secret entry title', $body, $path);
        }
    }

    public function testRssForPublicMagazineWorks(): void
    {
        $this->client->loginUser($this->alice);
        $body = $this->rss('/rss?magazine=open');

        self::assertStringContainsString('Public entry title', $body);
    }

    public function testInstanceRssOmitsRestrictedContent(): void
    {
        $this->client->loginUser($this->alice);
        $body = $this->rss('/rss');

        self::assertStringContainsString('Public entry title', $body);
        self::assertStringNotContainsString('Secret entry title', $body);
        self::assertStringNotContainsString('Vaulted entry title', $body);
    }

    public function testInstanceMicroblogRssOmitsRestrictedContent(): void
    {
        $this->client->loginUser($this->alice);
        $body = $this->rss('/rss?content=microblog');

        self::assertStringContainsString('Public post body', $body);
        self::assertStringNotContainsString('Secret post body', $body);
    }

    public function testUserRssOmitsRestrictedContent(): void
    {
        $this->client->loginUser($this->alice);
        $body = $this->rss('/rss?user=admin');

        self::assertStringContainsString('Public entry title', $body);
        self::assertStringNotContainsString('Secret entry title', $body);
        self::assertStringNotContainsString('Vaulted entry title', $body);
    }

    public function testAdminSessionStillGetsPublicOnlyRss(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));
        $body = $this->rss('/rss');

        self::assertStringContainsString('Public entry title', $body);
        self::assertStringNotContainsString('Secret entry title', $body);
        self::assertStringNotContainsString('Vaulted entry title', $body);
    }

    private function rss(string $path): string
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $this->client->getResponse()->getContent();
    }
}
