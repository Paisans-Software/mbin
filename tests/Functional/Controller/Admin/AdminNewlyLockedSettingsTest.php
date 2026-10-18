<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\WebTestCase;

/**
 * The seven Instance settings that could only be set in the admin panel
 * until they gained an environment variable, each locked.
 */
class AdminNewlyLockedSettingsTest extends WebTestCase
{
    private const array SETTINGS = [
        'KBIN_MERCURE_ENABLED',
        'KBIN_FEDERATED_SEARCH_ONLY_LOGGEDIN',
        'MBIN_SIDEBAR_SECTIONS_RANDOM_LOCAL_ONLY',
        'MBIN_SIDEBAR_SECTIONS_USERS_LOCAL_ONLY',
        'MBIN_SSO_REGISTRATIONS_ENABLED',
        'MBIN_RESTRICT_MAGAZINE_CREATION',
        'MBIN_SSO_SHOW_FIRST',
    ];

    /** @var array<string, array{0: ?string, 1: ?string}> */
    private array $previousEnvironment = [];

    public function setUp(): void
    {
        $environment = ['MBIN_LOCKED_SETTINGS' => implode(',', self::SETTINGS)];
        foreach (self::SETTINGS as $name) {
            $environment[$name] = 'true';
        }

        foreach ($environment as $name => $value) {
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

    public function testEachRendersDisabledWithANote(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        foreach (self::SETTINGS as $name) {
            self::assertSelectorExists('#settings_'.$name.'[disabled]');
        }
        $this->assertCount(\count(self::SETTINGS), $crawler->filter('#content .help-text')->reduce(
            fn ($note) => str_contains($note->text(), 'cannot be changed here'),
        ));
    }

    public function testCraftedPostCannotChangeOne(): void
    {
        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/settings');
        $values = $crawler->filter('#content form[name=settings]')->form()->getPhpValues();
        // Leaving a checkbox out of a crafted request would untick it.
        foreach (self::SETTINGS as $name) {
            unset($values['settings'][$name]);
        }

        $this->client->request('POST', '/admin/settings', $values);

        self::assertResponseRedirects();
        foreach (self::SETTINGS as $name) {
            $this->assertNull($this->findSettingValue($name), $name);
        }
    }

    private function findSettingValue(string $name): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);

        return false === $value ? null : $value;
    }
}
