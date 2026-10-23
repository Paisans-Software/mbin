<?php

declare(strict_types=1);

namespace App\Tests\Functional\Markdown;

use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Markdown\MarkdownConverter;
use App\Markdown\RenderTarget;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Markdown is cached per text, not per viewer, so a link to restricted content must
 * render the same for everyone: as the plain URL that was written, never inlined.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedLinkRenderingTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->createRestrictedFixture();
    }

    /**
     * @return list<string>
     */
    private function restrictedUrls(): array
    {
        $entry = $this->entry->getId();
        $post = $this->post->getId();

        return [
            "https://kbin.test/m/x/t/$entry",
            "https://kbin.test/m/x/t/$entry/-/comment/{$this->entryComment->getId()}",
            "https://kbin.test/m/x/p/$post",
            "https://kbin.test/m/x/p/$post/-/reply/{$this->postComment->getId()}",
        ];
    }

    private const array SECRETS = ['Secret entry title', 'Secret entry comment', 'Secret post body', 'Secret post comment'];

    /**
     * @return array<string, array{?string}>
     */
    public static function viewerProvider(): array
    {
        return ['anonymous' => [null], 'non-member' => ['bob'], 'member' => ['alice']];
    }

    #[DataProvider('viewerProvider')]
    public function testLinksToRestrictedContentRenderAsPlainUrls(?string $viewer): void
    {
        $owner = $this->getUserByUsername('carol');
        $body = implode("\n\n", $this->restrictedUrls());
        $public = $this->createEntry('Public entry', $this->open, $owner, body: $body);

        if (null !== $viewer) {
            $this->client->loginUser($this->viewer($viewer));
        }
        $this->client->request('GET', "/m/open/t/{$public->getId()}");
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        foreach ($this->restrictedUrls() as $url) {
            self::assertStringContainsString('href="'.$url.'"', $html);
        }
    }

    public function testRestrictedLinkMarkupMatchesForEveryViewer(): void
    {
        $text = implode("\n\n", $this->restrictedUrls());
        $rendered = $this->render($text);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $rendered);
        }
        foreach ($this->restrictedUrls() as $url) {
            self::assertStringContainsString('<a href="'.$url.'"', $rendered);
        }
    }

    public function testRestrictedLinkRendersLikeALinkToNothing(): void
    {
        $id = $this->entry->getId();
        $missing = 999999;
        $restricted = $this->render("https://kbin.test/m/x/t/$id");
        $nothing = $this->render("https://kbin.test/m/x/t/$missing");
        self::assertSame(str_replace((string) $missing, (string) $id, $nothing), $restricted);
    }

    public function testHiddenMagazineMentionRendersLikeAMissingOne(): void
    {
        $vault = $this->getMagazineByName('vault', $this->getUserByUsername('admin', isAdmin: true));
        $vault->access = MagazineAccess::Hidden;
        $vault->title = 'Vault title';
        $this->entityManager->flush();

        foreach (['!%s', '@%s', 'see !%s here'] as $pattern) {
            $hidden = $this->render(\sprintf($pattern, 'vault'));
            $missing = $this->render(\sprintf($pattern, 'nosuchm'));
            self::assertSame(str_replace('nosuchm', 'vault', $missing), $hidden, $pattern);
            self::assertStringNotContainsString('Vault title', $hidden);
            self::assertStringNotContainsString('/m/vault', $hidden);
        }
    }

    public function testPrivateMagazineMentionStaysRich(): void
    {
        $rendered = $this->render('!circle');
        self::assertStringContainsString('/m/circle', $rendered);
    }

    private function render(string $text): string
    {
        return $this->markdownConverter->convertToHtml($text, context: [MarkdownConverter::RENDER_TARGET => RenderTarget::Page]);
    }

    private function viewer(string $name): User
    {
        return 'bob' === $name ? $this->bob : $this->alice;
    }
}
