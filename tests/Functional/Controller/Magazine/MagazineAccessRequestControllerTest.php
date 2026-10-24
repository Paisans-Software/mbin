<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\DTO\MagazineBanDto;
use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Event\Magazine\MagazineAccessRequestedEvent;
use App\Repository\MagazineAccessRequestRepository;
use App\Service\MagazineAccessRequestManager;
use App\Service\MagazineManager;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestControllerTest extends WebTestCase
{
    private Magazine $circle;
    private Magazine $vault;
    private User $bob;

    public function setUp(): void
    {
        parent::setUp();

        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->circle = $this->getMagazineByName('circle', $admin);
        $this->circle->access = MagazineAccess::Private;
        $this->vault = $this->getMagazineByName('vault', $admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->getMagazineByName('garden', $admin);
        $this->entityManager->flush();

        $this->bob = $this->getUserByUsername('bob');
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(MagazineAccessRequest::class)->count([]);
    }

    /** Submits the stub's own form, as a browser would. */
    private function submitRequest(string $name = 'circle'): void
    {
        $crawler = $this->client->request('GET', '/m/'.$name);
        $this->client->submit($crawler->filter('[data-magazine-stub] form[action$="/request-access"]')->form());
    }

    /** @return array<string, mixed> */
    private function post(string $name): array
    {
        $this->client->request('POST', '/m/'.$name.'/request-access', ['token' => 'whatever']);

        return $this->production404();
    }

    /**
     * @see MagazineGateTest::production404()
     *
     * @return array<string, mixed>
     */
    private function production404(): array
    {
        $response = $this->client->getResponse();
        $headers = $response->headers->all();
        foreach (['date', 'expires', 'x-debug-exception', 'x-debug-exception-file', 'x-debug-token', 'x-debug-token-link'] as $volatile) {
            unset($headers[$volatile]);
        }
        ksort($headers);

        $dump = (string) $response->getContent();
        preg_match('/^(\S+) \{#\d+/', $dump, $class);
        preg_match('/^\s+-statusCode: (\d+)$/m', $dump, $status);
        preg_match('/^\s+-headers: (.*)$/m', $dump, $exceptionHeaders);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'class' => $class[1] ?? null,
            'exceptionStatus' => $status[1] ?? null,
            'exceptionHeaders' => $exceptionHeaders[1] ?? null,
        ];
    }

    public function testRequestCreatesPendingRow(): void
    {
        $this->client->loginUser($this->bob);
        $this->submitRequest();

        self::assertResponseRedirects('/m/circle');
        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(MagazineAccessRequest::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('circle', $rows[0]->magazine->name);
        self::assertSame('bob', $rows[0]->user->username);
    }

    public function testRequestIsIdempotent(): void
    {
        // The client reboots the kernel per request, so the event is observed through the manager.
        $events = [];
        static::getContainer()->get(EventDispatcherInterface::class)
            ->addListener(MagazineAccessRequestedEvent::class, function (MagazineAccessRequestedEvent $e) use (&$events) { $events[] = $e; });

        $manager = static::getContainer()->get(MagazineAccessRequestManager::class);
        $manager->request($this->circle, $this->bob);
        // A second request, as from a stale open form, changes nothing and notifies no one.
        $manager->request($this->circle, $this->bob);

        self::assertSame(1, $this->pendingCount());
        self::assertCount(1, $events);
    }

    public function testStubShowsRequestedState(): void
    {
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        self::assertSelectorTextContains('[data-magazine-stub] button', 'Request access');
        self::assertSelectorNotExists('[data-magazine-stub] button[disabled]');

        $this->client->submit($crawler->filter('[data-magazine-stub] form')->form());
        $this->client->request('GET', '/m/circle');

        self::assertSelectorExists('[data-magazine-stub] button[disabled]');
        self::assertSelectorTextContains('[data-magazine-stub] button[disabled]', 'Requested');
        self::assertSelectorNotExists('[data-magazine-stub] form');
    }

    public function testAnonymousSeesNoButton(): void
    {
        $this->client->request('GET', '/m/circle');

        self::assertSelectorExists('[data-magazine-stub]');
        self::assertSelectorNotExists('[data-magazine-stub] button');
        self::assertSelectorNotExists('[data-magazine-stub] form');
    }

    public function testHiddenMagazineRequestIs404(): void
    {
        $this->client->loginUser($this->bob);
        $hidden = $this->post('vault');
        $missing = $this->post('nonexistent');

        self::assertSame(404, $hidden['status']);
        self::assertSame($missing, $hidden);
        self::assertSame(0, $this->pendingCount());
    }

    public function testPublicMagazineRequestIs404(): void
    {
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        $token = $crawler->filter('[data-magazine-stub] input[name=token]')->attr('value');

        $this->client->request('POST', '/m/garden/request-access', ['token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->pendingCount());
    }

    public function testBannedUserCannotRequest(): void
    {
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        $token = $crawler->filter('[data-magazine-stub] input[name=token]')->attr('value');

        static::getContainer()->get(MagazineManager::class)->ban(
            $this->circle,
            $this->bob,
            $this->getUserByUsername('admin'),
            MagazineBanDto::create('test ban', new \DateTimeImmutable('+1 day'))
        );

        $this->client->request('POST', '/m/circle/request-access', ['token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->pendingCount());
    }

    public function testMemberCannotRequest(): void
    {
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        $token = $crawler->filter('[data-magazine-stub] input[name=token]')->attr('value');

        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $this->bob, $this->getUserByUsername('admin'));

        $this->client->request('POST', '/m/circle/request-access', ['token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->pendingCount());
    }

    public function testRemovedMemberCanRequestAgain(): void
    {
        $members = static::getContainer()->get(MagazineMemberManager::class);
        $members->add($this->circle, $this->bob, $this->getUserByUsername('admin'));
        $members->remove($this->circle, $this->bob);

        $this->client->loginUser($this->bob);
        $this->submitRequest();

        self::assertResponseRedirects('/m/circle');
        self::assertSame(1, $this->pendingCount());
    }

    public function testHiddenMagazineWithValidTokenIs404LikeMissing(): void
    {
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        $token = $crawler->filter('[data-magazine-stub] input[name=token]')->attr('value');

        $this->client->request('POST', '/m/vault/request-access', ['token' => $token]);
        $hidden = $this->production404();
        $this->client->request('POST', '/m/nonexistent/request-access', ['token' => $token]);
        $missing = $this->production404();

        self::assertSame(404, $hidden['status']);
        self::assertSame($missing, $hidden);
        self::assertSame(0, $this->pendingCount());
    }

    public function testAnonymousPostToHiddenIsIdenticalToMissing(): void
    {
        $hidden = $this->post('vault');
        $missing = $this->post('nonexistent');

        self::assertSame($missing, $hidden);
        self::assertSame(0, $this->pendingCount());
    }

    public function testAnonymousPostToPrivateCreatesNothing(): void
    {
        $this->client->request('POST', '/m/circle/request-access', ['token' => 'whatever']);

        self::assertTrue($this->client->getResponse()->isRedirect() || 404 === $this->client->getResponse()->getStatusCode() || 401 === $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->pendingCount());
    }

    public function testBadTokenOnPrivateIs400(): void
    {
        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/m/circle');
        $this->client->request('POST', '/m/circle/request-access', ['token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->pendingCount());
    }

    public function testStubOffersNoButtonToBannedUsers(): void
    {
        $admin = $this->getUserByUsername('admin');
        $magazineBanned = $this->getUserByUsername('mbanned');
        $instanceBanned = $this->getUserByUsername('ibanned');
        $instanceBanned->isBanned = true;
        $this->entityManager->flush();
        static::getContainer()->get(MagazineManager::class)->ban(
            $this->circle,
            $magazineBanned,
            $admin,
            MagazineBanDto::create('test ban', new \DateTimeImmutable('+1 day'))
        );

        foreach ([$magazineBanned, $instanceBanned] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/m/circle');
            self::assertSelectorExists('[data-magazine-stub]');
            self::assertSelectorNotExists('[data-magazine-stub] button');
            self::assertSelectorNotExists('[data-magazine-stub] form');
        }
    }

    public function testConcurrentDoubleSubmitIsIdempotent(): void
    {
        $container = static::getContainer();
        $manager = $container->get(MagazineAccessRequestManager::class);
        $manager->request($this->circle, $this->bob);

        // A repository that misses the existing row models the race: both submits passed the existence check.
        $blind = $this->createMock(MagazineAccessRequestRepository::class);
        $blind->method('findOneByMagazineAndUser')->willReturn(null);
        $racing = new MagazineAccessRequestManager(
            $blind,
            $container->get('doctrine.orm.entity_manager'),
            $container->get(EventDispatcherInterface::class),
            $container->get(MagazineMemberManager::class),
            $container->get('doctrine'),
        );

        $racing->request($this->circle, $this->bob);

        $em = $container->get('doctrine')->getManager();
        self::assertCount(1, $em->getRepository(MagazineAccessRequest::class)->findAll());
    }

    public function testDirectAddDropsPendingRequest(): void
    {
        $admin = $this->getUserByUsername('admin');
        $members = static::getContainer()->get(MagazineMemberManager::class);
        static::getContainer()->get(MagazineAccessRequestManager::class)->request($this->circle, $this->bob);
        self::assertSame(1, $this->pendingCount());

        $members->add($this->circle, $this->bob, $admin);
        self::assertSame(0, $this->pendingCount());

        $members->remove($this->circle, $this->bob);
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/m/circle');
        self::assertSelectorNotExists('[data-magazine-stub] button[disabled]');
        $this->client->submit($crawler->filter('[data-magazine-stub] form')->form());

        self::assertResponseRedirects('/m/circle');
        self::assertSame(1, $this->pendingCount());
    }

    public function testMagazineBanDropsPendingRequest(): void
    {
        static::getContainer()->get(MagazineAccessRequestManager::class)->request($this->circle, $this->bob);
        self::assertSame(1, $this->pendingCount());

        static::getContainer()->get(MagazineManager::class)->ban(
            $this->circle,
            $this->bob,
            $this->getUserByUsername('admin'),
            MagazineBanDto::create('test ban', new \DateTimeImmutable('+1 day'))
        );

        self::assertSame(0, $this->pendingCount());
    }
}
