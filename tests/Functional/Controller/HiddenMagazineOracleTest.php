<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineMemberManager;
use App\Tests\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Requests naming a hidden magazine must answer exactly as they do for a magazine
 * that does not exist, unless the requester may see it.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class HiddenMagazineOracleTest extends WebTestCase
{
    private Magazine $vault;
    private User $alice;
    private User $bob;

    public function setUp(): void
    {
        parent::setUp();
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->vault = $this->getMagazineByName('vault', $admin);
        $this->vault->access = MagazineAccess::Hidden;
        $this->vault->customCss = 'body { --vault-marker: 1; }';
        $this->getMagazineByName('acme', $admin);
        $this->entityManager->flush();

        $this->alice = $this->getUserByUsername('alice');
        $this->bob = $this->getUserByUsername('bob');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->vault, $this->alice, $admin);
    }

    public function testCustomStyleOfHiddenMagazineMatchesMissing(): void
    {
        foreach ([null, $this->bob] as $viewer) {
            if (null !== $viewer) {
                $this->client->loginUser($viewer);
            }
            $this->client->request('GET', '/custom-style?magazine=no-such-mag');
            $missing = $this->client->getResponse();
            $missingStatus = $missing->getStatusCode();
            $missingBody = (string) $missing->getContent();
            $missingEtag = $missing->headers->get('ETag');

            $this->client->request('GET', '/custom-style?magazine=vault');
            $hidden = $this->client->getResponse();
            self::assertSame($missingStatus, $hidden->getStatusCode());
            self::assertSame($missingBody, (string) $hidden->getContent());
            self::assertSame($missingEtag, $hidden->headers->get('ETag'));
            self::assertStringNotContainsString('vault-marker', (string) $hidden->getContent());
        }
    }

    public function testCustomStyleOfHiddenMagazineServedToMember(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/custom-style?magazine=vault');
        self::assertStringContainsString('vault-marker', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array{int, string}
     */
    private function submitWithMagazine(string $page, string $form, string $button, array $fields, int $magazineId): array
    {
        $crawler = $this->client->request('GET', $page);
        $f = $crawler->filter("form[name=$form]")->selectButton($button)->form($fields);
        $values = $f->getPhpValues();
        $values[$form]['magazine'] = (string) $magazineId;
        $this->client->request($f->getMethod(), $f->getUri(), $values);

        $response = $this->client->getResponse();

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    public function testEntryCreateWithHiddenMagazineIdMatchesMissingId(): void
    {
        $this->client->loginUser($this->bob);
        $fields = ['entry[title]' => 'Oracle probe', 'entry[body]' => 'probe'];

        [$missingStatus, $missingBody] = $this->submitWithMagazine('/m/acme/new_entry', 'entry', 'Add new thread', $fields, 999999);
        [$hiddenStatus, $hiddenBody] = $this->submitWithMagazine('/m/acme/new_entry', 'entry', 'Add new thread', $fields, $this->vault->getId());

        self::assertNotSame(403, $missingStatus);
        self::assertSame($missingStatus, $hiddenStatus);
        self::assertSame($this->formErrors($missingBody), $this->formErrors($hiddenBody));
        self::assertNotEmpty($this->formErrors($hiddenBody));
    }

    public function testMemberCanCreateEntryInHiddenMagazineById(): void
    {
        $this->client->loginUser($this->alice);
        $fields = ['entry[title]' => 'Member entry', 'entry[body]' => 'inside'];

        [$status] = $this->submitWithMagazine('/m/acme/new_entry', 'entry', 'Add new thread', $fields, $this->vault->getId());

        self::assertSame(302, $status);
        self::assertResponseRedirects('/m/vault/default/newest');
    }

    public function testPostCreateWithHiddenMagazineIdMatchesMissingId(): void
    {
        $this->client->loginUser($this->bob);
        $fields = ['post[body]' => 'probe'];

        [$missingStatus, $missingBody] = $this->submitWithMagazine('/m/acme/microblog', 'post', 'Add post', $fields, 999999);
        [$hiddenStatus, $hiddenBody] = $this->submitWithMagazine('/m/acme/microblog', 'post', 'Add post', $fields, $this->vault->getId());

        self::assertNotSame(403, $missingStatus);
        self::assertSame($missingStatus, $hiddenStatus);
        self::assertSame($this->formErrors($missingBody, 'post'), $this->formErrors($hiddenBody, 'post'));
        self::assertNotEmpty($this->formErrors($hiddenBody, 'post'));
    }

    public function testMemberCanCreatePostInHiddenMagazineById(): void
    {
        $this->client->loginUser($this->alice);
        [$status] = $this->submitWithMagazine('/m/acme/microblog', 'post', 'Add post', ['post[body]' => 'inside'], $this->vault->getId());

        self::assertSame(302, $status);
        self::assertResponseRedirects('/m/vault/microblog/newest');
    }

    /**
     * @return list<string>
     */
    private function formErrors(string $html, string $form = 'entry'): array
    {
        $formHtml = (new Crawler($html))->filter("form[name=$form]")->outerHtml();
        preg_match_all('#<ul><li[^>]*>(.*?)</li></ul>#s', $formHtml, $m);

        return $m[1];
    }
}
