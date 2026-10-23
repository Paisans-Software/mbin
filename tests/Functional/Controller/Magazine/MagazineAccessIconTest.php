<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\Controller\User\ThemeSettingsController;
use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * A private magazine carries a lock and a hidden one an eye-slash beside its name; a public one carries none.
 */
class MagazineAccessIconTest extends WebTestCase
{
    private User $admin;
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
    }

    public function testDirectoryShowsLockOnPrivate(): void
    {
        $this->client->loginUser($this->getUserByUsername('bob'));
        $crawler = $this->client->request('GET', '/magazines');
        self::assertResponseIsSuccessful();

        $icon = $crawler->filterXPath('//a[contains(@href, "/m/circle")]//i[@data-magazine-access="private"]');
        self::assertGreaterThan(0, $icon->count());
        // fa-lock already means posting-restricted upstream, so private has its own glyph
        self::assertContains('fa-user-lock', explode(' ', $icon->attr('class')));
        self::assertNotContains('fa-lock', explode(' ', $icon->attr('class')));
        self::assertSame('Private', $icon->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-magazine-access="hidden"]'));
    }

    public function testDirectoryCardsShowLockOnPrivate(): void
    {
        $this->client->loginUser($this->getUserByUsername('bob'));
        $crawler = $this->client->request('GET', '/magazines/default/cards');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('section.magazine [data-magazine-access="private"]')->count());
    }

    public function testHiddenIconForAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/magazines');
        self::assertResponseIsSuccessful();

        $icon = $crawler->filterXPath('//a[contains(@href, "/m/vault")]//i[@data-magazine-access="hidden"]');
        self::assertGreaterThan(0, $icon->count());
        self::assertStringContainsString('fa-eye-slash', $icon->attr('class'));
        self::assertSame('Hidden', $icon->attr('aria-label'));

        $crawler = $this->client->request('GET', '/m/vault');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('section.magazine h4 [data-magazine-access="hidden"]')->count());
    }

    public function testNoIconOnPublic(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/m/open');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.head-title [data-magazine-access], section.magazine [data-magazine-access]'));

        $crawler = $this->client->request('GET', '/magazines');
        self::assertCount(0, $crawler->filterXPath('//a[contains(@href, "/m/open")]//i[@data-magazine-access]'));
    }

    public function testPageHeaderShowsIcon(): void
    {
        $alice = $this->getUserByUsername('alice');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $alice, $this->admin);

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/m/circle');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.head-title [data-magazine-access="private"]')->count());

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/m/vault');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.head-title [data-magazine-access="hidden"]')->count());
    }

    public function testSubscriptionsSidebarShowsIcon(): void
    {
        $alice = $this->getUserByUsername('alice');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $alice, $this->admin);
        $this->magazineManager->subscribe($this->circle, $alice);

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/magazines');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.subscription-list [data-magazine-access="private"]')->count());

        $crawler = $this->client->request('GET', '/u/alice/subscriptions');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.magazines [data-magazine-access="private"]')->count());
    }

    public function testPlainMagazineMentionShowsIcon(): void
    {
        $open = $this->magazineRepository->findOneByName('open');
        $entry = $this->createEntry('Mentions circle', $open, $this->admin, body: 'see !circle');

        $this->client->getCookieJar()->set(new Cookie(ThemeSettingsController::MBIN_ENTRIES_SHOW_RICH_MENTION_MAGAZINE, ThemeSettingsController::FALSE));
        $crawler = $this->client->request('GET', "/m/open/t/{$entry->getId()}");
        self::assertResponseIsSuccessful();

        $icon = $crawler->filter('.entry__body a[href="/m/circle"] [data-magazine-access="private"]');
        self::assertCount(1, $icon);
        self::assertSame(0, $crawler->filter('.entry__body .magazine-inline')->count(), 'the plain, not the rich, mention');
    }
}
