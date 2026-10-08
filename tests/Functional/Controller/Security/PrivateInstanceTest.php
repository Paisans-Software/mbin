<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Security;

use App\Entity\Settings;
use App\Service\SettingsManager;
use App\Tests\WebTestCase;

class PrivateInstanceTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['MBIN_PRIVATE_INSTANCE'], $_SERVER['MBIN_PRIVATE_INSTANCE']);

        parent::tearDown();
    }

    public function testAnonymousCanReadEntriesByDefault(): void
    {
        $this->client->request('GET', '/api/entries');

        self::assertResponseIsSuccessful();
    }

    public function testEnvMakesInstancePrivateWithoutDatabaseSetting(): void
    {
        $this->rebootWithPrivateInstanceEnv('true');

        self::assertTrue($this->settingsManager->get('MBIN_PRIVATE_INSTANCE'));

        $this->client->request('GET', '/api/entries');

        self::assertResponseStatusCodeSame(401);
    }

    public function testDatabaseSettingOverridesEnv(): void
    {
        $this->entityManager->persist(new Settings('MBIN_PRIVATE_INSTANCE', 'false'));
        $this->entityManager->flush();

        $this->rebootWithPrivateInstanceEnv('true');

        self::assertFalse($this->settingsManager->get('MBIN_PRIVATE_INSTANCE'));

        $this->client->request('GET', '/api/entries');

        self::assertResponseIsSuccessful();
    }

    /**
     * Environment variables are resolved when the container builds a service, so the kernel
     * has to be rebooted for a changed value to reach the SettingsManager.
     */
    private function rebootWithPrivateInstanceEnv(string $value): void
    {
        $_ENV['MBIN_PRIVATE_INSTANCE'] = $value;
        $_SERVER['MBIN_PRIVATE_INSTANCE'] = $value;

        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->settingsManager = $this->getService(SettingsManager::class);
    }
}
