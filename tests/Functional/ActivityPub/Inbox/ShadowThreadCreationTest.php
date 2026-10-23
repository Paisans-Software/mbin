<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Inbox;

use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\Post;
use App\Enum\MagazineFollowKind;
use App\Message\ActivityPub\Inbox\ActivityMessage;
use App\Service\ActivityPub\ShadowThreadManager;
use App\Service\MagazineMemberManager;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use App\Tests\Service\RecordingHub;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * A restricted magazine that follows an actor gets a private link thread (a shadow)
 * for each post that actor publishes, while the original is filed publicly as if
 * the restricted magazine did not follow.
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class ShadowThreadCreationTest extends ActivityPubFunctionalTestCase
{
    use ShadowThreadFixtureTrait;

    private Magazine $circle;
    private Magazine $ring;
    private Magazine $garden;
    private Magazine $random;
    /** @var array<string, mixed> */
    private array $pageToNobody;
    /** @var array<string, mixed> */
    private array $pageToRemoteGroup;
    /** @var array<string, mixed> */
    private array $noteToNobody;
    /** @var array<string, mixed> */
    private array $longNoteToNobody;
    /** @var array<string, mixed> */
    private array $announcedPage;
    private string $longNoteBody;

    public function setUpRemoteEntities(): void
    {
        $this->pageToNobody = $this->addressedToNobody($this->remotePageCreate('shadowed page to nobody'));
        $this->pageToRemoteGroup = $this->remotePageCreate('shadowed page to the remote group');
        $this->noteToNobody = $this->addressedToNobody($this->remoteNoteCreate('shadowed note to nobody'));

        $this->longNoteBody = substr('Firstword '.str_repeat('lorem ipsum ', 60), 0, 600);
        $this->longNoteToNobody = $this->addressedToNobody($this->remoteNoteCreate($this->longNoteBody));

        $entry = $this->getEntryByTitle('announced page', magazine: $this->remoteMagazine, user: $this->remoteUser);
        $page = $this->pageFactory->create($entry, $this->tagLinkRepository->getTagsOfContent($entry));
        $page['to'] = [ActivityPubActivityInterface::PUBLIC_URL];
        $page['cc'] = [];
        unset($page['audience']);
        $this->testingApHttpClient->activityObjects[$page['id']] = $page;
        $this->announcedPage = [
            '@context' => ActivityPubActivityInterface::CONTEXT_URL,
            'id' => "https://$this->remoteDomain/activities/announce-of-announced-page",
            'type' => 'Announce',
            // the remote actor has no apProfileId yet while it is being set up
            'actor' => "https://$this->remoteDomain/u/remoteUser",
            'object' => $page['id'],
            'to' => [ActivityPubActivityInterface::PUBLIC_URL],
            'cc' => [],
        ];
        $this->entitiesToRemoveAfterSetup[] = $entry;
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->circle = $this->magazineRepository->findOneByName('circle');
        $this->ring = $this->magazineRepository->findOneByName('ring');
        $this->garden = $this->magazineRepository->findOneByName('garden');
        $this->random = $this->magazineRepository->findOneByName('random');
        $this->follow($this->circle, MagazineFollowKind::Both);
    }

    public function testFollowedActorPageCreatesShadowAndPublicOriginal(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]);
        self::assertNotNull($original);
        self::assertSame($this->random->getId(), $original->magazine->getId());

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        $shadow = $shadows[0];
        self::assertSame($original->getId(), $shadow->shadowOfEntry?->getId());
        self::assertNull($shadow->shadowOfPost);
        self::assertSame(Entry::ENTRY_TYPE_LINK, $shadow->type);
        self::assertSame($this->expectedUrl($this->pageToNobody['object']), $shadow->url);
        self::assertSame($this->remoteUser->getId(), $shadow->user->getId());
        self::assertNull($shadow->apId);
        self::assertSame($original->title, $shadow->title);
        self::assertSame($original->body, $shadow->body);
        self::assertFalse($shadow->isOc);
        self::assertSame(0, $this->entryRepository->count(['magazine' => $this->garden]));

        $this->entityManager->refresh($this->circle);
        self::assertSame(1, $this->circle->entryCount);
    }

    public function testFollowedActorNoteCreatesShadowOfPost(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->noteToNobody)));

        $post = $this->postRepository->findOneBy(['apId' => $this->noteToNobody['object']['id']]);
        self::assertNotNull($post);
        self::assertSame($this->random->getId(), $post->magazine->getId());

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        self::assertSame($post->getId(), $shadows[0]->shadowOfPost?->getId());
        self::assertNull($shadows[0]->shadowOfEntry);
        self::assertSame('shadowed note to nobody', $shadows[0]->title);
        self::assertSame($this->expectedUrl($this->noteToNobody['object']), $shadows[0]->url);
        self::assertSame(Entry::ENTRY_TYPE_LINK, $shadows[0]->type);
    }

    public function testNoteTitleGeneratedAndTruncated(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->longNoteToNobody)));

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        $title = $shadows[0]->title;
        self::assertLessThanOrEqual(Entry::MAX_TITLE_LENGTH, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
        self::assertStringStartsWith('Firstword ', $title);
        // cut on a word boundary: the text before the ellipsis ends with a whole word
        self::assertMatchesRegularExpression('/(lorem|ipsum)…$/', $title);
    }

    public function testPostTitleFallsBackWhenTheBodyHasNoText(): void
    {
        $manager = self::getContainer()->get(ShadowThreadManager::class);
        $user = $this->userRepository->findOneByUsername('admin');

        self::assertSame('Untitled post', $manager->titleForPost(new Post('![](https://example.com/a.png)', $this->random, $user, false)));
        self::assertSame('Untitled post', $manager->titleForPost(new Post(null, $this->random, $user, false)));
        self::assertSame('bold and a link', $manager->titleForPost(new Post("\n\n**bold**   and [a link](https://example.com)\nsecond line", $this->random, $user, false)));
    }

    public function testJavascriptUrlFallsBackToTheId(): void
    {
        self::assertSame($this->pageToNobody['object']['id'], $this->shadowUrlFor('javascript:alert(1)'));
    }

    public function testForeignHostUrlFallsBackToTheId(): void
    {
        self::assertSame($this->pageToNobody['object']['id'], $this->shadowUrlFor([['type' => 'Link', 'href' => 'https://evil.example/phish']]));
    }

    public function testSameHostUrlRejectedByTheCleanerFallsBackToTheId(): void
    {
        self::assertSame($this->pageToNobody['object']['id'], $this->shadowUrlFor("https://$this->remoteDomain/some page"));
    }

    public function testSameHostUrlIsCleanedOfTrackingParameters(): void
    {
        self::assertSame(
            "https://$this->remoteDomain/some/page?keep=1",
            $this->shadowUrlFor("https://$this->remoteDomain/some/page?utm_source=feed&keep=1"),
        );
    }

    public function testOriginalFiledInLocalPublicMagazineThatAlsoFollows(): void
    {
        // garden is public, so its follow files the original there and CreateHandler goes on
        // to record the Create and announce it; circle still gets its shadow
        $this->follow($this->garden, MagazineFollowKind::Both);

        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]);
        self::assertNotNull($original);
        self::assertSame($this->garden->getId(), $original->magazine->getId());
        self::assertNotNull($this->activityRepository->findFirstActivitiesByTypeAndObject('Create', $original), 'the Create was recorded');

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        self::assertSame($original->getId(), $shadows[0]->shadowOfEntry?->getId());
    }

    public function testOriginalAddressedToRemoteCommunityStillShadowed(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToRemoteGroup)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->pageToRemoteGroup['object']['id']]);
        self::assertNotNull($original);
        self::assertSame($this->remoteMagazine->getId(), $original->magazine->getId());

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        self::assertSame($original->getId(), $shadows[0]->shadowOfEntry?->getId());
    }

    public function testTwoRestrictedMagazinesEachGetAShadow(): void
    {
        $this->follow($this->ring, MagazineFollowKind::Create);

        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]);
        self::assertNotNull($original);
        foreach ([$this->circle, $this->ring] as $magazine) {
            $shadows = $this->entryRepository->findBy(['magazine' => $magazine]);
            self::assertCount(1, $shadows, $magazine->name);
            self::assertSame($original->getId(), $shadows[0]->shadowOfEntry?->getId(), $magazine->name);
        }
    }

    public function testRedeliveryCreatesNoSecondShadow(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));
        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        self::assertSame(1, $this->entryRepository->count(['magazine' => $this->circle]));
        self::assertSame(1, $this->entryRepository->count(['apId' => $this->pageToNobody['object']['id']]));
    }

    public function testFollowKindIsRespected(): void
    {
        $follow = $this->entityManager->getRepository(MagazineFollow::class)->findOneBy(['magazine' => $this->circle]);
        $follow->kind = MagazineFollowKind::Create;
        $this->entityManager->flush();

        $this->bus->dispatch(new ActivityMessage(json_encode($this->announcedPage)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->announcedPage['object']]);
        self::assertNotNull($original, 'positive control: the announced page is filed publicly');
        self::assertSame(0, $this->entryRepository->count(['magazine' => $this->circle]));
    }

    public function testAnnounceKindFollowShadowsAnnouncedPage(): void
    {
        // the counterpart of testFollowKindIsRespected, so that test cannot pass vacuously
        $follow = $this->entityManager->getRepository(MagazineFollow::class)->findOneBy(['magazine' => $this->circle]);
        $follow->kind = MagazineFollowKind::Announce;
        $this->entityManager->flush();

        $this->bus->dispatch(new ActivityMessage(json_encode($this->announcedPage)));

        $original = $this->entryRepository->findOneBy(['apId' => $this->announcedPage['object']]);
        self::assertNotNull($original);
        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);
        self::assertSame($original->getId(), $shadows[0]->shadowOfEntry?->getId());
    }

    public function testUnfollowedActorCreatesNoShadow(): void
    {
        $follow = $this->entityManager->getRepository(MagazineFollow::class)->findOneBy(['magazine' => $this->circle]);
        $this->entityManager->remove($follow);
        $this->entityManager->flush();

        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        self::assertNotNull($this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]));
        self::assertSame(0, $this->entryRepository->count(['magazine' => $this->circle]));
    }

    public function testNoNotificationsOrDeliveriesForShadowCreation(): void
    {
        $carol = $this->getUserByUsername('carol');
        $carol->notifyOnNewEntry = true;
        $this->entityManager->flush();
        $this->magazineManager->subscribe($this->circle, $carol);
        $admin = $this->userRepository->findOneByUsername('admin');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $carol, $admin);
        $this->settingsManager->set('KBIN_MERCURE_ENABLED', true);
        $hub = self::getContainer()->get(RecordingHub::class);
        $before = $this->notificationRepository->countUnreadNotifications($carol);
        $hub->updates = [];

        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));

        self::assertSame(1, $this->entryRepository->count(['magazine' => $this->circle]));
        self::assertSame($before, $this->notificationRepository->countUnreadNotifications($carol));
        self::assertSame([], $this->testingApHttpClient->getPostedObjects());
        $circleTopics = array_filter($hub->updates, fn ($update) => str_contains(implode(' ', $update->getTopics()).$update->getData(), '/m/circle'));
        self::assertSame([], $circleTopics);

        // positive control: a thread created the normal way does notify carol
        $this->createEntry('a normal circle thread', $this->circle, $admin);
        self::assertSame($before + 1, $this->notificationRepository->countUnreadNotifications($carol));
    }

    /**
     * @param string|array<mixed> $url
     */
    private function shadowUrlFor(string|array $url): ?string
    {
        $create = $this->pageToNobody;
        $create['object']['url'] = $url;
        $this->bus->dispatch(new ActivityMessage(json_encode($create)));

        $shadows = $this->entryRepository->findBy(['magazine' => $this->circle]);
        self::assertCount(1, $shadows);

        return $shadows[0]->url;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function expectedUrl(array $object): string
    {
        if (isset($object['url']) && \is_string($object['url'])) {
            return $object['url'];
        }

        return $object['id'];
    }
}
