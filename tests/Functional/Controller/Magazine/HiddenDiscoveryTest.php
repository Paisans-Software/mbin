<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\Controller\User\ThemeSettingsController;
use App\DTO\MagazineBanDto;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\MagazineRepository;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Magazine discovery lists a private magazine and leaves a hidden one out, except for
 * those who may view it: its members who are not banned, its moderators, admins and
 * global moderators. WebFinger resolves neither while it follows nothing.
 */
class HiddenDiscoveryTest extends WebTestCase
{
    private User $admin;
    private User $bob;
    private Magazine $circle;
    private Magazine $vault;

    public function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->circle = $this->getMagazineByName('circle', $this->admin);
        $this->circle->access = MagazineAccess::Private;
        $this->vault = $this->getMagazineByName('vault', $this->admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->getMagazineByName('open', $this->admin);
        $this->entityManager->flush();

        // findRelated and findByActivity only list magazines with content
        $this->createEntry('Circle entry', $this->circle, $this->admin);
        $this->createEntry('Vault entry', $this->vault, $this->admin);

        $this->bob = $this->getUserByUsername('bob');
    }

    public function testDirectoryListsPrivateWithLockAndOmitsHidden(): void
    {
        // the lock icon itself is added with the other access icons in a later change
        $this->client->loginUser($this->bob);
        $body = $this->get('/magazines');
        self::assertStringContainsString('/m/circle', $body);
        self::assertStringNotContainsString('/m/vault', $body);

        $this->client->restart();
        $body = $this->get('/magazines');
        self::assertStringContainsString('/m/circle', $body);
        self::assertStringNotContainsString('/m/vault', $body);

        $json = $this->getApi('/api/magazines');
        self::assertStringContainsString('"name":"circle"', $json);
        self::assertStringNotContainsString('"name":"vault"', $json);
    }

    public function testAdminAndGlobalModeratorSeeHiddenInDirectory(): void
    {
        $moderator = $this->getUserByUsername('globalmod', isModerator: true);
        foreach ([$this->admin, $moderator] as $user) {
            $this->client->loginUser($user);
            self::assertStringContainsString('/m/vault', $this->get('/magazines'), $user->username);
        }
    }

    public function testMagazineSearchRandomRelatedOmitHidden(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/search?search%5Bq%5D=vault');
        self::assertStringNotContainsString('/m/vault', $body);
        $body = $this->get('/search?search%5Bq%5D=circle');
        self::assertStringContainsString('/m/circle', $body);

        $this->client->loginUser($this->admin);
        self::assertStringContainsString('/m/vault', $this->get('/search?search%5Bq%5D=vault'));

        $names = fn (array $magazines) => array_map(fn (Magazine $m) => $m->name, $magazines);
        self::assertNotContains('vault', $names($this->magazineRepository->findRandom($this->bob)));
        self::assertContains('circle', $names($this->magazineRepository->findRandom($this->bob)));
        self::assertNotContains('vault', $names($this->magazineRepository->findRandom()));
        self::assertContains('vault', $names($this->magazineRepository->findRandom($this->admin)));

        self::assertSame([], $names($this->magazineRepository->findRelated('vault', $this->bob)));
        self::assertSame([], $names($this->magazineRepository->findRelated('vault')));
        self::assertSame(['vault'], $names($this->magazineRepository->findRelated('vault', $this->admin)));
        self::assertSame(['circle'], $names($this->magazineRepository->findRelated('circle', $this->bob)));

        $this->actAs($this->bob);
        self::assertNotContains('vault', $names($this->repository()->findByActivity()));
        self::assertContains('circle', $names($this->repository()->findByActivity()));
        self::assertSame([], $names(iterator_to_array($this->repository()->search('vault', 1)->getCurrentPageResults())));
        $this->actAs($this->admin);
        self::assertContains('vault', $names($this->repository()->findByActivity()));
        self::assertSame(['vault'], $names(iterator_to_array($this->repository()->search('vault', 1)->getCurrentPageResults())));
    }

