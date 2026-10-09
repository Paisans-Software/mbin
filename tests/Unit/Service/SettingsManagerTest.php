<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Settings;
use App\Repository\InstanceRepository;
use App\Repository\SettingsRepository;
use App\Service\SettingsManager;
use App\Utils\DownvotesMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

class SettingsManagerTest extends WebTestCase
{
    public function testGetMaxImageByteStringDefault(): void
    {
        // Set max images bytes (as if its coming from the .env)
        $setMaxImagesBytes = 1500000;

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $requestStack = $this->createStub(RequestStack::class);
        $instanceRepository = $this->createStub(InstanceRepository::class);
        $logger = $this->createStub(LoggerInterface::class);

        // SUT
        $manager = new SettingsManager(
            entityManager: $entityManager,
            repository: $settingsRepository,
            requestStack: $requestStack,
            instanceRepository: $instanceRepository,
            kbinDomain: 'domain.tld',
            kbinTitle: 'title',
            kbinMetaTitle: 'meta title',
            kbinMetaDescription: 'meta description',
            kbinMetaKeywords: 'meta keywords',
            kbinDefaultLang: 'en',
            kbinContactEmail: 'contact@domain.tld',
            kbinSenderEmail: 'sender@domain.tld',
            mbinDefaultTheme: 'light',
            kbinJsEnabled: true,
            kbinFederationEnabled: true,
            kbinRegistrationsEnabled: true,
            kbinHeaderLogo: true,
            kbinCaptchaEnabled: true,
            kbinFederationPageEnabled: true,
            kbinAdminOnlyOauthClients: true,
            mbinSsoOnlyMode: false,
            mbinMaxImageBytes: $setMaxImagesBytes,
            mbinDownvotesMode: DownvotesMode::Enabled,
            mbinNewUsersNeedApproval: false,
            logger: $logger,
            mbinUseFederationAllowList: false,
            mbinSearchLang: 'english',
        );

        // Assert
        $this->assertSame('1.5 MB', $manager->getMaxImageByteString());
    }

    public function testGetMaxImageByteStringOverridden(): void
    {
        // Set max images bytes (as if its coming from the .env)
        $setMaxImagesBytes = 1572864;

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $requestStack = $this->createStub(RequestStack::class);
        $instanceRepository = $this->createStub(InstanceRepository::class);
        $logger = $this->createStub(LoggerInterface::class);

        // SUT
        $manager = new SettingsManager(
            entityManager: $entityManager,
            repository: $settingsRepository,
            requestStack: $requestStack,
            instanceRepository: $instanceRepository,
            kbinDomain: 'domain.tld',
            kbinTitle: 'title',
            kbinMetaTitle: 'meta title',
            kbinMetaDescription: 'meta description',
            kbinMetaKeywords: 'meta keywords',
            kbinDefaultLang: 'en',
            kbinContactEmail: 'contact@domain.tld',
            kbinSenderEmail: 'sender@domain.tld',
            mbinDefaultTheme: 'light',
            kbinJsEnabled: true,
            kbinFederationEnabled: true,
            kbinRegistrationsEnabled: true,
            kbinHeaderLogo: true,
            kbinCaptchaEnabled: true,
            kbinFederationPageEnabled: true,
            kbinAdminOnlyOauthClients: true,
            mbinSsoOnlyMode: false,
            mbinMaxImageBytes: $setMaxImagesBytes,
            mbinDownvotesMode: DownvotesMode::Enabled,
            mbinNewUsersNeedApproval: false,
            logger: $logger,
            mbinUseFederationAllowList: false,
            mbinSearchLang: 'english',
        );

        // Assert
        $this->assertSame('1.57 MB', $manager->getMaxImageByteString());
    }

    public function testDoesNotReadTheDatabaseUntilASettingIsUsed(): void
    {
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->expects($this->never())->method('findAll');

        $this->createManager($settingsRepository, $this->createStub(EntityManagerInterface::class));
    }

