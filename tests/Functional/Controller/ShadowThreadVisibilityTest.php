<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\User;
use App\Enum\MagazineFollowKind;
use App\Message\ActivityPub\Inbox\ActivityMessage;
use App\Service\MagazineMemberManager;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use App\Tests\Functional\ActivityPub\Inbox\ShadowThreadFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Only members see a shadow thread; the original stays public and its page lists the
 * shadow as a cross post for members alone. It needs the inbound ActivityPub fixtures,
 * so it builds on the shadow thread fixture trait rather than the plain web test case.
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class ShadowThreadVisibilityTest extends ActivityPubFunctionalTestCase
{
    use ShadowThreadFixtureTrait;

    /** @var array<string, mixed> */
    private array $firstPage;
    /** @var array<string, mixed> */
    private array $secondPage;
    /** @var array<string, mixed> */
    private array $replyToFirstPage;
    private User $alice;
    private User $bob;
    private Entry $original;
    private Entry $shadow;

    public function setUpRemoteEntities(): void
    {
        $this->firstPage = $this->addressedToNobody($this->remotePageCreate('shadowed page for the circle'));
        $page = $this->lastRemotePage;
        self::assertInstanceOf(Entry::class, $page);

        $reply = $this->createEntryComment('a third party reply to the page', $page, $this->remoteSubscriber);
        $createReply = $this->createWrapper->build($reply);
        $this->replyToFirstPage = $this->addressedToNobody($this->activityJsonBuilder->buildActivityJson($createReply));
        // the replier is the remote subscriber, an actor on another instance than the page's author
        $replier = "https://$this->remoteSubDomain/u/remoteSubscriber";
        $this->replyToFirstPage['actor'] = $replier;
        $this->replyToFirstPage['object']['attributedTo'] = $replier;
        $this->entitiesToRemoveAfterSetup[] = $createReply;
        $this->entitiesToRemoveAfterSetup[] = $reply;

        $this->secondPage = $this->addressedToNobody($this->remotePageCreate('a later page for the circle'));
    }

    public function setUp(): void
    {
        parent::setUp();
        $circle = $this->magazineRepository->findOneByName('circle');
        $this->follow($circle, MagazineFollowKind::Both);

        $admin = $this->userRepository->findOneByUsername('admin');
        $this->alice = $this->getUserByUsername('alice');
        $this->bob = $this->getUserByUsername('bob');
        static::getContainer()->get(MagazineMemberManager::class)->add($circle, $this->alice, $admin);

        $this->bus->dispatch(new ActivityMessage(json_encode($this->firstPage)));
        $this->original = $this->entryRepository->findOneBy(['apId' => $this->firstPage['object']['id']]);
        $this->shadow = $this->entryRepository->findOneBy(['magazine' => $circle]);
        self::assertSame($this->original->getId(), $this->shadow->shadowOfEntry?->getId());
    }

    public function testNonMemberSeesOriginalButNotShadow(): void
    {
        $this->client->loginUser($this->bob);

        $all = $this->get('/all');
        self::assertStringContainsString($this->entryPath($this->original), $all);
        self::assertStringNotContainsString($this->entryPath($this->shadow), $all);
        self::assertStringNotContainsString('/m/circle/t/', $all);

        $profile = $this->get('/u/'.$this->remoteUser->username.'/threads');
        self::assertStringContainsString($this->entryPath($this->original), $profile);
        self::assertStringNotContainsString($this->entryPath($this->shadow), $profile);

        $page = $this->get($this->entryPath($this->original));
        self::assertStringContainsString($this->original->title, $page);
        self::assertStringNotContainsString($this->entryPath($this->shadow), $page, 'the original must not list the shadow as a cross post');
        self::assertStringNotContainsString('/m/circle/t/', $page);

        $this->client->request('GET', $this->entryPath($this->shadow));
        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberSeesBothAndCrossPostLink(): void
    {
        $this->client->loginUser($this->alice);

        $circle = $this->get('/m/circle');
        self::assertStringContainsString($this->entryPath($this->shadow), $circle);

        $all = $this->get('/all');
        self::assertStringContainsString($this->entryPath($this->original), $all);

        $page = $this->get($this->entryPath($this->original));
        self::assertStringContainsString($this->entryPath($this->shadow), $page, 'the original should list the shadow as a cross post');

        $this->get($this->entryPath($this->shadow));
        self::assertResponseIsSuccessful();
    }

    public function testLocalFollowerOfAuthorStillSeesNewPosts(): void
    {
        $this->userManager->follow($this->bob, $this->remoteUser);
        $this->client->loginUser($this->bob);

        $this->bus->dispatch(new ActivityMessage(json_encode($this->secondPage)));
        $later = $this->entryRepository->findOneBy(['apId' => $this->secondPage['object']['id']]);
        self::assertNotNull($later);
        self::assertNotSame($this->shadow->getId(), $later->getId());
        self::assertSame(2, $this->entryRepository->count(['magazine' => $this->shadow->magazine]), 'the circle shadows the later page too');

        $feed = $this->get('/sub');
        self::assertStringContainsString($this->entryPath($later), $feed);
        self::assertStringContainsString($this->entryPath($this->original), $feed);
        self::assertStringNotContainsString('/m/circle/t/', $feed);
    }

    public function testThirdPartyRemoteReplyAttachesToOriginal(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->replyToFirstPage)));

        $reply = $this->entryCommentRepository->findOneBy(['apId' => $this->replyToFirstPage['object']['id']]);
        self::assertInstanceOf(EntryComment::class, $reply);
        self::assertSame($this->original->getId(), $reply->entry->getId());
        self::assertSame(0, $this->entryCommentRepository->count(['entry' => $this->shadow]));
        $this->entityManager->refresh($this->shadow);
        self::assertSame(0, $this->shadow->commentCount);
    }

    private function entryPath(Entry $entry): string
    {
        return "/m/{$entry->magazine->name}/t/{$entry->getId()}";
    }

    private function get(string $path): string
    {
        $this->client->request('GET', $path);

        return (string) $this->client->getResponse()->getContent();
    }
}
