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
use Symfony\Component\HttpKernel\KernelInterface;

class SettingsManagerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset static DTO to avoid leaking settings between tests
        SettingsManager::resetDto();
    }

    public function testGetMaxImageByteStringDefault(): void
    {
        SettingsManager::resetDto();

        // Set max images bytes (as if its coming from the .env)
        $setMaxImagesBytes = 1500000;

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $requestStack = $this->createStub(RequestStack::class);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        $instanceRepository = $this->createStub(InstanceRepository::class);
        $logger = $this->createStub(LoggerInterface::class);

        // SUT
        $manager = new SettingsManager(
            entityManager: $entityManager,
            repository: $settingsRepository,
            requestStack: $requestStack,
            kernel: $kernel,
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
            mbinPrivateInstance: false,
        );

        // Assert
        $this->assertSame('1.5 MB', $manager->getMaxImageByteString());
    }

    public function testGetMaxImageByteStringOverridden(): void
    {
        SettingsManager::resetDto();

        // Set max images bytes (as if its coming from the .env)
        $setMaxImagesBytes = 1572864;

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $requestStack = $this->createStub(RequestStack::class);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        $instanceRepository = $this->createStub(InstanceRepository::class);
        $logger = $this->createStub(LoggerInterface::class);

        // SUT
        $manager = new SettingsManager(
            entityManager: $entityManager,
            repository: $settingsRepository,
            requestStack: $requestStack,
            kernel: $kernel,
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
            mbinPrivateInstance: false,
        );

        // Assert
        $this->assertSame('1.57 MB', $manager->getMaxImageByteString());
    }

    public function testPrivateInstanceFallsBackToEnv(): void
    {
        $manager = $this->createManagerForPrivateInstance(settings: [], mbinPrivateInstance: true);

        $this->assertTrue($manager->get('MBIN_PRIVATE_INSTANCE'));
    }

    public function testPrivateInstanceDefaultsToFalse(): void
    {
        $manager = $this->createManagerForPrivateInstance(settings: [], mbinPrivateInstance: false);

        $this->assertFalse($manager->get('MBIN_PRIVATE_INSTANCE'));
    }

    public function testPrivateInstanceDatabaseValueOverridesEnv(): void
    {
        $manager = $this->createManagerForPrivateInstance(
            settings: [new Settings('MBIN_PRIVATE_INSTANCE', 'false')],
            mbinPrivateInstance: true,
        );
        $this->assertFalse($manager->get('MBIN_PRIVATE_INSTANCE'));

        SettingsManager::resetDto();

        $manager = $this->createManagerForPrivateInstance(
            settings: [new Settings('MBIN_PRIVATE_INSTANCE', 'true')],
            mbinPrivateInstance: false,
        );
        $this->assertTrue($manager->get('MBIN_PRIVATE_INSTANCE'));
    }

    /**
     * @param Settings[] $settings
     */
    private function createManagerForPrivateInstance(array $settings, bool $mbinPrivateInstance): SettingsManager
    {
        SettingsManager::resetDto();

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn($settings);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');

        return new SettingsManager(
            entityManager: $this->createStub(EntityManagerInterface::class),
            repository: $settingsRepository,
            requestStack: $this->createStub(RequestStack::class),
            kernel: $kernel,
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
            mbinMaxImageBytes: 6000000,
            mbinDownvotesMode: DownvotesMode::Enabled,
            mbinNewUsersNeedApproval: false,
            logger: $this->createStub(LoggerInterface::class),
            mbinUseFederationAllowList: false,
            mbinSearchLang: 'english',
            mbinPrivateInstance: $mbinPrivateInstance,
        );
    }
}
