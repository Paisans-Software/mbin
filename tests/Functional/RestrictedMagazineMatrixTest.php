<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DTO\ModeratorDto;
use App\Entity\Entry;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineManager;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every viewer against every surface of a private and of a hidden magazine.
 *
 * A viewer is a member, a moderator of the magazine, a global moderator or an admin. The
 * admin owns the magazines and wrote their content; a second admin with no ties to either
 * shows that the admin bypass alone grants the view.
 * Anonymous visitors and signed-in non-members see nothing: the stub on a private
 * magazine's front page, a 404 on every content route and on a hidden magazine's front
 * page, and no trace in listings. RSS is 404 for everyone, viewer or not. ActivityPub
 * fetches carry no user, so the entry object is checked anonymously only.
 *
 * In the magazine directory (/api/magazines) a private magazine is listed for everyone
 * and a hidden one for every viewer who may view it: hidden means hidden from those who
 * cannot.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedMagazineMatrixTest extends WebTestCase
{
    private const array VIEWERS = ['anonymous', 'non-member', 'member', 'magazine moderator', 'global moderator', 'admin', 'unattached admin'];
    private const array LEVELS = ['private' => 'circle', 'hidden' => 'vault'];
    private const array SURFACES = ['front', 'entry', 'post', 'all', 'comments', 'search', 'profile', 'api entry', 'api magazines', 'rss', 'ap entry'];

    /**
     * A word only the restricted content carries, so search finds nothing else. Every
     * piece of content in a magazine starts with "MARK name", which is what a non-viewer
     * must never see: the bare word alone can appear as the echoed search query.
     */
    private const string MARK = 'Quokka';

    /** @var array<string, Entry> */
    private array $restrictedEntries = [];
    /** @var array<string, Post> */
    private array $restrictedPosts = [];
    /** The positive control for surfaces that answer 404 to everyone on restricted content. */
    private Entry $publicEntry;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function matrix(): iterable
    {
        foreach (self::VIEWERS as $viewer) {
            foreach (array_keys(self::LEVELS) as $level) {
                foreach (self::SURFACES as $surface) {
                    if ('ap entry' === $surface && 'anonymous' !== $viewer) {
                        continue;
                    }
                    yield "$viewer / $level / $surface" => [$viewer, $level, $surface];
                }
            }
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->getUserByUsername('bob');
        $alice = $this->getUserByUsername('alice');
        $mmod = $this->getUserByUsername('mmod');
        $this->getUserByUsername('gmod', isModerator: true);
        $this->getUserByUsername('root', isAdmin: true);
        $this->publicEntry = $this->createEntry('Public control entry', $this->getMagazineByName('open', $admin), $admin);

        $members = static::getContainer()->get(MagazineMemberManager::class);

        foreach (self::LEVELS as $level => $name) {
            $magazine = $this->getMagazineByName($name, $admin);
            $magazine->access = 'private' === $level ? MagazineAccess::Private : MagazineAccess::Hidden;
            $this->entityManager->flush();

            $members->add($magazine, $alice, $admin);
            $dto = new ModeratorDto($magazine);
            $dto->user = $mmod;
            $dto->addedBy = $admin;
            self::getContainer()->get(MagazineManager::class)->addModerator($dto);

            $entry = $this->createEntry(self::MARK." $name entry title", $magazine, $admin);
            $this->createEntryComment(self::MARK." $name entry comment", $entry, $admin);
            $post = $this->createPost(self::MARK." $name post body", $magazine, $admin);
            $this->createPostComment(self::MARK." $name post comment", $post, $admin);
            $this->restrictedEntries[$level] = $entry;
            $this->restrictedPosts[$level] = $post;
        }
    }

    #[DataProvider('matrix')]
    public function testSurface(string $viewer, string $level, string $surface): void
    {
        $name = self::LEVELS[$level];
        $user = $this->viewer($viewer);
        $sees = null !== $user && 'non-member' !== $viewer;
        $entry = $this->restrictedEntries[$level];
        $post = $this->restrictedPosts[$level];
        $entryTitle = self::MARK." $name entry title";

        switch ($surface) {
            case 'front':
                $body = $this->html("/m/$name", $user);
                if ($sees) {
                    $this->assertOk($body);
                    self::assertStringContainsString($entryTitle, $body);
                    self::assertSelectorNotExists('[data-magazine-stub]');
                } elseif ('private' === $level) {
                    $this->assertOk($body);
                    self::assertSelectorExists('[data-magazine-stub]');
                    self::assertStringNotContainsString(self::MARK." $name", $body);
                } else {
                    self::assertResponseStatusCodeSame(404);
                }
                break;

            case 'entry':
                $body = $this->html("/m/$name/t/{$entry->getId()}/-", $user);
                $this->assertSeenOr404($sees, $entryTitle, $name, $body);
                break;

            case 'post':
                $body = $this->html("/m/$name/p/{$post->getId()}/-", $user);
                $this->assertSeenOr404($sees, self::MARK." $name post body", $name, $body);
                break;

            case 'all':
                $this->assertListed($sees, $entryTitle, $name, $this->html('/all', $user));
                break;

            case 'comments':
                $this->assertListed($sees, self::MARK." $name entry comment", $name, $this->html('/comments', $user));
                break;

            case 'search':
                $this->assertListed($sees, $entryTitle, $name, $this->html('/search?search%5Bq%5D='.self::MARK, $user));
                break;

            case 'profile':
                $this->assertListed($sees, $entryTitle, $name, $this->html('/u/admin', $user));
                break;

            case 'api entry':
                $this->api("/api/entry/{$entry->getId()}", $user);
                if ($sees) {
                    self::assertResponseIsSuccessful();
                    self::assertSame($entryTitle, self::getJsonResponse($this->client)['title']);
                } else {
                    self::assertResponseStatusCodeSame(404);
                }
                break;

            case 'api magazines':
                $this->api('/api/magazines?perPage=100', $user);
                self::assertResponseIsSuccessful();
                $names = array_column(self::getJsonResponse($this->client)['items'], 'name');
                $listed = 'private' === $level || !\in_array($viewer, ['anonymous', 'non-member'], true);
                self::assertSame($listed, \in_array($name, $names, true), implode(', ', $names));
                break;

            case 'rss':
                $this->html('/rss?magazine=open', $user);
                self::assertResponseIsSuccessful('positive control: a public magazine has a feed');
                $this->html("/rss?magazine=$name", $user);
                self::assertResponseStatusCodeSame(404);
                $this->html("/rss?magazine=$name&content=microblog", $user);
                self::assertResponseStatusCodeSame(404);
                break;

            case 'ap entry':
                // requests arrive on the instance's own host, so the ids built while answering are local URLs
                $ap = [
                    'HTTP_ACCEPT' => 'application/activity+json',
                    'HTTP_HOST' => $this->settingsManager->get('KBIN_DOMAIN'),
                    'HTTPS' => 'on',
                ];
                $this->client->request('GET', "/m/open/t/{$this->publicEntry->getId()}/-", server: $ap);
                self::assertResponseIsSuccessful('positive control: a public entry is served');
                $this->client->request('GET', "/m/$name/t/{$entry->getId()}/-", server: $ap);
                self::assertResponseStatusCodeSame(404);
                break;

            default:
                self::fail("unknown surface $surface");
        }
    }

    private function viewer(string $viewer): ?User
    {
        return match ($viewer) {
            'anonymous' => null,
            'non-member' => $this->getUserByUsername('bob'),
            'member' => $this->getUserByUsername('alice'),
            'magazine moderator' => $this->getUserByUsername('mmod'),
            'global moderator' => $this->getUserByUsername('gmod', isModerator: true),
            'admin' => $this->getUserByUsername('admin', isAdmin: true),
            'unattached admin' => $this->getUserByUsername('root', isAdmin: true),
            default => throw new \LogicException("unknown viewer $viewer"),
        };
    }

    private function html(string $path, ?User $user): string
    {
        if ($user) {
            $this->client->loginUser($user);
        }
        $this->client->request('GET', $path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function api(string $path, ?User $user): void
    {
        $server = [];
        if ($user) {
            self::createOAuth2AuthCodeClient();
            $this->client->loginUser($user);
            $codes = self::getAuthorizationCodeTokenResponse($this->client);
            $server['HTTP_AUTHORIZATION'] = $codes['token_type'].' '.$codes['access_token'];
        }
        $this->client->jsonRequest('GET', $path, server: $server);
    }

    private function assertOk(string $body): void
    {
        self::assertResponseIsSuccessful(substr($body, 0, 500));
    }

    private function assertSeenOr404(bool $sees, string $needle, string $name, string $body): void
    {
        if ($sees) {
            $this->assertOk($body);
            self::assertStringContainsString($needle, $body);
        } else {
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString(self::MARK." $name", $body);
        }
    }

    private function assertListed(bool $sees, string $needle, string $name, string $body): void
    {
        $this->assertOk($body);
        if ($sees) {
            self::assertStringContainsString($needle, $body);
        } else {
            self::assertStringNotContainsString(self::MARK." $name", $body);
        }
    }
}
