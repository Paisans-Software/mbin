<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Settings;
use App\Tests\WebTestCase;

class AdminLockedSettingsTest extends WebTestCase
{
    /**
     * The environment each test boots with, as a deployment that locks these
     * settings would set it.
     */
    private const array ENVIRONMENT = [
        'MBIN_LOCKED_SETTINGS' => 'KBIN_TITLE, KBIN_REGISTRATIONS_ENABLED,MBIN_USE_FEDERATION_ALLOW_LIST,MBIN_PRIVATE_INSTANCE,MBIN_AUTHORIZED_FETCH',
        'KBIN_TITLE' => 'Environment title',
        'KBIN_REGISTRATIONS_ENABLED' => 'true',
        'MBIN_USE_FEDERATION_ALLOW_LIST' => 'false',
        'MBIN_PRIVATE_INSTANCE' => 'false',
        'MBIN_AUTHORIZED_FETCH' => 'false',
    ];

    /** @var array<string, array{0: ?string, 1: ?string}> */
    private array $previousEnvironment = [];

    public function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->previousEnvironment[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->previousEnvironment as $name => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }
    }

    public function testLockedFieldsRenderDisabledWithANote(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#settings_KBIN_TITLE[disabled]');
        self::assertSelectorExists('#settings_KBIN_REGISTRATIONS_ENABLED[disabled]');
        self::assertSelectorExists('#settings_MBIN_PRIVATE_INSTANCE[disabled]');
        self::assertSelectorNotExists('#settings_KBIN_META_TITLE[disabled]');
        self::assertSelectorNotExists('#settings_KBIN_HEADER_LOGO[disabled]');
        $this->assertCount(3, $crawler->filter('#content .help-text')->reduce(
            fn ($note) => str_contains($note->text(), 'cannot be changed here'),
        ));
    }

    public function testLockedSettingShowsTheEnvironmentValueOverADatabaseRow(): void
    {
        $this->entityManager->persist(new Settings('KBIN_TITLE', 'Database title'));
        $this->entityManager->flush();
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        // The first request runs on the container booted before the row existed.
        // The client reboots the kernel before the next one, which reads the row.
        $this->client->request('GET', '/admin/settings');
        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        $this->assertSame('Environment title', $crawler->filter('#settings_KBIN_TITLE')->attr('value'));
    }

    public function testCraftedPostCannotChangeALockedSetting(): void
    {
        $this->entityManager->persist(new Settings('KBIN_TITLE', 'Database title'));
        $this->entityManager->flush();
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/settings');
        $values = $crawler->filter('#content form[name=settings]')->form()->getPhpValues();
        // A browser leaves a disabled field out. A crafted request puts the title
        // back in, and leaves the registrations checkbox out, which would untick it.
        $values['settings']['KBIN_TITLE'] = 'Crafted title';
        unset($values['settings']['KBIN_REGISTRATIONS_ENABLED']);
        $values['settings']['MBIN_PRIVATE_INSTANCE'] = '1';
        $values['settings']['KBIN_META_TITLE'] = 'Admin meta title';

        $this->client->request('POST', '/admin/settings', $values);

        self::assertResponseRedirects();
        $this->assertSame('Database title', $this->findSettingValue('KBIN_TITLE'));
        $this->assertNull($this->findSettingValue('KBIN_REGISTRATIONS_ENABLED'));
        $this->assertNull($this->findSettingValue('MBIN_PRIVATE_INSTANCE'));
        $this->assertSame('Admin meta title', $this->findSettingValue('KBIN_META_TITLE'));
    }

    public function testFederationFormCannotChangeALockedSetting(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/federation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#federation_settings_federationUsesAllowList[disabled]');
        self::assertSelectorExists('#federation_settings_authorizedFetch[disabled]');
        self::assertSelectorNotExists('#federation_settings_federationEnabled[disabled]');
        self::assertSelectorTextContains('#content', 'cannot be changed here');

        $values = $crawler->filter('#content form[name=federation_settings]')->form()->getPhpValues();
        $values['federation_settings']['federationUsesAllowList'] = '1';
        $values['federation_settings']['authorizedFetch'] = '1';

        $this->client->request('POST', '/admin/federation', $values);

        self::assertResponseRedirects();
        $this->assertNull($this->findSettingValue('MBIN_USE_FEDERATION_ALLOW_LIST'));
        $this->assertNull($this->findSettingValue('MBIN_AUTHORIZED_FETCH'));
        $this->assertSame('true', $this->findSettingValue('KBIN_FEDERATION_ENABLED'));
    }

    /**
     * Read past Doctrine's caches, straight from the table.
     */
    private function findSettingValue(string $name): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);

        return false === $value ? null : $value;
    }
}
