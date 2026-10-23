<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\DTO\MagazineBanDto;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\MagazineMemberRepository;
use App\Service\MagazineMemberManager;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Listings drop content from magazines the viewer cannot see. The fixture is
 * RestrictedContentFixtureTrait (circle private, alice member, bob not) plus a
 * hidden magazine "vault" and one public entry and post in "open".
 */
class RestrictedListingTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private const string PUBLIC_ENTRY = 'Public entry title';
    private const string PUBLIC_POST = 'Public post body';
    private const string HIDDEN_ENTRY = 'Vaulted entry title';

    private User $admin;
    private Magazine $vault;
    private Entry $publicEntry;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $this->admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->vault = $this->getMagazineByName('vault', $this->admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->entityManager->flush();
        $this->createEntry(self::HIDDEN_ENTRY, $this->vault, $this->admin);

        $this->publicEntry = $this->createEntry(self::PUBLIC_ENTRY, $this->open, $this->admin);
        $this->createPost(self::PUBLIC_POST, $this->open, $this->admin);
    }

    public function testFrontAllFeedHidesRestrictedFromNonMember(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/all');

        self::assertSeen(self::PUBLIC_ENTRY, $body);
        self::assertNotSeen('Secret entry title', $body);
        self::assertNotSeen(self::HIDDEN_ENTRY, $body);
    }

    public function testAnonymousFrontAllFeedHidesRestricted(): void
    {
        $body = $this->get('/all');

        self::assertSeen(self::PUBLIC_ENTRY, $body);
        self::assertNotSeen('Secret entry title', $body);
        self::assertNotSeen(self::HIDDEN_ENTRY, $body);
    }

    public function testFrontAllFeedShowsRestrictedToMember(): void
    {
        $this->client->loginUser($this->alice);
        $body = $this->get('/all');

        self::assertSeen(self::PUBLIC_ENTRY, $body);
        self::assertSeen('Secret entry title', $body);
        // alice is not a member of vault
        self::assertNotSeen(self::HIDDEN_ENTRY, $body);
    }

    public function testAdminSeesEverythingInFrontFeed(): void
    {
        $this->client->loginUser($this->admin);
        $body = $this->get('/all');

        self::assertSeen('Secret entry title', $body);
        self::assertSeen(self::HIDDEN_ENTRY, $body);
    }

    public function testCombinedFeedAndMicroblogFeed(): void
    {
        foreach (['/all/microblog', '/all/combined'] as $path) {
            $this->client->loginUser($this->bob);
            $body = $this->get($path);
            self::assertSeen(self::PUBLIC_POST, $body, $path);
            self::assertNotSeen('Secret post body', $body, $path);

            $this->client->loginUser($this->alice);
            $body = $this->get($path);
            self::assertSeen(self::PUBLIC_POST, $body, $path);
            self::assertSeen('Secret post body', $body, $path);
        }
    }

    public function testRelatedAndLatestEntriesWidgets(): void
    {
        // the sidebar on a magazine page shows entries tagged with the magazine's name
        $this->createEntry('Secret tagged entry', $this->circle, $this->admin, body: 'about #open');
        $this->createEntry('Public tagged entry', $this->open, $this->admin, body: 'about #open');

        $this->client->loginUser($this->bob);
        $body = $this->get(\sprintf('/m/open/t/%d/-', $this->publicEntry->getId()));
        self::assertSeen('Public tagged entry', $body);
        self::assertNotSeen('Secret tagged entry', $body);

        // a page without a magazine shows the latest entries in the sidebar
        $body = $this->get('/all/microblog');
        self::assertNotSeen('Secret tagged entry', $body);
        self::assertNotSeen('Secret entry title', $body);

        $this->client->loginUser($this->alice);
        $body = $this->get(\sprintf('/m/open/t/%d/-', $this->publicEntry->getId()));
        self::assertSeen('Secret tagged entry', $body);
    }

    public function testCrossPostsExcludeRestricted(): void
    {
        $cross = $this->createEntry('Public cross entry', $this->open, $this->admin, 'https://secret.example/link');

        $this->client->loginUser($this->bob);
        $body = $this->get(\sprintf('/m/open/t/%d/-', $cross->getId()));
        self::assertNotSeen('Secret entry title', $body);
        self::assertNotSeen('/m/circle', $body);

        $this->client->loginUser($this->alice);
        $body = $this->get(\sprintf('/m/open/t/%d/-', $cross->getId()));
        self::assertSeen('Secret entry title', $body);
    }

    public function testSearchHidesRestrictedContent(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/search?search%5Bq%5D=Secret');
        self::assertNotSeen('Secret entry title', $body);
        self::assertNotSeen('Secret post body', $body);

        $this->client->loginUser($this->alice);
        $body = $this->get('/search?search%5Bq%5D=Secret');
        self::assertSeen('Secret entry title', $body);
    }

    public function testUserOutboxExcludesRestricted(): void
    {
        $this->createEntry('Alice circle entry', $this->circle, $this->alice);
        $this->createEntry('Alice open entry', $this->open, $this->alice);

        // signed in as a member, the outbox is still the anonymous one
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/u/alice/outbox?page=1', server: ['HTTP_ACCEPT' => 'application/activity+json']);
        self::assertResponseIsSuccessful();
        $json = json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES);

        self::assertSeen('Alice open entry', $json);
        self::assertNotSeen('Alice circle entry', $json);
        self::assertNotSeen('/m/circle', $json);
    }

    public function testBannedMemberSeesNoListingItems(): void
    {
        $dto = new MagazineBanDto();
        $dto->reason = 'test';
        $this->magazineManager->ban($this->circle, $this->alice, $this->admin, $dto);

        $this->client->loginUser($this->alice);
        $body = $this->get('/all');
        self::assertSeen(self::PUBLIC_ENTRY, $body);
        self::assertNotSeen('Secret entry title', $body);
    }

    public function testRemovedMemberStopsSeeingOnNextRequest(): void
    {
        $this->createEntry('Secret tagged entry', $this->circle, $this->admin, body: 'about #open');
        $cross = $this->createEntry('Public cross entry', $this->open, $this->admin, 'https://secret.example/link', body: 'about #open');
        $crossPage = \sprintf('/m/open/t/%d/-', $cross->getId());

        // warm every cache alice's pages go through
        $this->client->loginUser($this->alice);
        self::assertSeen('Secret entry title', $this->get('/all'));
        $body = $this->get($crossPage);
        self::assertSeen('Secret entry title', $body);
        self::assertSeen('Secret tagged entry', $body);

        $this->memberManager()->remove($this->circle, $this->alice);

        self::assertNotSeen('Secret entry title', $this->get('/all'));
        $body = $this->get($crossPage);
        self::assertNotSeen('Secret entry title', $body);
        self::assertNotSeen('Secret tagged entry', $body);
    }

    public function testUserOutboxExcludesBoostsOfRestrictedContent(): void
    {
        $this->memberManager()->add($this->vault, $this->alice, $this->admin);
        $vaultEntry = $this->createEntry('Vault boosted entry', $this->vault, $this->admin);

        $this->voteManager->vote(1, $this->entry, $this->alice);
        $this->voteManager->vote(1, $vaultEntry, $this->alice);
        $this->voteManager->vote(1, $this->publicEntry, $this->alice);

        $this->client->request('GET', '/u/alice/outbox?page=1', server: ['HTTP_ACCEPT' => 'application/activity+json']);
        self::assertResponseIsSuccessful();
        $json = json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES);
        $items = json_decode($json, true)['orderedItems'];

        self::assertNotSeen('/m/circle', $json);
        self::assertNotSeen('/m/vault', $json);
        $boosts = array_filter($items, fn (array $item) => 'Announce' === $item['type']);
        self::assertCount(1, $boosts);
        self::assertSeen('/m/open/t/'.$this->publicEntry->getId(), json_encode(array_values($boosts)[0], JSON_UNESCAPED_SLASHES));
    }

    private function memberManager(): MagazineMemberManager
    {
        /** @var MagazineMemberRepository $members */
        $members = $this->entityManager->getRepository(MagazineMember::class);

        return new MagazineMemberManager($members, $this->magazineManager, $this->entityManager);
    }

    private function get(string $path): string
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $this->client->getResponse()->getContent();
    }

    private static function assertSeen(string $needle, string $haystack, string $message = ''): void
    {
        self::assertTrue(str_contains($haystack, $needle), trim("expected \"$needle\" in the response. $message"));
    }

    private static function assertNotSeen(string $needle, string $haystack, string $message = ''): void
    {
        self::assertFalse(str_contains($haystack, $needle), trim("did not expect \"$needle\" in the response. $message"));
    }
}
