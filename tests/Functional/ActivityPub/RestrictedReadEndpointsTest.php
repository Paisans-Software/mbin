<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub;

use App\Entity\Activity;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\ActivityPub\Wrapper\AnnounceWrapper;
use App\Service\ActivityPub\Wrapper\FollowWrapper;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Anonymous ActivityPub reads of a restricted magazine, which is what every remote
 * server is: the same 404 a missing object gets.
 */
#[Group('ActivityPub')]
class RestrictedReadEndpointsTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private Magazine $vault;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $this->vault = $this->getMagazineByName('vault', $this->getUserByUsername('admin'));
        $this->vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();

        // requests arrive on the instance's own host, so the ids built while answering are local URLs
        $this->client->setServerParameter('HTTP_HOST', $this->settingsManager->get('KBIN_DOMAIN'));
        $this->client->setServerParameter('HTTPS', 'on');
    }

    private function apGet(string $path): void
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/activity+json']);
    }

    /**
     * @return iterable<string, array{\Closure(self): string}>
     */
    public static function provideCirclePaths(): iterable
    {
        yield 'followers' => [fn (self $t) => '/m/circle/followers'];
        yield 'moderators' => [fn (self $t) => '/m/circle/moderators'];
        yield 'featured' => [fn (self $t) => '/m/circle/pinned'];
        yield 'entry' => [fn (self $t) => "/m/circle/t/{$t->entry->getId()}/-"];
        yield 'entry comment' => [fn (self $t) => "/m/circle/t/{$t->entry->getId()}/-/comment/{$t->entryComment->getId()}"];
        yield 'post' => [fn (self $t) => "/m/circle/p/{$t->post->getId()}/-"];
        yield 'post comment' => [fn (self $t) => "/m/circle/p/{$t->post->getId()}/-/reply/{$t->postComment->getId()}"];
    }

    #[DataProvider('provideCirclePaths')]
    public function testRestrictedMagazineEndpointsAreNotFound(\Closure $path): void
    {
        $this->apGet($path($this));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOutboxIsTheSameEmptyStubAsForAMissingMagazine(): void
    {
        // the magazine outbox is an empty document for every name, existing or not, so
        // it says nothing about circle; a 404 here would be the one thing that did
        $this->apGet('/m/no-such-mag/outbox');
        $missing = $this->client->getResponse();

        $this->apGet('/m/circle/outbox');
        $restricted = $this->client->getResponse();

        self::assertSame($missing->getStatusCode(), $restricted->getStatusCode());
        self::assertSame($missing->getContent(), $restricted->getContent());
    }

    public function testActivityAboutRestrictedContentIsNotFound(): void
    {
        $activity = $this->activityAbout($this->entry);

        $this->apGet("/f/object/{$activity->uuid}");

        self::assertResponseStatusCodeSame(404);
    }

    public function testActivityAboutRestrictedContentIsNotFoundUnderAnotherHost(): void
    {
        // generated ids then carry the other host, so the payload no longer looks local
        $create = $this->activityAbout($this->entry);
        $announce = self::getContainer()->get(AnnounceWrapper::class)->build($this->alice, $create);
        $this->entityManager->flush();

        foreach (['Create' => $create, 'Announce' => $announce] as $type => $activity) {
            $this->client->request('GET', "/f/object/{$activity->uuid}", server: [
                'HTTP_ACCEPT' => 'application/activity+json',
                'HTTP_HOST' => 'other.example',
            ]);

            self::assertResponseStatusCodeSame(404, $type);
        }
    }

    public function testActivityAboutPublicContentIsServed(): void
    {
        $activity = $this->activityAbout($this->createEntry('Open entry', $this->open, $this->alice));

        $this->apGet("/f/object/{$activity->uuid}");

        self::assertResponseIsSuccessful();
    }

    public function testRestrictedMagazineFollowActivityIsServed(): void
    {
        $activity = self::getContainer()->get(FollowWrapper::class)->build($this->circle, $this->remoteFriend());

        $this->apGet("/f/object/{$activity->uuid}");

        self::assertResponseIsSuccessful();
    }

    public function testRestrictedActorIsNotFoundWithoutFollows(): void
    {
        $this->apGet('/m/circle');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRestrictedActorIsServedOnceItFollowsSomething(): void
    {
        $this->entityManager->persist(new MagazineFollow($this->circle, $this->remoteFriend()));
        $this->entityManager->flush();

        $this->apGet('/m/circle');

        self::assertResponseIsSuccessful();
        // the ActivityPub actor, not the private magazine's HTML stub
        self::assertResponseHeaderSame('Content-Type', 'application/activity+json');
        $json = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Group', $json['type']);
    }

    /**
     * Each route as a path template: {m} is the magazine name, {e} {ec} {p} {pc} the ids of
     * vault's content. A missing magazine gets the same ids, so only the name differs.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideMagazineRoutes(): iterable
    {
        yield 'actor' => ['/m/{m}'];
        yield 'outbox' => ['/m/{m}/outbox'];
        yield 'followers' => ['/m/{m}/followers'];
        yield 'moderators' => ['/m/{m}/moderators'];
        yield 'featured' => ['/m/{m}/pinned'];
        yield 'entry' => ['/m/{m}/t/{e}/-'];
        yield 'entry comment' => ['/m/{m}/t/{e}/-/comment/{ec}'];
        yield 'post' => ['/m/{m}/p/{p}/-'];
        yield 'post comment' => ['/m/{m}/p/{p}/-/reply/{pc}'];
    }

    #[DataProvider('provideMagazineRoutes')]
    public function testHiddenMagazineLooksExactlyLikeAMissingOne(string $route): void
    {
        $admin = $this->getUserByUsername('admin');
        $entry = $this->createEntry('Vault entry', $this->vault, $admin);
        $entryComment = $this->createEntryComment('Vault entry comment', $entry, $admin);
        $post = $this->createPost('Vault post', $this->vault, $admin);
        $postComment = $this->createPostComment('Vault post comment', $post, $admin);
        $ids = ['{e}' => $entry->getId(), '{ec}' => $entryComment->getId(), '{p}' => $post->getId(), '{pc}' => $postComment->getId()];

        $this->apGet(strtr($route, $ids + ['{m}' => 'nosuchmag']));
        $missing = $this->client->getResponse();

        $this->apGet(strtr($route, $ids + ['{m}' => 'vault']));
        $hidden = $this->client->getResponse();

        self::assertSame($missing->getStatusCode(), $hidden->getStatusCode());
        self::assertSame($missing->headers->get('Content-Type'), $hidden->headers->get('Content-Type'));
        self::assertSame($this->comparableBody($missing->getContent()), $this->comparableBody($hidden->getContent()));
    }

    /**
     * The error body as production renders it. The test kernel runs in debug mode, where
     * ProblemNormalizer adds the exception class and trace and puts the exception message
     * in "detail"; those name the code that threw, which differs between a missing name
     * (the argument resolver) and a withheld one (the visibility listener). With debug off,
     * as in production, it emits only type, title, status and the status text as detail.
     */
    private function comparableBody(string $content): mixed
    {
        $json = json_decode($content, true);
        if (!\is_array($json) || !isset($json['status'])) {
            return $content;
        }
        unset($json['class'], $json['trace']);
        $json['detail'] = Response::$statusTexts[$json['status']];

        return $json;
    }

    private function remoteFriend(): User
    {
        $remote = $this->getUserByUsername('remotefriend');
        $remote->apId = 'remotefriend@remote.example';
        $remote->apProfileId = 'https://remote.example/u/remotefriend';
        $remote->apInboxUrl = 'https://remote.example/u/remotefriend/inbox';
        $this->entityManager->flush();

        return $remote;
    }

    private function activityAbout(object $content): Activity
    {
        $activity = $this->createWrapper->build($content);
        $this->entityManager->flush();

        return $activity;
    }
}
