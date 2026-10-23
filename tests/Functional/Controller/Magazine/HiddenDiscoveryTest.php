<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\Controller\User\ThemeSettingsController;
use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\MagazineRepository;
use App\Tests\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Magazine discovery lists a private magazine and leaves a hidden one out, except for
 * admins and global moderators. WebFinger resolves neither.
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

    /**
     * The client boots a new kernel for each request, so a repository that reads the signed-in
     * user is taken from the current container, after actAs().
     */
    private function repository(): MagazineRepository
    {
        return self::getContainer()->get(MagazineRepository::class);
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

    private function getApi(string $path): string
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES);
    }
}
