<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Entry;
use App\Entity\Post;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedMovementTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();
    }

    /**
     * The change_magazine CSRF token is the same for every subject, and the form is only
     * offered for public content, so it is read from a public entry's panel.
     */
    private function moderateToken(): string
    {
        $public = $this->createEntry('Token source', $this->open, $this->getUserByUsername('admin'), 'https://public.example/token');
        $crawler = $this->client->request('GET', "/m/open/t/{$public->getId()}/-/moderate");
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[name=change_magazine] input[name=token]')->attr('value');
    }

    public function testMoveFormIsNotOfferedForRestrictedContent(): void
    {
        $public = $this->createPost('Public post', $this->open, $this->getUserByUsername('admin'));
        $this->client->loginUser($this->getUserByUsername('admin'));

        $this->client->request('GET', "/m/circle/t/{$this->entry->getId()}/-/moderate");
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name=change_magazine]');
        $this->client->request('GET', "/m/circle/p/{$this->post->getId()}/-/moderate");
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name=change_magazine]');

        $this->client->request('GET', "/m/open/p/{$public->getId()}/-/moderate");
        self::assertSelectorExists('form[name=change_magazine]', 'positive control: public content keeps the form');
    }

    private function moveEntry(Entry $entry, string $target): void
    {
        $token = $this->moderateToken();
        $this->client->request(
            'POST',
            \sprintf('/m/%s/e/%d/-/change_magazine', $entry->magazine->name, $entry->getId()),
            ['token' => $token, 'change_magazine' => ['new_magazine' => $target]],
            server: ['HTTP_REFERER' => '/']
        );
    }

    public function testCrosspostFormFromRestrictedEntryRefused(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/crosspost/'.$this->entry->getId());

        self::assertResponseRedirects();
        self::assertStringNotContainsString('new_entry', $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('/t/'.$this->entry->getId(), $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert__danger', 'This thread cannot be cross-posted.');

        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/crosspost/'.$this->entry->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCrosspostFromPublicEntryStillWorks(): void
    {
        $entry = $this->createEntry('Public entry', $this->open, $this->bob, 'https://public.example/link');

        $this->client->loginUser($this->bob);
        $this->client->request('GET', '/crosspost/'.$entry->getId());

        self::assertResponseRedirects();
        self::assertStringContainsString('public.example', urldecode($this->client->getResponse()->headers->get('Location')));
    }

    public function testMoveRestrictedToPublicRefused(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin'));
        $this->moveEntry($this->entry, 'open');

        self::assertResponseRedirects();
        $this->entityManager->clear();
        self::assertSame('circle', $this->entityManager->find(Entry::class, $this->entry->getId())->magazine->name);
    }

    public function testMovePublicToRestrictedRefused(): void
    {
        $public = $this->createEntry('Public entry', $this->open, $this->getUserByUsername('admin'), 'https://public.example/x');
        $this->client->loginUser($this->getUserByUsername('admin'));
        $this->moveEntry($public, 'circle');

        self::assertResponseRedirects();
        $this->entityManager->clear();
        self::assertSame('open', $this->entityManager->find(Entry::class, $public->getId())->magazine->name);
    }

    public function testMovePostRestrictedToPublicRefused(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin'));
        $token = $this->moderateToken();
        $this->client->request(
            'POST',
            \sprintf('/m/circle/p/%d/-/change_magazine', $this->post->getId()),
            ['token' => $token, 'change_magazine' => ['new_magazine' => 'open']],
            server: ['HTTP_REFERER' => '/']
        );

        self::assertResponseRedirects();
        $this->entityManager->clear();
        self::assertSame('circle', $this->entityManager->find(Post::class, $this->post->getId())->magazine->name);
    }

    public function testApiMoveRefusedWith400(): void
    {
        $admin = $this->getUserByUsername('admin');
        $public = $this->createEntry('Public entry', $this->open, $admin, 'https://public.example/y');
        [$entryId, $publicId, $openId, $circleId] = [$this->entry->getId(), $public->getId(), $this->open->getId(), $this->circle->getId()];
        self::createOAuth2AuthCodeClient();
        $this->client->loginUser($admin);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read admin:magazine:move_entry');
        $server = ['HTTP_AUTHORIZATION' => $codes['token_type'].' '.$codes['access_token']];

        $this->client->jsonRequest('PUT', \sprintf('/api/admin/entry/%d/change-magazine/%d', $entryId, $openId), server: $server);
        self::assertResponseStatusCodeSame(400);

        $this->client->jsonRequest('PUT', \sprintf('/api/admin/entry/%d/change-magazine/%d', $publicId, $circleId), server: $server);
        self::assertResponseStatusCodeSame(400);

        $this->entityManager->clear();
        self::assertSame('circle', $this->entityManager->find(Entry::class, $entryId)->magazine->name);
        self::assertSame('open', $this->entityManager->find(Entry::class, $publicId)->magazine->name);
    }
}
