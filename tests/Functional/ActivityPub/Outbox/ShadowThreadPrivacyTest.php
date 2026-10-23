<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Outbox;

use App\Entity\Contracts\VotableInterface;
use App\Entity\Entry;
use App\Entity\MagazineSubscription;
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
 * What a member does on a shadow thread stays on the instance: replies, votes, boosts,
 * favourites and mentions are never delivered, and the shadow is not an ActivityPub
 * object. The remote author and a remote follower of the member are both reachable, so
 * every fan out path has somebody to deliver to; the same actions on the public
 * original are the control that shows a delivery would be seen.
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class ShadowThreadPrivacyTest extends ActivityPubFunctionalTestCase
{
    use ShadowThreadFixtureTrait;

    /** @var array<string, mixed> */
    private array $pageToNobody;
    private User $alice;
    private string $mention;
    private Entry $original;
    private Entry $shadow;
    private int $baseline;

    public function setUpRemoteEntities(): void
    {
        $this->pageToNobody = $this->addressedToNobody($this->remotePageCreate('shadowed page'));
    }

    public function setUp(): void
    {
        parent::setUp();
        $circle = $this->magazineRepository->findOneByName('circle');
        $this->follow($circle, MagazineFollowKind::Both);

        $admin = $this->userRepository->findOneByUsername('admin');
        $this->alice = $this->getUserByUsername('alice');
        static::getContainer()->get(MagazineMemberManager::class)->add($circle, $this->alice, $admin);

        // somebody remote to deliver to on every path: the circle's subscribers and alice's followers
        $this->entityManager->persist(new MagazineSubscription($this->remoteSubscriber, $circle));
        $this->entityManager->flush();
        $this->userManager->follow($this->remoteSubscriber, $this->alice);
        $this->userManager->follow($this->remoteUser, $this->alice);

        $this->bus->dispatch(new ActivityMessage(json_encode($this->pageToNobody)));
        $this->original = $this->entryRepository->findOneBy(['apId' => $this->pageToNobody['object']['id']]);
        $this->shadow = $this->entryRepository->findOneBy(['magazine' => $circle]);
        self::assertSame($this->original->getId(), $this->shadow->shadowOfEntry?->getId());

        $this->mention = "@remoteUser@$this->remoteDomain";
        // legitimate earlier deliveries (follow handshakes, the original's own traffic) must not mask anything
        $this->baseline = \count($this->testingApHttpClient->getPostedObjects());
    }

    public function testControlActivityOnTheOriginalIsDelivered(): void
    {
        $this->createEntryComment("a reply to $this->mention", $this->original, $this->alice);
        $this->voteManager->vote(VotableInterface::VOTE_UP, $this->original, $this->alice, rateLimit: false);

        self::assertNotSame([], $this->newlyPosted(), 'the harness should see deliveries for public content');
    }

    public function testReplyOnShadowNotDeliveredToOriginalAuthor(): void
    {
        $comment = $this->createEntryComment('a plain reply on the shadow', $this->shadow, $this->alice);
        $this->createEntryComment('a nested reply', $this->shadow, $this->alice, $comment);

        $this->assertNothingDelivered();
        self::assertSame(2, $this->entryCommentRepository->count(['entry' => $this->shadow]));
        self::assertSame(0, $this->entryCommentRepository->count(['entry' => $this->original]));
    }

    public function testVoteBoostFavouriteOnShadowNotDelivered(): void
    {
        $comment = $this->createEntryComment('a reply on the shadow', $this->shadow, $this->alice);
        $this->assertNothingDelivered();

        $this->voteManager->vote(VotableInterface::VOTE_DOWN, $this->shadow, $this->alice, rateLimit: false);
        $this->voteManager->vote(VotableInterface::VOTE_UP, $this->shadow, $this->alice, rateLimit: false);
        $this->voteManager->vote(VotableInterface::VOTE_UP, $comment, $this->alice, rateLimit: false);
        $this->voteManager->vote(VotableInterface::VOTE_NONE, $comment, $this->alice, rateLimit: false);
        $this->favouriteManager->toggle($this->alice, $this->shadow);
        $this->favouriteManager->toggle($this->alice, $comment);
        // and undone again, which would send an Undo
        $this->favouriteManager->toggle($this->alice, $this->shadow);
        $this->voteManager->vote(VotableInterface::VOTE_NONE, $this->shadow, $this->alice, rateLimit: false);

        $this->assertNothingDelivered();
    }

    public function testMentionOfRemoteUserInShadowCommentNotDelivered(): void
    {
        $this->createEntryComment("hello $this->mention, are you there", $this->shadow, $this->alice);

        $this->assertNothingDelivered();
    }

    public function testEditAndDeleteOfShadowCommentNotDelivered(): void
    {
        $comment = $this->createEntryComment("hello $this->mention", $this->shadow, $this->alice);
        $id = $comment->getId();

        $this->entityManager->clear();
        $alice = $this->userRepository->find($this->alice->getId());
        $comment = $this->entryCommentRepository->find($id);
        $dto = $this->entryCommentManager->createDto($comment);
        $dto->body = "edited $this->mention";
        $this->entryCommentManager->edit($comment, $dto, $alice);
        // the delivery handlers leave the entity manager holding stale copies, so start over
        $this->entityManager->clear();
        $this->entryCommentManager->delete($this->userRepository->find($this->alice->getId()), $this->entryCommentRepository->find($id));

        $this->assertNothingDelivered();
    }

    public function testShadowNotFetchableOverActivityPub(): void
    {
        $comment = $this->createEntryComment('a reply on the shadow', $this->shadow, $this->alice);
        $this->entityManager->clear();

        $headers = ['HTTP_ACCEPT' => 'application/activity+json'];
        $this->client->request('GET', "/m/circle/t/{$this->shadow->getId()}", server: $headers);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/m/circle/t/{$this->shadow->getId()}/-/comment/{$comment->getId()}", server: $headers);
        self::assertResponseStatusCodeSame(404);

        // the control: a local thread in a public magazine is served
        $local = $this->createEntry('A local public thread', $this->magazineRepository->findOneByName('garden'), $this->userRepository->findOneByUsername('admin'));
        $this->client->request('GET', "/m/garden/t/{$local->getId()}", server: $headers);
        self::assertResponseIsSuccessful();
    }

    private function assertNothingDelivered(): void
    {
        self::assertSame([], $this->newlyPosted(), 'nothing done on a shadow should leave the instance');
        // and no delivery at all can name the shadow or its comments, whenever it was sent
        foreach ($this->testingApHttpClient->getPostedObjects() as $posted) {
            self::assertDoesNotMatchRegularExpression("#/t/{$this->shadow->getId()}(?!\\d)#", json_encode($posted['payload'], JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * What was posted since the fixture was built, as "type to inbox" lines.
     *
     * @return string[]
     */
    private function newlyPosted(): array
    {
        return array_map(
            fn (array $posted) => ($posted['payload']['type'] ?? '?').' to '.$posted['inboxUrl'],
            \array_slice($this->testingApHttpClient->getPostedObjects(), $this->baseline)
        );
    }
}
