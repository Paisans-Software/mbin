<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\DTO\ModeratorDto;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\Post;
use App\Enum\MagazineAccess;
use App\Service\MagazineManager;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineGateTest extends WebTestCase
{
    private Magazine $circle;
    private Magazine $vault;
    private Entry $entry;
    private Post $post;

    public function setUp(): void
    {
        parent::setUp();

        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->circle = $this->getMagazineByName('circle', $admin);
        $this->circle->access = MagazineAccess::Private;
        $this->circle->description = 'A circle of friends';
        $this->vault = $this->getMagazineByName('vault', $admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();

        $this->entry = $this->createEntry('Secret entry title', $this->circle, $admin);
        $this->post = $this->createPost('Secret post body', $this->circle, $admin);

        $this->getUserByUsername('bob');
        $this->member($this->getUserByUsername('alice'));
    }

    private function member($user): void
    {
        $this->memberManager()->add($this->circle, $user, $this->getUserByUsername('admin'));
    }

    private function memberManager(): MagazineMemberManager
    {
        return new MagazineMemberManager(
            $this->entityManager->getRepository(MagazineMember::class),
            $this->magazineManager,
            $this->entityManager,
        );
    }

    private function assertStub(): void
    {
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-magazine-stub]');
        self::assertStringContainsString('circle', $this->client->getResponse()->getContent());
        self::assertStringContainsString('A circle of friends', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('article.entry');
        self::assertSelectorNotExists('.moderators');
        self::assertStringNotContainsString('Secret entry title', $this->client->getResponse()->getContent());
    }

    /**
     * The test kernel runs with debug on, so the body is an exception dump that carries the
     * resolver's own message, file and line. Compare what a client sees: status, headers that
     * are not per-request, and the exception class.
     */
    private function normalize404(string $name): string
    {
        $response = $this->client->getResponse();
        $headers = $response->headers->all();
        foreach (['date', 'expires', 'x-debug-exception', 'x-debug-exception-file'] as $volatile) {
            unset($headers[$volatile]);
        }
        ksort($headers);
        $firstLine = strtok($response->getContent(), "\n");

        return str_replace($name, 'NAME', json_encode([$response->getStatusCode(), $headers, preg_replace('/#\d+/', '', $firstLine)]));
    }

    public function testNonMemberSeesStubForPrivate(): void
    {
        $this->circle->apIndexable = true;
        $this->entityManager->flush();

        $this->client->loginUser($this->getUserByUsername('bob'));
        $this->client->request('GET', '/m/circle');

        $this->assertStub();
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
    }

    public function testAnonymousSeesStubForPrivate(): void
    {
        $this->client->request('GET', '/m/circle');

        $this->assertStub();
    }

    public function testHiddenMagazineIndistinguishableFromMissing(): void
    {
        $this->client->loginUser($this->getUserByUsername('bob'));

        $this->client->request('GET', '/m/vault');
        self::assertResponseStatusCodeSame(404);
        $bodyA = $this->normalize404('vault');

        $this->client->request('GET', '/m/no-such-mag');
        self::assertResponseStatusCodeSame(404);
        $bodyB = $this->normalize404('no-such-mag');

        self::assertSame($bodyA, $bodyB);
    }

    public function testContentRoutesUnderPrivateAre404ForNonMember(): void
    {
        $this->client->loginUser($this->getUserByUsername('bob'));

        $paths = [
            '/m/circle/microblog',
            '/m/circle/t/'.$this->entry->getId(),
            '/m/circle/p/'.$this->post->getId(),
            '/m/circle/people',
            '/m/circle/modlog',
            '/m/circle/panel/bans',
        ];
        foreach ($paths as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testMemberSeesContent(): void
    {
        $this->client->loginUser($this->getUserByUsername('alice'));
        $this->client->request('GET', '/m/circle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Secret entry title');
        self::assertSelectorNotExists('[data-magazine-stub]');
    }

    public function testGlobalModeratorSeesContent(): void
    {
        $this->client->loginUser($this->getUserByUsername('gmod', isModerator: true));
        $this->client->request('GET', '/m/circle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Secret entry title');
    }

    public function testMagazineModeratorSeesContent(): void
    {
        $dto = new ModeratorDto($this->circle);
        $dto->user = $this->getUserByUsername('mmod');
        $dto->addedBy = $this->getUserByUsername('admin');
        $this->client->getContainer()->get(MagazineManager::class)->addModerator($dto);

        $this->client->loginUser($this->getUserByUsername('mmod'));
        $this->client->request('GET', '/m/circle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Secret entry title');
    }

    public function testRemovedMemberLosesAccessOnNextRequest(): void
    {
        $alice = $this->getUserByUsername('alice');
        $this->client->loginUser($alice);

        $this->client->request('GET', '/m/circle');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-magazine-stub]');

        $this->memberManager()->remove($this->circle, $alice);

        $this->client->request('GET', '/m/circle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-magazine-stub]');
    }

    public function testNonMemberCannotSubscribe(): void
    {
        $bob = $this->getUserByUsername('bob');
        $this->client->loginUser($bob);

        $this->client->request('POST', '/m/circle/subscribe');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->circle->isSubscribed($bob));
    }
}