    public function testDatabaseValueWinsOverConfiguredValueAndDefault(): void
    {
        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([]);
        $manager = $this->createManager($settingsRepository, $this->createStub(EntityManagerInterface::class));

        $this->assertSame('title', $manager->get('KBIN_TITLE'));
        $this->assertFalse($manager->get('MBIN_PRIVATE_INSTANCE'));

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([
            new Settings('KBIN_TITLE', 'database title'),
            new Settings('MBIN_PRIVATE_INSTANCE', 'true'),
        ]);
        $manager = $this->createManager($settingsRepository, $this->createStub(EntityManagerInterface::class));

        $this->assertSame('database title', $manager->get('KBIN_TITLE'));
        $this->assertTrue($manager->get('MBIN_PRIVATE_INSTANCE'));
    }

    public function testResetReloadsSettingsChangedInTheDatabase(): void
    {
        $rows = [new Settings('KBIN_TITLE', 'old title')];
        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturnCallback(function () use (&$rows) {
            return $rows;
        });
        $manager = $this->createManager($settingsRepository, $this->createStub(EntityManagerInterface::class));

        $this->assertInstanceOf(ResetInterface::class, $manager);
        $this->assertSame('old title', $manager->get('KBIN_TITLE'));

        $rows = [new Settings('KBIN_TITLE', 'new title')];

        $this->assertSame('old title', $manager->getDto()->KBIN_TITLE);

        $manager->reset();

        $this->assertSame('new title', $manager->get('KBIN_TITLE'));
    }

    public function testResetPicksUpASettingSavedByAnotherInstance(): void
    {
        // Two managers over one store stand in for two long-running workers.
        $rows = [];
        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturnCallback(function () use (&$rows) {
            return $rows;
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (Settings $settings) use (&$rows) {
            $rows[$settings->name] = $settings;
        });

        $savingWorker = $this->createManager($settingsRepository, $entityManager);
        $otherWorker = $this->createManager($settingsRepository, $entityManager);
        $this->assertFalse($otherWorker->get('MBIN_PRIVATE_INSTANCE'));

        $savingWorker->set('MBIN_PRIVATE_INSTANCE', true);

        $this->assertTrue($savingWorker->get('MBIN_PRIVATE_INSTANCE'));
        $this->assertFalse($otherWorker->get('MBIN_PRIVATE_INSTANCE'));

        $otherWorker->reset();

        $this->assertTrue($otherWorker->get('MBIN_PRIVATE_INSTANCE'));
    }

    private function createManager(SettingsRepository $settingsRepository, EntityManagerInterface $entityManager): SettingsManager
    {
        return new SettingsManager(
            entityManager: $entityManager,
            repository: $settingsRepository,
            requestStack: $this->createStub(RequestStack::class),
            instanceRepository: $this->createStub(InstanceRepository::class),
            kbinDomain: 'domain.tld',
            kbinTitle: 'title',
            kbinMetaTitle: 'meta title',
            kbinMetaDescription: 'meta description',
            kbinMetaKeywords: 'meta keywords',
            kbinDefaultLang: 'en',
            kbinContactEmail: 'contact@domain.tld',
            kbinSenderEmail: 'sender@domain.tld',
            mbinDefaultTheme: 'light',
            kbinJsEnabled: true,
            kbinFederationEnabled: true,
            kbinRegistrationsEnabled: true,
            kbinHeaderLogo: true,
            kbinCaptchaEnabled: true,
            kbinFederationPageEnabled: true,
            kbinAdminOnlyOauthClients: true,
            mbinSsoOnlyMode: false,
            mbinMaxImageBytes: 1500000,
            mbinDownvotesMode: DownvotesMode::Enabled,
            mbinNewUsersNeedApproval: false,
            logger: $this->createStub(LoggerInterface::class),
            mbinUseFederationAllowList: false,
            mbinSearchLang: 'english',
        );
    }
}
