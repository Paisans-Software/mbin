<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Post;
use App\Event\Post\PostCreatedEvent;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Posts never move into or out of a restricted magazine by tag: not when a remote post
 * lands in random, and not by the move-by-tag command.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedPostRoutingTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();
    }

    public function testRemotePostTaggedWithRestrictedMagazineStaysInRandom(): void
    {
        $admin = $this->getUserByUsername('admin');
        $random = $this->getMagazineByName('random', $admin);
        $this->circle->tags = ['circletag'];
        $public = $this->getMagazineByName('opentag', $admin);
        $public->tags = ['opentag'];
        $this->entityManager->flush();

        $toCircle = $this->remotePost('about #circletag', 'https://remote.example/p/1');
        $toPublic = $this->remotePost('about #opentag', 'https://remote.example/p/2');

        $this->entityManager->clear();
        self::assertSame('random', $this->entityManager->find(Post::class, $toCircle)->magazine->name);
        self::assertSame('opentag', $this->entityManager->find(Post::class, $toPublic)->magazine->name, 'positive control: a public magazine collects its tag');
        self::assertSame($random->getId(), $this->entityManager->find(Post::class, $toCircle)->magazine->getId());
    }

    public function testMovePostsByTagSkipsRestrictedPosts(): void
    {
        $admin = $this->getUserByUsername('admin');
        $this->getMagazineByName('target', $admin);
        $secret = $this->createPost('secret #movetag', $this->circle, $admin);
        $public = $this->createPost('public #movetag', $this->open, $admin);
        [$secretId, $publicId] = [$secret->getId(), $public->getId()];

        $tester = new CommandTester((new Application(self::$kernel))->find('mbin:posts:move'));
        $tester->execute(['magazine' => 'target', 'tag' => 'movetag']);

        $this->entityManager->clear();
        self::assertSame('circle', $this->entityManager->find(Post::class, $secretId)->magazine->name);
        self::assertSame('target', $this->entityManager->find(Post::class, $publicId)->magazine->name);
        self::assertStringContainsString('skipped, a restricted magazine is involved', $tester->getDisplay());
    }

    private function remotePost(string $body, string $apId): int
    {
        $post = $this->createPost($body, $this->getMagazineByName('random'), $this->getUserByUsername('remoteposter'));
        $post->apId = $apId;
        $this->entityManager->flush();
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new PostCreatedEvent($post));

        return $post->getId();
    }
}
