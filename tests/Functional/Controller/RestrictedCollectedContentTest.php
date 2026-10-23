<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\DTO\MagazineBanDto;
use App\Entity\EntryCreatedNotification;
use App\Entity\PostCommentReplyNotification;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a member collected while they could see a private magazine (bookmarks,
 * notifications) is not readable once they are removed or banned.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedCollectedContentTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $list = $this->bookmarkManager->createList($this->alice, 'saved');
        foreach ([$this->entry, $this->entryComment, $this->post, $this->postComment] as $content) {
            $this->bookmarkManager->addBookmark($this->alice, $list, $content);
        }
        $this->entityManager->persist(new EntryCreatedNotification($this->alice, $this->entry));
        $this->entityManager->persist(new PostCommentReplyNotification($this->alice, $this->postComment));
        $this->entityManager->flush();
    }

    private const array SECRETS = ['Secret entry title', 'Secret entry comment', 'Secret post body', 'Secret post comment'];
    private const array NOTIFIED = ['Secret entry title', 'Secret post comment'];

    public function testActiveMemberSeesCollectedContent(): void
    {
        $this->client->loginUser($this->alice);

        $this->client->request('GET', '/bookmark-lists/show/saved');
        self::assertResponseIsSuccessful();
        foreach (self::SECRETS as $secret) {
            self::assertStringContainsString($secret, (string) $this->client->getResponse()->getContent());
        }

        $this->client->request('GET', '/settings/notifications');
        self::assertResponseIsSuccessful();
        foreach (self::NOTIFIED as $secret) {
            self::assertStringContainsString($secret, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lossProvider(): array
    {
        return ['removed' => ['remove'], 'banned' => ['ban']];
    }

    #[DataProvider('lossProvider')]
    public function testFormerMemberNoLongerSeesCollectedContent(string $loss): void
    {
        if ('remove' === $loss) {
            static::getContainer()->get(MagazineMemberManager::class)->remove($this->circle, $this->alice);
        } else {
            $this->magazineManager->ban($this->circle, $this->alice, $this->getUserByUsername('admin'), MagazineBanDto::create('test ban'));
        }

        $this->client->loginUser($this->alice);

        $this->client->request('GET', '/bookmark-lists/show/saved');
        self::assertResponseIsSuccessful();
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        }

        $this->client->request('GET', '/settings/notifications');
        self::assertResponseIsSuccessful();
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        }
    }
}
