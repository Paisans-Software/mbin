<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Instance\Admin;

use App\Tests\WebTestCase;

class InstanceSettingsUpdateLockedApiTest extends WebTestCase
{
    /**
     * The environment each test boots with, as a deployment that locks these
     * settings would set it.
     */
    private const array ENVIRONMENT = [
        'MBIN_LOCKED_SETTINGS' => 'KBIN_TITLE,KBIN_REGISTRATIONS_ENABLED',
        'KBIN_TITLE' => 'Environment title',
        'KBIN_REGISTRATIONS_ENABLED' => 'true',
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

    public function testApiRefusesAChangeToALockedSetting(): void
    {
        $settings = $this->settingsManager->getDto()->jsonSerialize();
        $settings['KBIN_TITLE'] = 'Changed title';
        $settings['KBIN_META_TITLE'] = 'Updated meta title';

        $this->client->jsonRequest('PUT', '/api/instance/settings', $settings, server: ['HTTP_AUTHORIZATION' => $this->getAdminToken()]);

        self::assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('KBIN_TITLE is locked by MBIN_LOCKED_SETTINGS', (string) $this->client->getResponse()->getContent());
        $this->assertNull($this->findSettingValue('KBIN_TITLE'));
        $this->assertNull($this->findSettingValue('KBIN_META_TITLE'));
    }

    public function testApiAcceptsUnchangedLockedValuesInAFullSet(): void
    {
        $settings = $this->settingsManager->getDto()->jsonSerialize();
        $this->assertSame('Environment title', $settings['KBIN_TITLE']);
        $settings['KBIN_META_TITLE'] = 'Updated meta title';

        $this->client->jsonRequest('PUT', '/api/instance/settings', $settings, server: ['HTTP_AUTHORIZATION' => $this->getAdminToken()]);

        self::assertResponseIsSuccessful();
        $jsonData = self::getJsonResponse($this->client);
        $this->assertSame('Environment title', $jsonData['KBIN_TITLE']);
        $this->assertSame('Updated meta title', $jsonData['KBIN_META_TITLE']);
        $this->assertNull($this->findSettingValue('KBIN_TITLE'));
        $this->assertNull($this->findSettingValue('KBIN_REGISTRATIONS_ENABLED'));
    }

    private function getAdminToken(): string
    {
        self::createOAuth2AuthCodeClient();
        $this->client->loginUser($this->getUserByUsername('JohnDoe', isAdmin: true));

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read admin:instance:settings:edit');

        return $codes['token_type'].' '.$codes['access_token'];
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
