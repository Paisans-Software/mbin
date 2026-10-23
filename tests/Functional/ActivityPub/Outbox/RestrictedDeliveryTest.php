<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Outbox;

use App\Entity\Contracts\VotableInterface;
use App\Entity\MagazineSubscription;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Nothing that touches a restricted magazine leaves the instance, except the
 * magazine's own follow handshakes. "circle" carries a remote subscriber row that
 * the normal subscribe path would never create, alice (a member) has a remote
 * follower, and the content mentions a remote user, so every fan out path has
 * somebody to deliver to.
 */
#[Group('ActivityPub')]
#[Group('NonThreadSafe')]
class RestrictedDeliveryTest extends ActivityPubFunctionalTestCase
{
    use RestrictedContentFixtureTrait;

    private string $mention;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $this->entityManager->persist(new MagazineSubscription($this->remoteSubscriber, $this->circle));
        $this->entityManager->flush();
        $this->userManager->follow($this->remoteSubscriber, $this->alice);

        $this->mention = "@remoteUser@$this->remoteDomain";
    }

    public function setUpRemoteEntities(): void
    {
    }

    public function testNothingDeliveredForRestrictedContent(): void
    {
        self::assertSame([], $this->postedSummary(), 'the fixture itself should not have delivered anything');

        $entry = $this->createEntry('Alice circle entry', $this->circle, $this->alice, body: "hello $this->mention");
        $comment = $this->createEntryComment("a comment for $this->mention", $entry, $this->alice);
        $post = $this->createPost("a post for $this->mention", $this->circle, $this->alice);
        $postComment = $this->createPostComment("a reply for $this->mention", $post, $this->alice);

        // the delivery handlers leave the entity manager holding stale copies, so start over from fresh ones
        $this->entityManager->clear();
        $post = $this->postRepository->find($post->getId());
        $postComment = $this->postCommentRepository->find($postComment->getId());
        $comment = $this->entryCommentRepository->find($comment->getId());
        $alice = $this->userRepository->find($this->alice->getId());

        $dto = $this->postManager->createDto($post);
        $dto->body = "an edited post for $this->mention";
        $this->postManager->edit($post, $dto, $alice);
        $this->postCommentManager->delete($alice, $postComment);
        $this->entryCommentManager->delete($alice, $comment);

        $this->voteManager->vote(VotableInterface::VOTE_UP, $this->entryRepository->find($this->entry->getId()), $alice, rateLimit: false);
        $this->voteManager->vote(VotableInterface::VOTE_DOWN, $this->entryCommentRepository->find($this->entryComment->getId()), $alice, rateLimit: false);
        $this->voteManager->vote(VotableInterface::VOTE_UP, $this->postRepository->find($this->post->getId()), $alice, rateLimit: false);
        $this->favouriteManager->toggle($alice, $this->postCommentRepository->find($this->postComment->getId()));
        $this->favouriteManager->toggle($alice, $this->entryRepository->find($this->entry->getId()));

        $this->entryManager->delete($alice, $this->entryRepository->find($entry->getId()));

        self::assertSame([], $this->postedSummary());
    }

    public function testPublicContentStillDelivered(): void
    {
        $this->createPost('a public post', $this->open, $this->alice);

        $this->assertCountOfSentActivitiesOfType(1, 'Create');
        $creates = $this->getSentActivitiesOfType('Create');
        self::assertSame($this->remoteSubscriber->apInboxUrl, $creates[0]['inboxUrl']);
    }

    public function testRestrictedMagazineFollowHandshakeStillDelivered(): void
    {
        // the panel request has to run in the kernel the test set the fake HTTP client on
        $this->client->disableReboot();
        // and on the instance's own host, so the activity ids it builds are local ones
        $this->client->setServerParameter('HTTP_HOST', $this->localDomain);
        $this->client->setServerParameter('HTTPS', 'on');
        $this->client->loginUser($this->getUserByUsername('admin'));

        $crawler = $this->client->request('GET', '/m/circle/panel/tags');
        self::assertResponseIsSuccessful();
        $this->client->submit(
            $crawler->filter('#main form[name=follow]')->selectButton('Add follow')->form([
                'actor' => $this->mention,
            ])
        );
        self::assertResponseRedirects('/m/circle/panel/tags');

        $posted = $this->testingApHttpClient->getPostedObjects();
        self::assertCount(1, $posted);
        self::assertSame('Follow', $posted[0]['payload']['type']);
        self::assertSame($this->remoteUser->apInboxUrl, $posted[0]['inboxUrl']);
    }

    /**
     * What was posted, as "type to inbox" lines: the full payloads are too large to diff.
     *
     * @return string[]
     */
    private function postedSummary(): array
    {
        return array_map(
            fn (array $posted) => ($posted['payload']['type'] ?? '?').' to '.$posted['inboxUrl'],
            $this->testingApHttpClient->getPostedObjects()
        );
    }
}
