<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Entry;
use App\Entity\NotificationSettings;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedContentByIdTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ajaxFetchProvider(): array
    {
        return [
            'entry' => ['/ajax/fetch_entry/%entry%'],
            'entry comment' => ['/ajax/fetch_entry_comment/%entryComment%'],
            'post' => ['/ajax/fetch_post/%post%'],
            'post comment' => ['/ajax/fetch_post_comment/%postComment%'],
            'post comments' => ['/ajax/fetch_post_comments/%post%'],
        ];
    }

    private function path(string $template): string
    {
        return strtr($template, [
            '%entry%' => (string) $this->entry->getId(),
            '%entryComment%' => (string) $this->entryComment->getId(),
            '%post%' => (string) $this->post->getId(),
            '%postComment%' => (string) $this->postComment->getId(),
        ]);
    }

    #[DataProvider('ajaxFetchProvider')]
    public function testAjaxFetchById(string $template): void
    {
        $path = $this->path($template);

        $this->client->loginUser($this->bob);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousAjaxFetchIs404(): void
    {
        $this->client->request('GET', $this->path('/ajax/fetch_entry/%entry%'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCommentByIdInRestrictedMagazineIs404(): void
    {
        $path = \sprintf('/m/open/t/%d/-/comment/%d/votes/up', $this->entry->getId(), $this->entryComment->getId());

        $this->client->loginUser($this->bob);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
    }

    public function testVoteOnRestrictedEntryIs404AndNotCounted(): void
    {
        $before = $this->entry->countUpVotes();

        $this->client->loginUser($this->bob);
        $this->client->request('POST', '/ev/'.$this->entry->getId().'/1');
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->entityManager->find(Entry::class, $this->entry->getId())->countUpVotes());

        foreach (['/ecv/'.$this->entryComment->getId().'/1', '/pv/'.$this->post->getId().'/1'] as $path) {
            $this->client->request('POST', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testMemberCanVoteOnRestrictedEntry(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('POST', '/ev/'.$this->entry->getId().'/1');
        self::assertResponseStatusCodeSame(302);

        self::assertSame(1, $this->entityManager->find(Entry::class, $this->entry->getId())->countUpVotes());
    }

    public function testBoostFavouriteBookmarkReportOnRestrictedEntryAre404(): void
    {
        $id = $this->entry->getId();
        $this->client->loginUser($this->bob);

        foreach ([['POST', "/eb/$id"], ['POST', "/ef/$id"], ['GET', "/er/$id"], ['GET', "/bos/$id/entry"],
            ['GET', "/bor/$id/entry"], ['GET', "/rbo/$id/entry"],
            ['POST', '/ecb/'.$this->entryComment->getId()], ['GET', '/ecr/'.$this->entryComment->getId()],
            ['POST', '/pb/'.$this->post->getId()], ['GET', '/bos/'.$this->post->getId().'/post'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(404, "$method $path");
        }

        $entry = $this->entityManager->find(Entry::class, $id);
        self::assertSame(0, $entry->countUpVotes());
        self::assertCount(0, $entry->favourites);
    }

    public function testMemberCanReportRestrictedEntry(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/er/'.$this->entry->getId());
        self::assertResponseIsSuccessful();
    }

    public function testVotersListOfRestrictedEntryIs404(): void
    {
        $this->client->loginUser($this->bob);

        $paths = [
            \sprintf('/m/circle/t/%d/-/votes/up', $this->entry->getId()),
            \sprintf('/m/open/t/%d/-/votes/up', $this->entry->getId()),
            \sprintf('/m/open/t/%d/-/favourites', $this->entry->getId()),
            \sprintf('/m/open/p/%d/-/votes', $this->post->getId()),
            \sprintf('/m/open/p/%d/-/favourites', $this->post->getId()),
            \sprintf('/m/open/p/%d/-/reply/%d/votes', $this->post->getId(), $this->postComment->getId()),
            \sprintf('/m/open/p/%d/-/reply/%d/favourites', $this->post->getId(), $this->postComment->getId()),
            \sprintf('/m/open/t/%d/-/comment/%d/favourites', $this->entry->getId(), $this->entryComment->getId()),
        ];
        foreach ($paths as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->loginUser($this->alice);
        $this->client->request('GET', \sprintf('/m/open/t/%d/-/votes/up', $this->entry->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testDuplicateUrlCheckOmitsRestrictedEntries(): void
    {
        $body = json_encode(['url' => 'https://secret.example/link']);

        $this->client->loginUser($this->bob);
        $this->client->request('POST', '/ajax/fetch_duplicates', content: $body);
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['total']);
        self::assertStringNotContainsString('Secret entry title', $this->client->getResponse()->getContent());

        $this->client->loginUser($this->alice);
        $this->client->request('POST', '/ajax/fetch_duplicates', content: $body);
        self::assertSame(1, json_decode($this->client->getResponse()->getContent(), true)['total']);
    }

    public function testNotificationSettingOnRestrictedTargetsIs404AndNotSaved(): void
    {
        $paths = [
            '/cns/entry/'.$this->entry->getId().'/Loud',
            '/cns/post/'.$this->post->getId().'/Loud',
            '/cns/magazine/'.$this->circle->getId().'/Loud',
        ];

        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/cns/entry/999999/Loud', server: ['HTTP_REFERER' => '/']);
        self::assertResponseStatusCodeSame(404);
        foreach ($paths as $path) {
            $this->client->request('GET', $path, server: ['HTTP_REFERER' => '/']);
            self::assertResponseStatusCodeSame(404, $path);
        }
        self::assertSame(0, $this->entityManager->getRepository(NotificationSettings::class)->count(['user' => $this->bob->getId()]));

        $this->client->loginUser($this->alice);
        foreach ($paths as $path) {
            $this->client->request('GET', $path, server: ['HTTP_REFERER' => '/']);
            self::assertResponseStatusCodeSame(302, $path);
        }
    }

    public function testBookmarkMenuRefreshOnRestrictedEntryIs404(): void
    {
        $xhr = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/blr/999999/entry', server: $xhr);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/blr/'.$this->entry->getId().'/entry', server: $xhr);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/blr/'.$this->entry->getId().'/entry', server: $xhr);
        self::assertResponseIsSuccessful();
    }
}