    public function testMemberFindsHiddenMagazineWhereverMagazinesAreListed(): void
    {
        $alice = $this->getUserByUsername('alice');
        $this->memberManager()->add($this->vault, $alice, $this->admin);
        $this->admin->lastActive = new \DateTime('-2 months');
        $this->entityManager->flush();

        $this->client->loginUser($alice);
        self::assertStringContainsString('/m/vault', $this->get('/magazines'));
        self::assertStringContainsString('/m/vault', $this->get('/magazines/abandoned'));
        self::assertStringContainsString('/m/vault', $this->get('/search?search%5Bq%5D=vault'));
        $this->client->request('GET', '/autocomplete/magazine_autocomplete_type?query=vault');
        self::assertStringContainsString('vault', $this->client->getResponse()->getContent());
        $this->client->getCookieJar()->set(new Cookie(ThemeSettingsController::KBIN_GENERAL_TOPBAR, ThemeSettingsController::TRUE));
        $crawler = $this->client->request('GET', '/all');
        self::assertStringContainsString('/m/vault', $crawler->filter('#topbar')->html());
        self::assertStringContainsString('"name":"vault"', $this->getApi('/api/magazines', $alice));

        $names = fn (array $magazines) => array_map(fn (Magazine $m) => $m->name, $magazines);
        self::assertContains('vault', $names($this->magazineRepository->findRandom($alice)));
        self::assertSame(['vault'], $names($this->magazineRepository->findRelated('vault', $alice)));
        $this->actAs($alice);
        self::assertContains('vault', $names($this->repository()->findByActivity()));
        self::assertSame(['vault'], $names(iterator_to_array($this->repository()->search('vault', 1)->getCurrentPageResults())));

        // membership of one hidden magazine reveals no other
        $this->client->loginUser($this->bob);
        self::assertStringNotContainsString('/m/vault', $this->get('/magazines'));
    }

    public function testBannedMemberDoesNotFindHiddenMagazine(): void
    {
        $alice = $this->getUserByUsername('alice');
        $this->memberManager()->add($this->vault, $alice, $this->admin);
        $dto = new MagazineBanDto();
        $dto->reason = 'test';
        $this->magazineManager->ban($this->vault, $alice, $this->admin, $dto);

        $this->client->loginUser($alice);
        self::assertStringNotContainsString('/m/vault', $this->get('/magazines'));
        self::assertStringNotContainsString('/m/vault', $this->get('/search?search%5Bq%5D=vault'));
        self::assertNotContains('vault', array_map(fn (Magazine $m) => $m->name, $this->magazineRepository->findRandom($alice)));
    }

    public function testHiddenMagazineNeverReceivesTaggedPosts(): void
    {
        $this->vault->tags = ['vaulttag'];
        $this->circle->tags = ['circletag'];
        $this->entityManager->flush();

        $this->actAs($this->admin);
        self::assertNull($this->repository()->findByTag('vaulttag'));
        self::assertNull($this->repository()->findByTag('circletag'));
    }

    public function testAbandonedListOmitsHidden(): void
    {
        $this->admin->lastActive = new \DateTime('-2 months');
        $this->entityManager->flush();

        $this->client->loginUser($this->bob);
        $body = $this->get('/magazines/abandoned');
        self::assertStringContainsString('/m/circle', $body);
        self::assertStringNotContainsString('/m/vault', $body);
    }

    public function testFeaturedMagazinesAndAutocompleteOmitHidden(): void
    {
        $this->client->loginUser($this->bob);
        $this->client->getCookieJar()->set(new Cookie(ThemeSettingsController::KBIN_GENERAL_TOPBAR, ThemeSettingsController::TRUE));
        $crawler = $this->client->request('GET', '/all');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#topbar'));
        $featured = $crawler->filter('#topbar')->html();
        self::assertStringContainsString('/m/circle', $featured);
        self::assertStringNotContainsString('/m/vault', $featured);

        $this->client->request('GET', '/autocomplete/magazine_autocomplete_type?query=vault');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('vault', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/autocomplete/magazine_autocomplete_type?query=circle');
        self::assertStringContainsString('circle', $this->client->getResponse()->getContent());
    }

    public function testAutocompleteWithoutQueryOmitsHidden(): void
    {
        $paths = ['/autocomplete/magazine_autocomplete_type', '/autocomplete/magazine_autocomplete_type?page=1', '/autocomplete/magazine_autocomplete_type?page=2'];
        foreach ([$this->bob, null] as $user) {
            $this->client->restart();
            if ($user) {
                $this->client->loginUser($user);
            }
            foreach ($paths as $path) {
                $this->client->request('GET', $path);
                self::assertResponseIsSuccessful($path);
                self::assertStringNotContainsString('vault', $this->client->getResponse()->getContent(), $path);
            }
            $this->client->request('GET', $paths[0]);
            self::assertStringContainsString('circle', $this->client->getResponse()->getContent());
        }

        $this->client->loginUser($this->admin);
        $this->client->request('GET', $paths[0]);
        self::assertStringContainsString('vault', $this->client->getResponse()->getContent());
    }

    #[Group('NonThreadSafe')]
    public function testUrlLookupDoesNotResolveHiddenMagazine(): void
    {
        $domain = $this->settingsManager->get('KBIN_DOMAIN');
        $vaultUrl = "https://$domain/m/vault";
        $circleUrl = "https://$domain/m/circle";

        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/search?search%5Bq%5D='.urlencode($vaultUrl));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/m/vault"]'));
        $crawler = $this->client->request('GET', '/search?search%5Bq%5D='.urlencode($circleUrl));
        self::assertGreaterThan(0, $crawler->filter('a[href$="/m/circle"]')->count());

