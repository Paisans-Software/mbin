<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\DTO\ModeratorDto;
use App\Entity\Domain;
use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\PageView\EntryCommentPageView;
use App\Repository\EntryCommentRepository;
use App\Repository\EntryRepository;
use App\Repository\MagazineMemberRepository;
use App\Repository\SearchRepository;
use App\Service\MagazineMemberManager;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Listings that did not go through the magazine visibility filter: comment feeds, profile
 * tabs, tags, domains, the latest posts and pinned entries, the modlog, the user content
 * API, search, and another user's magazine lists. The fixture is RestrictedContentFixtureTrait
 * (circle private, alice member, bob not) plus a hidden "vault", alice's own content in
 * circle and in the public "open", and her boosts.
 */
class RestrictedBypassListingTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    private const string CIRCLE_COMMENT = 'Alice circle comment';
    private const string OPEN_COMMENT = 'Alice open comment';
    private const string CIRCLE_REPLY = 'Alice circle reply';
    private const string OPEN_REPLY = 'Alice open reply';
    private const string PUBLIC_ENTRY = 'Public entry title';
    private const string PUBLIC_POST = 'Public post body';

    private User $admin;
    private Magazine $vault;
    private Entry $publicEntry;
    private Post $publicPost;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();

        $this->admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->vault = $this->getMagazineByName('vault', $this->admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->alice->showProfileSubscriptions = true;
        $this->entityManager->flush();

        $this->publicEntry = $this->createEntry(self::PUBLIC_ENTRY, $this->open, $this->admin, 'https://secret.example/public');
        $this->publicPost = $this->createPost(self::PUBLIC_POST, $this->open, $this->admin);

        $this->createEntryComment(self::CIRCLE_COMMENT.' #recovery', $this->entry, $this->alice);
        $this->createEntryComment(self::OPEN_COMMENT.' #recovery', $this->publicEntry, $this->alice);
        $this->createPostComment(self::CIRCLE_REPLY.' #recovery', $this->post, $this->alice);
        $this->createPostComment(self::OPEN_REPLY.' #recovery', $this->publicPost, $this->alice);

        $this->voteManager->vote(1, $this->entry, $this->alice);
        $this->voteManager->vote(1, $this->publicEntry, $this->alice);
    }

    public function testCommentFeedHidesRestrictedComments(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/comments');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
        self::assertNotSeen('Secret entry comment', $body);

        $this->client->loginUser($this->alice);
        $body = $this->get('/comments');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertSeen(self::CIRCLE_COMMENT, $body);
    }

    public function testAnonymousCommentFeedHidesRestrictedComments(): void
    {
        $body = $this->get('/comments');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
    }

    public function testProfileCommentsAndRepliesTabs(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/u/alice/comments');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
        $body = $this->get('/u/alice/replies');
        self::assertSeen(self::OPEN_REPLY, $body);
        self::assertNotSeen(self::CIRCLE_REPLY, $body);

        $this->client->loginUser($this->alice);
        self::assertSeen(self::CIRCLE_COMMENT, $this->get('/u/alice/comments'));
        self::assertSeen(self::CIRCLE_REPLY, $this->get('/u/alice/replies'));
    }

    public function testProfileOverviewAndBoostsTabs(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/u/alice');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_REPLY, $body);
        $body = $this->get('/u/alice/boosts');
        self::assertSeen(self::PUBLIC_ENTRY, $body);
        self::assertNotSeen('Secret entry title', $body);

        $this->client->loginUser($this->alice);
        $body = $this->get('/u/alice');
        self::assertSeen(self::CIRCLE_COMMENT, $body);
        self::assertSeen(self::CIRCLE_REPLY, $body);
        self::assertSeen('Secret entry title', $this->get('/u/alice/boosts'));
    }

    public function testTagOverviewAndTagComments(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/tag/recovery');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertSeen(self::OPEN_REPLY, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_REPLY, $body);
        // the tag comments page itself fails to render any comment (its template lacks
        // `criteria`), so its query is checked through the repository it uses
        self::assertSame([self::OPEN_COMMENT.' #recovery'], $this->tagComments($this->bob));

        $this->client->loginUser($this->alice);
        $body = $this->get('/tag/recovery');
        self::assertSeen(self::CIRCLE_COMMENT, $body);
        self::assertSeen(self::CIRCLE_REPLY, $body);
        self::assertContains(self::CIRCLE_COMMENT.' #recovery', $this->tagComments($this->alice));
    }

    public function testDomainCommentsPage(): void
    {
        $this->client->loginUser($this->bob);
        $body = $this->get('/d/secret.example/comments');
        self::assertSeen(self::OPEN_COMMENT, $body);
        self::assertNotSeen(self::CIRCLE_COMMENT, $body);
        self::assertNotSeen('Secret entry comment', $body);

        $this->client->loginUser($this->alice);
        self::assertSeen(self::CIRCLE_COMMENT, $this->get('/d/secret.example/comments'));
    }

    public function testRelatedPostsUsesFilteredLatest(): void
    {
        $bodies = fn (?User $user) => array_map(fn (Post $post) => $post->body, $this->postRepository->findLast(50, $user));

        self::assertContains(self::PUBLIC_POST, $bodies($this->bob));
        self::assertNotContains('Secret post body', $bodies($this->bob));
        self::assertNotContains('Secret post body', $bodies(null));
        self::assertContains('Secret post body', $bodies($this->alice));
    }

    public function testPinnedEntriesFiltered(): void
    {
        $this->entryManager->pin($this->entry, $this->admin);

        $this->actAs($this->bob);
        self::assertSame([], self::getContainer()->get(EntryRepository::class)->findPinned($this->circle, onlyVisibleToViewer: true));

        $this->actAs($this->alice);
        self::assertSame(['Secret entry title'], array_map(fn (Entry $e) => $e->title, self::getContainer()->get(EntryRepository::class)->findPinned($this->circle, onlyVisibleToViewer: true)));

        // the remote featured collection sync reads every pinned entry, whoever is signed in
        $this->actAs($this->bob);
        self::assertCount(1, self::getContainer()->get(EntryRepository::class)->findPinned($this->circle));
    }

    public function testInstanceModlogHidesRestrictedEntries(): void
    {
        $doomed = $this->createEntry('Alice doomed circle entry', $this->circle, $this->alice);
        $this->entryManager->delete($this->admin, $doomed);
        $doomedPublic = $this->createEntry('Alice doomed open entry', $this->open, $this->alice);
        $this->entryManager->delete($this->admin, $doomedPublic);

        $this->client->loginUser($this->bob);
        $body = $this->get('/modlog');
        self::assertSeen('Alice doomed open entry', $body);
        self::assertNotSeen('Alice doomed circle entry', $body);

        $this->client->request('GET', '/api/modlog', server: ['HTTP_AUTHORIZATION' => $this->token($this->bob)]);
        self::assertResponseIsSuccessful();
        $json = json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES);
        // the API leaves the title of a removed entry out, so look at the magazine
        self::assertSeen('"name":"open"', $json);
        self::assertNotSeen('"name":"circle"', $json);

        $this->client->loginUser($this->alice);
        self::assertSeen('Alice doomed circle entry', $this->get('/modlog'));
    }

    public function testApiUserCommentsAndBoosts(): void
    {
        $id = $this->alice->getId();
        foreach (["/api/users/$id/comments", "/api/users/$id/content"] as $path) {
            $json = $this->api($path, $this->bob);
            self::assertSeen(self::OPEN_COMMENT, $json, $path);
            self::assertNotSeen(self::CIRCLE_COMMENT, $json, $path);
            self::assertSeen(self::CIRCLE_COMMENT, $this->api($path, $this->alice), $path);
        }

        $json = $this->api("/api/users/$id/boosts", $this->bob);
        self::assertSeen(self::PUBLIC_ENTRY, $json);
        self::assertNotSeen('Secret entry title', $json);
        self::assertSeen('Secret entry title', $this->api("/api/users/$id/boosts", $this->alice));
    }

    public function testApiTagAndDomainCommentEndpoints(): void
    {
        $domainId = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'secret.example'])->getId();
        $checks = [
            '/api/tag/recovery/entryComments' => [self::OPEN_COMMENT, self::CIRCLE_COMMENT],
            '/api/tag/recovery/postComments' => [self::OPEN_REPLY, self::CIRCLE_REPLY],
            "/api/domain/$domainId/comments" => [self::OPEN_COMMENT, self::CIRCLE_COMMENT],
        ];

        foreach ($checks as $path => [$public, $restricted]) {
            $json = $this->api($path, $this->bob);
            self::assertSeen($public, $json, $path);
            self::assertNotSeen($restricted, $json, $path);
            self::assertSeen($restricted, $this->api($path, $this->alice), $path);
        }
    }

    #[Group('NonThreadSafe')]
    public function testApiSearchV1AndV2(): void
    {
        // v1 only serialises content, so it is asked for content only
        foreach (['/api/search?q=Alice&type=entry', '/api/search/v2?q=Alice'] as $path) {
            $json = $this->api($path, $this->bob);
            self::assertSeen(self::OPEN_COMMENT, $json, $path);
            self::assertNotSeen(self::CIRCLE_COMMENT, $json, $path);
            self::assertSeen(self::CIRCLE_COMMENT, $this->api($path, $this->alice), $path);
        }

        // the magazine part of v2: hidden is left out for everyone but admins and global moderators
        self::assertNotSeen('"name":"vault"', $this->api('/api/search/v2?q=vault', $this->bob));
        self::assertSeen('"name":"vault"', $this->api('/api/search/v2?q=vault', $this->admin));
    }

    public function testLookupByApIdAndBoostCount(): void
    {
        $this->entry->apId = 'https://remote.example/e/1';
        $this->entityManager->flush();

        $found = fn () => array_map(fn ($o) => $o::class, self::getContainer()->get(SearchRepository::class)->findByApId('https://remote.example/e/1'));
        $boosts = fn () => (int) self::getContainer()->get(SearchRepository::class)->countBoosts($this->alice);

        $this->actAs($this->bob);
        self::assertSame([], $found());
        self::assertSame(1, $boosts());

        $this->actAs($this->alice);
        self::assertSame([Entry::class], $found());
        self::assertSame(2, $boosts());
    }

    public function testOtherUsersSubscriptionListOmitsRestricted(): void
    {
        $this->magazineManager->subscribe($this->circle, $this->alice);
        $this->magazineManager->subscribe($this->open, $this->alice);

        $this->client->loginUser($this->bob);
        $body = $this->get('/u/alice/subscriptions');
        self::assertSeen('/m/open', $body);
        self::assertNotSeen('/m/circle', $this->mainContent($body));

        $json = $this->api("/api/users/{$this->alice->getId()}/magazines/subscriptions", $this->bob);
        self::assertSeen('"name":"open"', $json);
        self::assertNotSeen('"name":"circle"', $json);

        // alice's own view of the same list, and a member viewing it, are unfiltered
        $this->client->loginUser($this->alice);
        self::assertSeen('/m/circle', $this->mainContent($this->get('/u/alice/subscriptions')));
        self::assertSeen('"name":"circle"', $this->api("/api/users/{$this->alice->getId()}/magazines/subscriptions", $this->alice));
        self::assertSeen('"name":"circle"', $this->api('/api/magazines/subscribed', $this->alice));
    }

    public function testOtherUsersModeratedListOmitsRestricted(): void
    {
        $this->magazineManager->addModerator(new ModeratorDto($this->circle, $this->alice, $this->admin));
        $this->magazineManager->addModerator(new ModeratorDto($this->open, $this->alice, $this->admin));

        $this->client->loginUser($this->bob);
        $body = $this->mainContent($this->get('/u/alice/moderated'));
        self::assertSeen('/m/open', $body);
        self::assertNotSeen('/m/circle', $body);

        $json = $this->api("/api/users/{$this->alice->getId()}/moderatedMagazines", $this->bob);
        self::assertSeen('"name":"open"', $json);
        self::assertNotSeen('"name":"circle"', $json);

        $this->client->loginUser($this->alice);
        self::assertSeen('/m/circle', $this->mainContent($this->get('/u/alice/moderated')));
        self::assertSeen('"name":"circle"', $this->api('/api/magazines/moderated', $this->alice));
    }

    public function testOwnSidebarShowsHiddenMembership(): void
    {
        $this->memberManager()->add($this->vault, $this->alice, $this->admin);
        $this->magazineManager->subscribe($this->vault, $this->alice);

        $this->client->loginUser($this->alice);
        $this->get('/all');
        self::assertSelectorExists('.sidebar-subscriptions a[href$="/m/vault"]');
    }

    /**
     * @return string[] the bodies of the entry comments tagged #recovery, as $user sees them
     */
    private function tagComments(User $user): array
    {
        $this->actAs($user);
        $container = self::getContainer();
        $criteria = new EntryCommentPageView(1, $container->get(Security::class));
        $criteria->setTag('recovery');

        return array_map(fn (EntryComment $c) => $c->body, iterator_to_array($container->get(EntryCommentRepository::class)->findByCriteria($criteria)->getCurrentPageResults()));
    }

    /**
     * Sign $user in for services called directly. The client boots a new kernel for each
     * request, so take those services from self::getContainer() after calling this.
     */
    private function actAs(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function memberManager(): MagazineMemberManager
    {
        /** @var MagazineMemberRepository $members */
        $members = $this->entityManager->getRepository(MagazineMember::class);

        return new MagazineMemberManager($members, $this->magazineManager, $this->entityManager);
    }

    /** @var array<string, string> */
    private array $tokens = [];

    private function token(User $user): string
    {
        if (!isset($this->tokens[$user->username])) {
            if (!$this->tokens) {
                self::createOAuth2AuthCodeClient();
            }
            $this->client->loginUser($user);
            $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write magazine:subscribe moderate:magazine:list');
            $this->tokens[$user->username] = $codes['token_type'].' '.$codes['access_token'];
        }

        return $this->tokens[$user->username];
    }

    private function api(string $path, User $user): string
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => $this->token($user)]);
        self::assertResponseIsSuccessful($path);

        return json_encode(json_decode($this->client->getResponse()->getContent(), true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The page body without the top bar and sidebar, which list magazines for discovery.
     */
    private function mainContent(string $html): string
    {
        $crawler = new Crawler($html);

        return $crawler->filter('#main')->count() ? $crawler->filter('#main')->html() : $html;
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
