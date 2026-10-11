<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Security;

use App\Entity\Settings;
use App\Service\SettingsManager;
use App\Tests\WebTestCase;

class RegisterLockedSsoOnlyModeTest extends WebTestCase
{
    /**
     * The setting is MBIN_SSO_ONLY_MODE but its environment variable is
     * SSO_ONLY_MODE, so the lock must follow the setting's own binding.
     */
    private const array ENVIRONMENT = [
        'MBIN_LOCKED_SETTINGS' => 'MBIN_SSO_ONLY_MODE',
        'SSO_ONLY_MODE' => 'true',
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

    public function testRegistrationStaysClosedWhenTheDatabaseSaysOtherwise(): void
    {
        $this->entityManager->persist(new Settings('MBIN_SSO_ONLY_MODE', 'false'));
        $this->entityManager->flush();

        // The first request runs on the container booted before the row existed.
        // The client reboots the kernel before the next one, which reads the row.
        $this->client->request('GET', '/');
        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/login');
        $this->assertTrue(self::getContainer()->get(SettingsManager::class)->get('MBIN_SSO_ONLY_MODE'));
    }
}
