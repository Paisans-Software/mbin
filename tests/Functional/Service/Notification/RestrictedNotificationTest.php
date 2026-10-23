<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Notification;

use App\Entity\Contracts\VotableInterface;
use App\Entity\Magazine;
use App\Entity\User;
use App\Enums\ENotificationStatus;
use App\Repository\NotificationSettingsRepository;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\Service\RecordingHub;
use App\Tests\WebTestCase;
use Symfony\Component\Mercure\Update;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Notifications and realtime updates about restricted content only reach people who can
 * view the magazine. The fixture is RestrictedContentFixtureTrait: circle is private,
 * alice is a member, bob is not.
 */
class RestrictedNotificationTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private RecordingHub $hub;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $this->hub = self::getContainer()->get(RecordingHub::class);
    }

    private function enableMercure(): void
    {
        $this->settingsManager->set('KBIN_MERCURE_ENABLED', true);
    }

    public function testMentionOfNonMemberCreatesNoNotification(): void
    {
        $this->createEntry('Mention entry', $this->circle, $this->alice, null, 'hello @bob');
        $this->createPost('hello @bob', $this->circle, $this->alice);

        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->bob));
    }

    public function testMentionOfMemberStillNotifies(): void
    {
        $carol = $this->getUserByUsername('carol');
        $this->createEntry('Mention entry', $this->circle, $this->getUserByUsername('admin'), null, 'hello @alice');

        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($this->alice));
        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($carol));
    }

    public function testFollowerOfAuthorIsNotNotified(): void
    {
        $this->followLoud($this->bob, $this->alice);

        $this->createEntry('Circle entry', $this->circle, $this->alice);
        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->bob));

        $this->createEntry('Open entry', $this->open, $this->alice);
        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($this->bob));
    }

    public function testFollowerOfAuthorIsNotNotifiedOfPostsAndComments(): void
    {
        $this->followLoud($this->bob, $this->alice);

        $post = $this->createPost('Circle post', $this->circle, $this->alice);
        $this->createPostComment('Circle post comment', $post, $this->alice);
        $this->createEntryComment('Circle comment', $this->entry, $this->alice);

        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->bob));
    }

    public function testMemberSubscriberIsNotified(): void
    {
        $carol = $this->getUserByUsername('carol');
        $carol->notifyOnNewEntry = true;
        $this->entityManager->flush();
        $this->magazineManager->subscribe($this->circle, $carol);
        $this->addMember($carol);

        $this->createEntry('Circle entry', $this->circle, $this->alice);

        self::assertSame(1, $this->notificationRepository->countUnreadNotifications($carol));
    }

    public function testMentionOfNonMemberInACommentCreatesNoNotification(): void
    {
        $this->createEntryComment('hello @bob', $this->entry, $this->alice);
        $this->createPostComment('hello @bob', $this->post, $this->alice);

        self::assertSame(0, $this->notificationRepository->countUnreadNotifications($this->bob));
    }

    public function testFormerMemberGetsNoReplyNotification(): void
    {
        $carol = $this->getUserByUsername('carol');
        $this->addMember($carol);
        $this->addMember($this->bob);
        $carolsComment = $this->createEntryComment('carol speaks', $this->entry, $carol);
        $bobsComment = $this->createEntryComment('bob speaks', $this->entry, $this->bob);
        $carolsReply = $this->createPostComment('carol replies', $this->post, $carol);
        $bobsReply = $this->createPostComment('bob replies', $this->post, $this->bob);
        static::getContainer()->get(\App\Service\MagazineMemberManager::class)->remove($this->circle, $this->bob);
        $before = [$this->unread($carol), $this->unread($this->bob)];

        $this->createEntryComment('reply to carol', $this->entry, $this->alice, parent: $carolsComment);
        $this->createEntryComment('reply to bob', $this->entry, $this->alice, parent: $bobsComment);
        $this->createPostComment('reply to carol', $this->post, $this->alice, parent: $carolsReply);
        $this->createPostComment('reply to bob', $this->post, $this->alice, parent: $bobsReply);

        self::assertSame($before[0] + 2, $this->unread($carol), 'positive control: a member is told of both replies');
        self::assertSame($before[1], $this->unread($this->bob), 'a former member is told of neither');
    }

    public function testSiteStaffSubscribersAreStillNotified(): void
    {
        $staff = [
            $this->getUserByUsername('admin', isAdmin: true),
            $this->getUserByUsername('gmod', isModerator: true),
        ];
        foreach ($staff as $user) {
            $user->notifyOnNewEntry = true;
            $this->magazineManager->subscribe($this->circle, $user);
        }
        $this->entityManager->flush();
        $before = array_map(fn (User $user) => $this->unread($user), $staff);

        $this->createEntry('Circle entry for staff', $this->circle, $this->alice);

        foreach ($staff as $i => $user) {
            self::assertSame($before[$i] + 1, $this->unread($user), $user->username);
        }
    }

    private function unread(User $user): int
    {
        return $this->notificationRepository->countUnreadNotifications($user);
    }

    public function testNoMercureUpdateForRestrictedContent(): void
    {
        $this->enableMercure();
        $this->exerciseAllContent($this->circle);

        self::assertCount(0, $this->hub->updates);
    }

    public function testMercureUpdatesStillSentForPublicContent(): void
    {
        $this->enableMercure();
        $this->exerciseAllContent($this->open);

        self::assertNotEmpty($this->hub->updates);
        $ops = array_map(static fn (Update $u) => json_decode($u->getData(), true)['op'], $this->hub->updates);
        foreach (['EntryCreatedNotification', 'EntryCommentCreatedNotification', 'PostCreatedNotification', 'PostCommentCreatedNotification', 'EntryVote', 'EntryFavourite'] as $op) {
            self::assertContains($op, $ops);
        }
    }

    private function exerciseAllContent(Magazine $magazine): void
    {
        $entry = $this->createEntry('Realtime entry', $magazine, $this->alice);
        $this->createEntryComment('Realtime comment', $entry, $this->alice);
        $post = $this->createPost('Realtime post', $magazine, $this->alice);
        $this->createPostComment('Realtime post comment', $post, $this->alice);
        $this->voteManager->vote(VotableInterface::VOTE_DOWN, $entry, $this->getUserByUsername('admin'));
        $this->favouriteManager->toggle($this->getUserByUsername('admin'), $entry);
    }

    private function followLoud(User $follower, User $followed): void
    {
        self::getContainer()->get(NotificationSettingsRepository::class)
            ->setStatusByTarget($follower, $followed, ENotificationStatus::Loud);
    }

    private function addMember(User $user): void
    {
        static::getContainer()->get(\App\Service\MagazineMemberManager::class)->add($this->circle, $user, $this->getUserByUsername('admin'));
    }
}
