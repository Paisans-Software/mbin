<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Inbox;

use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\MagazineSubscription;
use App\Enum\MagazineAccess;
use App\Message\ActivityPub\Inbox\ActivityMessage;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * A restricted magazine takes nothing in from the fediverse: no remote followers,
 * no content addressed to it, and its own follows do not file anything (13c gives
 * them shadows instead).
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class RestrictedInboundTest extends ActivityPubFunctionalTestCase
{
    private Magazine $circle;
    private Magazine $random;
    private Magazine $open;
    /** @var array<string, mixed> */
    private array $createAddressedToCircle;
    /** @var array<string, mixed> */
    private array $createAddressedToNobody;

    public function setUpRemoteEntities(): void
    {
        $this->createAddressedToCircle = $this->remoteCreate('remote entry addressed to circle');
        // the helper rewrites to/audience; an unsaved magazine only lends it the name
        $this->createAddressedToCircle = $this->RewriteTargetFieldsToLocal(
            new Magazine('circle', 'circle', null, null, null, false, false, null),
            $this->createAddressedToCircle,
        );

        $this->createAddressedToNobody = $this->remoteCreate('remote entry addressed to nobody');
        foreach ([&$this->createAddressedToNobody, &$this->createAddressedToNobody['object']] as &$activity) {
            unset($activity['audience']);
            $activity['to'] = [ActivityPubActivityInterface::PUBLIC_URL];
        }
        unset($activity);
    }

    public function setUpLocalEntities(): void
    {
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $circle = $this->getMagazineByName('circle', $admin);
        $circle->access = MagazineAccess::Private;
        $this->getMagazineByName('random', $admin);
        $this->getMagazineByName('open', $admin);
        $this->entityManager->flush();
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->circle = $this->magazineRepository->findOneByName('circle');
        $this->random = $this->magazineRepository->findOneByName('random');
        $this->open = $this->magazineRepository->findOneByName('open');
    }

    public function testRemoteFollowOfRestrictedMagazineIsDroppedLikeAFollowOfAMissingOne(): void
    {
        foreach (['nosuchmag', 'circle'] as $name) {
            $this->bus->dispatch(new ActivityMessage(json_encode([
                '@context' => ActivityPubActivityInterface::CONTEXT_URL,
                'id' => "https://$this->remoteDomain/f/object/follow-$name",
                'type' => 'Follow',
                'actor' => $this->remoteUser->apProfileId,
                'object' => "https://$this->localDomain/m/$name",
            ])));

            self::assertSame([], array_map(fn (array $posted) => $posted['payload']['type'], $this->testingApHttpClient->getPostedObjects()), $name);
        }

        self::assertNull($this->entityManager->getRepository(MagazineSubscription::class)->findOneBy([
            'user' => $this->remoteUser,
            'magazine' => $this->circle,
        ])?->getId());
    }

    public function testFollowFromAReadOnlyInstanceIsDroppedToo(): void
    {
        // a read only instance gets a Reject for any Follow of a magazine that exists,
        // so answering one here would tell it circle exists
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $this->settingsManager->save($settings);
        $instance = $this->instanceRepository->getOrCreateInstance($this->remoteDomain);
        $this->instanceManager->allowInstanceFederation($instance);
        $this->instanceManager->markInstanceReadOnly($instance);

        $this->bus->dispatch(new ActivityMessage(json_encode([
            '@context' => ActivityPubActivityInterface::CONTEXT_URL,
            'id' => "https://$this->remoteDomain/f/object/follow-circle-readonly",
            'type' => 'Follow',
            'actor' => $this->remoteUser->apProfileId,
            'object' => "https://$this->localDomain/m/circle",
        ])));

        self::assertSame([], $this->testingApHttpClient->getPostedObjects());
    }

    public function testContentAddressedToRestrictedMagazineIsDropped(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->createAddressedToCircle)));

        self::assertNull($this->entryRepository->findOneBy(['apId' => $this->createAddressedToCircle['object']['id']])?->magazine->name);
        self::assertSame(0, $this->entryRepository->count(['magazine' => $this->circle]));
        self::assertSame(0, $this->entryRepository->count(['magazine' => $this->random]), 'nor may it fall back to random');
    }

    public function testReplyToRestrictedContentIsDropped(): void
    {
        // a local id is guessable, and a reply is filed under its parent's magazine
        // without asking where it was addressed
        $entry = $this->createEntry('circle entry', $this->circle, $this->userRepository->findOneByUsername('admin'));
        $parentUrl = $this->urlGenerator->generate('ap_entry', ['magazine_name' => 'circle', 'entry_id' => $entry->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $replyId = "https://$this->remoteDomain/comment/injected";

        $this->bus->dispatch(new ActivityMessage(json_encode([
            '@context' => ActivityPubActivityInterface::CONTEXT_URL,
            'id' => "https://$this->remoteDomain/activity/injected",
            'type' => 'Create',
            'actor' => $this->remoteUser->apProfileId,
            'to' => [ActivityPubActivityInterface::PUBLIC_URL],
            'object' => [
                'id' => $replyId,
                'type' => 'Note',
                'attributedTo' => $this->remoteUser->apProfileId,
                'inReplyTo' => $parentUrl,
                'content' => '<p>an injected reply</p>',
                'to' => [ActivityPubActivityInterface::PUBLIC_URL],
                'cc' => [],
                'published' => '2026-10-23T00:00:00+00:00',
            ],
        ])));

        self::assertNull($this->entryCommentRepository->findOneBy(['apId' => $replyId])?->getId());
    }

    public function testRestrictedFollowDoesNotFileContent(): void
    {
        $follow = new MagazineFollow($this->circle, $this->remoteUser);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $this->bus->dispatch(new ActivityMessage(json_encode($this->createAddressedToNobody)));

        $entry = $this->entryRepository->findOneBy(['apId' => $this->createAddressedToNobody['object']['id']]);
        self::assertNotNull($entry);
        self::assertSame($this->random->getId(), $entry->magazine->getId());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reactionProvider(): array
    {
        return ['Like' => ['Like'], 'Dislike' => ['Dislike'], 'Announce' => ['Announce'], 'Flag' => ['Flag']];
    }

    /**
     * A remote actor is never a viewer, so its reactions to restricted content are
     * dropped before anything is stored; the same reaction to public content is kept.
     */
    #[DataProvider('reactionProvider')]
    public function testRemoteReactionToRestrictedContentIsDropped(string $type): void
    {
        $admin = $this->userRepository->findOneByUsername('admin');
        $restricted = $this->createEntry("circle entry for $type", $this->circle, $admin);
        $public = $this->createEntry("open entry for $type", $this->open, $admin);

        foreach (['circle' => $restricted, 'open' => $public] as $name => $entry) {
            $url = $this->urlGenerator->generate('ap_entry', ['magazine_name' => $name, 'entry_id' => $entry->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->bus->dispatch(new ActivityMessage(json_encode([
                '@context' => ActivityPubActivityInterface::CONTEXT_URL,
                'id' => "https://$this->remoteDomain/activity/$type-$name",
                'type' => $type,
                'actor' => $this->remoteUser->apProfileId,
                'object' => 'Flag' === $type ? [$url] : $url,
                'to' => [ActivityPubActivityInterface::PUBLIC_URL],
            ])));
        }

        $this->entityManager->refresh($restricted);
        $this->entityManager->refresh($public);
        self::assertSame(0, $this->reactionCount($type, $restricted), 'restricted');
        self::assertSame(1, $this->reactionCount($type, $public), 'positive control: public');
    }

    private function reactionCount(string $type, Entry $entry): int
    {
        return match ($type) {
            'Like' => $entry->favourites->count(),
            'Dislike' => $entry->getDownVotes()->count(),
            'Announce' => $entry->getUpVotes()->count(),
            'Flag' => null === $this->reportRepository->findBySubject($entry) ? 0 : 1,
            default => throw new \LogicException("no count for $type"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteCreate(string $title): array
    {
        $entry = $this->getEntryByTitle($title, magazine: $this->remoteMagazine, user: $this->remoteUser);
        $createActivity = $this->createWrapper->build($entry);
        $create = $this->activityJsonBuilder->buildActivityJson($createActivity);
        // no other magazine may be named, or the object would be filed there instead
        $create['cc'] = [];
        $create['object']['cc'] = [];

        $this->entitiesToRemoveAfterSetup[] = $createActivity;
        $this->entitiesToRemoveAfterSetup[] = $entry;

        return $create;
    }
}