        // v1 is left out: for a URL it finds nothing by AP id, then fetches the URL over the
        // network, which the test environment cannot resolve
        self::assertStringNotContainsString('"name":"vault"', $this->getApi('/api/search/v2?q='.urlencode($vaultUrl), $this->bob));
        self::assertStringContainsString('"name":"circle"', $this->getApi('/api/search/v2?q='.urlencode($circleUrl), $this->bob));

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/search?search%5Bq%5D='.urlencode($vaultUrl));
        self::assertGreaterThan(0, $crawler->filter('a[href$="/m/vault"]')->count());
        self::assertStringContainsString('"name":"vault"', $this->getApi('/api/search/v2?q='.urlencode($vaultUrl), $this->admin));
    }

    #[Group('NonThreadSafe')]
    public function testUrlLookupDoesNotResolveRestrictedContent(): void
    {
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['title' => 'Circle entry']);
        $entry->apId = 'https://remote.example/e/circle';
        $this->entityManager->flush();
        $query = '/api/search/v2?q='.urlencode('https://remote.example/e/circle');

        self::assertStringNotContainsString('Circle entry', $this->getApi($query, $this->bob));
        $this->client->loginUser($this->bob);
        $crawler = $this->client->request('GET', '/search?search%5Bq%5D='.urlencode('https://remote.example/e/circle'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Circle entry', $crawler->filter('#main')->html());

        self::assertStringContainsString('Circle entry', $this->getApi($query, $this->admin));
    }

    public function testWebFingerDoesNotResolveRestricted(): void
    {
        $domain = $this->settingsManager->get('KBIN_DOMAIN');

        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:open@$domain"));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:nosuchmagazine@$domain"));
        self::assertResponseStatusCodeSame(404);
        $missing = $this->client->getResponse()->getContent();

        // signed in as an admin changes nothing: AP lookups are anonymous
        $this->client->loginUser($this->admin);
        foreach (['circle', 'vault'] as $name) {
            $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:$name@$domain"));
            self::assertResponseStatusCodeSame(404, $name);
            self::assertSame($missing, $this->client->getResponse()->getContent(), $name);
        }
    }

    public function testWebFingerResolvesRestrictedOnceItFollowsSomething(): void
    {
        // a server verifying the magazine's Follow confirms the actor through WebFinger,
        // so it resolves under the same rule as the actor document
        $domain = $this->settingsManager->get('KBIN_DOMAIN');
        $remote = $this->getUserByUsername('remotefriend');
        $remote->apId = 'remotefriend@remote.example';
        $remote->apProfileId = 'https://remote.example/u/remotefriend';
        $this->entityManager->persist(new MagazineFollow($this->circle, $remote));
        $this->entityManager->flush();

        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:circle@$domain"));
        self::assertResponseIsSuccessful();
        self::assertSame(["https://$domain/m/circle"], json_decode($this->client->getResponse()->getContent(), true)['aliases']);

        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:nosuchmagazine@$domain"));
        $missing = $this->client->getResponse()->getContent();
        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode("acct:vault@$domain"));
        self::assertResponseStatusCodeSame(404);
        self::assertSame($missing, $this->client->getResponse()->getContent());
    }

    /**
     * The client boots a new kernel for each request, so a repository that reads the signed-in
     * user is taken from the current container, after actAs().
     */
    private function repository(): MagazineRepository
    {
        return self::getContainer()->get(MagazineRepository::class);
    }

    private function memberManager(): MagazineMemberManager
    {
        return self::getContainer()->get(MagazineMemberManager::class);
    }

    private function actAs(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function get(string $path): string
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $this->client->getResponse()->getContent();
    }

    /** @var array<string, string> */
    private array $tokens = [];

    private function getApi(string $path, ?User $user = null): string
    {
        $server = [];
        if ($user) {
            if (!isset($this->tokens[$user->username])) {
                if (!$this->tokens) {
                    self::createOAuth2AuthCodeClient();
                }
                $this->client->loginUser($user);
                $codes = self::getAuthorizationCodeTokenResponse($this->client);
                $this->tokens[$user->username] = $codes['token_type'].' '.$codes['access_token'];
            }
            $server['HTTP_AUTHORIZATION'] = $this->tokens[$user->username];
        }
        $this->client->request('GET', $path, server: $server);
        self::assertResponseIsSuccessful($path);

        return json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES);
    }
}
