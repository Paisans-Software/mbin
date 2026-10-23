<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine;

use App\Enum\MagazineAccess;
use App\Tests\WebTestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineCreateAccessTest extends WebTestCase
{
    public function testAdminCreatesPrivateMagazine(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/newMagazine');
        self::assertSame(1, $crawler->filter('select[name="magazine[access]"]')->count());

        $this->client->submit(
            $crawler->filter('form[name=magazine]')->selectButton('Create new magazine')->form([
                'magazine[name]' => 'secretclub',
                'magazine[title]' => 'Secret club',
                'magazine[access]' => 'private',
            ])
        );

        $this->assertResponseRedirects('/m/secretclub');
        $magazine = $this->magazineRepository->findOneByName('secretclub');
        self::assertSame(MagazineAccess::Private, $magazine->access);
        self::assertTrue($magazine->isRestricted());
    }

    public function testNonAdminFormHasNoAccessField(): void
    {
        $users = [
            'plain' => $this->getUserByUsername('JohnDoe'),
            'globalmod' => $this->getUserByUsername('globalmod', isModerator: true),
        ];

        foreach ($users as $label => $user) {
            $this->client->loginUser($user);
            $crawler = $this->client->request('GET', '/newMagazine');
            self::assertSame(0, $crawler->filter('select[name="magazine[access]"]')->count(), $label);

            $name = 'open'.$label;
            $this->client->submit(
                $crawler->filter('form[name=magazine]')->selectButton('Create new magazine')->form([
                    'magazine[name]' => $name,
                    'magazine[title]' => 'Open magazine',
                ])
            );

            $this->assertResponseRedirects('/m/'.$name);
            self::assertSame(MagazineAccess::Public, $this->magazineRepository->findOneByName($name)->access);
        }
    }

    public function testAccessCannotBeChangedOnEdit(): void
    {
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/newMagazine');
        $this->client->submit(
            $crawler->filter('form[name=magazine]')->selectButton('Create new magazine')->form([
                'magazine[name]' => 'secretclub',
                'magazine[title]' => 'Secret club',
                'magazine[access]' => 'private',
            ])
        );

        $crawler = $this->client->request('GET', '/m/secretclub/panel/general');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[name="magazine[access]"]')->count());

        $form = $crawler->filter('#main form[name=magazine]')->selectButton('Done')->form();
        $values = $form->getPhpValues();
        $values['magazine']['access'] = 'public';
        $this->client->request('POST', '/m/secretclub/panel/general', $values);

        $this->entityManager->clear();
        self::assertSame(MagazineAccess::Private, $this->magazineRepository->findOneByName('secretclub')->access);
    }
}
