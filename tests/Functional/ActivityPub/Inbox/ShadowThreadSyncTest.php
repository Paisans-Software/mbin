<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Inbox;

use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Enum\MagazineFollowKind;
use App\Message\ActivityPub\Inbox\ActivityMessage;
use App\Service\EntryManager;
use App\Service\MagazineMemberManager;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * An edit, a soft delete or a restore of a followed post is mirrored onto its shadow
 * thread; a purge removes the shadow and its comments through the foreign keys.
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class ShadowThreadSyncTest extends ActivityPubFunctionalTestCase
{
    use ShadowThreadFixtureTrait;

    private Magazine $circle;
    /** @var array<string, mixed> */
    private array $pageToNobody;
    /** @var array<string, mixed> */
    private array $noteToNobody;

    public function setUpRemoteEntities(): void
    {
        $this->pageToNobody = $this->addressedToNobody($this->remotePageCreate('shadowed page'));
        $this->noteToNobody = $this->addressedToNobody($this->remoteNoteCreate('shadowed note'));
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->circle = $this->magazineRepository->findOneByName('circle');
        $this->follow($this->circle, MagazineFollowKind::Both);
    }

    public function testRemoteEditUpdatesShadowTitleAndBody(): void
    {
        [$original, $shadow] = $this->createPage();
        $url = $shadow->url;

        $update = $this->updateOf($this->pageToNobody, ['name' => 'An edited title', 'content' => '<p>An edited body</p>', 'source' => ['content' => 'An edited body', 'mediaType' => 'text/markdown']]);
        $this->bus->dispatch(new ActivityMessage(json_encode($update)));

        $this->entityManager->refresh($original);
        $this->entityManager->refresh($shadow);
        self::assertSame('An edited title', $original->title);
        self::assertSame($original->title, $shadow->title);
        self::assertStringContainsString('An edited body', $original->body);
        self::assertSame($original->body, $shadow->body);
        self::assertSame($url, $shadow->url);
        self::assertSame(Entry::ENTRY_TYPE_LINK, $shadow->type);
    }

    public function testRemoteEditOfNoteRegeneratesShadowTitle(): void
    {
        [$post, $shadow] = $this->createNote();
        self::assertSame('shadowed note', $shadow->title);

        $update = $this->updateOf($this->noteToNobody, ['content' => '<p>Brand new first line</p><p>second</p>', 'source' => ['content' => "Brand new first line\n\nsecond", 'mediaType' => 'text/markdown']]);
        $this->bus->dispatch(new ActivityMessage(json_encode($update)));

        $this->entityManager->refresh($post);
        $this->entityManager->refresh($shadow);
        self::assertStringContainsString('Brand new first line', $post->body);
        self::assertSame('Brand new first line', $shadow->title);
        self::assertSame($post->body, $shadow->body);
    }

    public function testRemoteDeleteSoftDeletesShadow(): void
    {
        [$original, $shadow] = $this->createPage();
        // the original needs a comment: the author's delete of a comment-less thread is a purge
        $this->createEntryComment('a comment', $original, $this->getUserByUsername('carol'));

        $this->bus->dispatch(new ActivityMessage(json_encode($this->deleteOf($this->pageToNobody))));

        $this->entityManager->refresh($original);
        $this->entityManager->refresh($shadow);
        self::assertSame(VisibilityInterface::VISIBILITY_SOFT_DELETED, $original->visibility);
        self::assertSame($original->visibility, $shadow->visibility);
    }

    public function testRemoteDeleteOfNoteSoftDeletesShadow(): void
    {
        [$post, $shadow] = $this->createNote();
        $this->createPostComment('a comment', $post, $this->getUserByUsername('carol'));

        $this->bus->dispatch(new ActivityMessage(json_encode($this->deleteOf($this->noteToNobody))));

        $this->entityManager->refresh($post);
        $this->entityManager->refresh($shadow);
        self::assertSame(VisibilityInterface::VISIBILITY_SOFT_DELETED, $post->visibility);
        self::assertSame($post->visibility, $shadow->visibility);
    }

    public function testRestoreRestoresShadow(): void
    {
        [$original, $shadow] = $this->createPage();
        $admin = $this->userRepository->findOneByUsername('admin');
        $manager = self::getContainer()->get(EntryManager::class);

        $manager->trash($admin, $original);
        $this->entityManager->refresh($shadow);
        self::assertSame(VisibilityInterface::VISIBILITY_TRASHED, $shadow->visibility);

        $manager->restore($admin, $original);
        $this->entityManager->refresh($shadow);
        self::assertSame(VisibilityInterface::VISIBILITY_VISIBLE, $shadow->visibility);
    }

    public function testPurgeOfOriginalRemovesShadowWithComments(): void
    {
        [$original, $shadow] = $this->createPage();
        $admin = $this->userRepository->findOneByUsername('admin');
        $carol = $this->getUserByUsername('carol');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $carol, $admin);
        $this->createEntryComment('first member comment', $shadow, $carol);
        $this->createEntryComment('second member comment', $shadow, $carol);
        $shadowId = $shadow->getId();
        self::assertSame(2, $this->entityManager->getRepository(EntryComment::class)->count(['entry' => $shadowId]));

        self::getContainer()->get(EntryManager::class)->purge($admin, $original);

        $this->entityManager->clear();
        self::assertNull($this->entryRepository->find($shadowId));
        self::assertSame(0, $this->entityManager->getRepository(EntryComment::class)->count(['entry' => $shadowId]));
    }

    public function testShadowSyncDispatchesNothing(): void
    {
        [$original] = $this->createPage();
        [$post] = $this->createNote();
        $this->createEntryComment('a comment', $original, $this->getUserByUsername('carol'));
        $this->createPostComment('a comment', $post, $this->getUserByUsername('carol'));
        // creating the comments federated them to the remote author; only what follows counts
        $before = $this->testingApHttpClient->getPostedObjects();

        $this->bus->dispatch(new ActivityMessage(json_encode($this->updateOf($this->pageToNobody, ['name' => 'Edited again']))));
        $this->bus->dispatch(new ActivityMessage(json_encode($this->deleteOf($this->pageToNobody))));
        $this->bus->dispatch(new ActivityMessage(json_encode($this->updateOf($this->noteToNobody, ['content' => '<p>Edited note</p>', 'source' => ['content' => 'Edited note', 'mediaType' => 'text/markdown']]))));
        $this->bus->dispatch(new ActivityMessage(json_encode($this->deleteOf($this->noteToNobody))));

        self::assertSame($before, $this->testingApHttpClient->getPostedObjects());
    }

    /**
     * @return array{Entry, Entry}
     */
    private function createPage(): array
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));
        $original = $this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]);
        $shadow = $this->entryRepository->findOneBy(['magazine' => $this->circle]);
        self::assertNotNull($original);
        self::assertSame($original->getId(), $shadow?->shadowOfEntry?->getId());

        return [$original, $shadow];
    }

    /**
     * @return array{Post, Entry}
     */
    private function createNote(): array
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->noteToNobody)));
        $post = $this->postRepository->findOneBy(['apId' => $this->noteToNobody['object']['id']]);
        $shadow = $this->entryRepository->findOneBy(['magazine' => $this->circle, 'shadowOfPost' => $post]);
        self::assertNotNull($post);
        self::assertNotNull($shadow);

        return [$post, $shadow];
    }

    /**
     * @param array<string, mixed> $create
     * @param array<string, mixed> $changes fields of the object to replace
     *
     * @return array<string, mixed>
     */
    private function updateOf(array $create, array $changes): array
    {
        return [
            '@context' => $create['@context'],
            'id' => "https://$this->remoteDomain/activities/update-".bin2hex(random_bytes(6)),
            'type' => 'Update',
            'actor' => $create['actor'],
            'object' => array_merge($create['object'], $changes),
            'to' => $create['to'],
            'cc' => [],
        ];
    }

    /**
     * @param array<string, mixed> $create
     *
     * @return array<string, mixed>
     */
    private function deleteOf(array $create): array
    {
        return [
            '@context' => $create['@context'],
            'id' => "https://$this->remoteDomain/activities/delete-".bin2hex(random_bytes(6)),
            'type' => 'Delete',
            'actor' => $create['actor'],
            'object' => ['id' => $create['object']['id'], 'type' => 'Tombstone'],
            'to' => $create['to'],
            'cc' => [],
        ];
    }
}
