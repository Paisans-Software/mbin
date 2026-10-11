<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Settings;
use App\Repository\InstanceRepository;
use App\Repository\SettingsRepository;
use App\Service\SettingsManager;
use App\Utils\DownvotesMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;

class SettingsManagerLockedSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        SettingsManager::resetDto();
    }

    public function testLockedSettingReadsTheEnvironmentOverADatabaseRow(): void
    {
        $manager = $this->createManager(
            lockedSettings: 'KBIN_TITLE,MBIN_SSO_ONLY_MODE',
            rows: [
                new Settings('KBIN_TITLE', 'database title'),
                new Settings('MBIN_SSO_ONLY_MODE', 'true'),
            ],
        );

        $this->assertSame('env title', $manager->get('KBIN_TITLE'));
        $this->assertFalse($manager->get('MBIN_SSO_ONLY_MODE'));
    }

    public function testMailAndAccessSettingsCanBeLockedOverTheirDatabaseRows(): void
    {
        $manager = $this->createManager(
            lockedSettings: 'KBIN_SENDER_EMAIL,KBIN_CONTACT_EMAIL,MBIN_SSO_ONLY_MODE,MBIN_USE_FEDERATION_ALLOW_LIST',
            rows: [
                new Settings('KBIN_SENDER_EMAIL', 'admin-sender@domain.tld'),
                new Settings('KBIN_CONTACT_EMAIL', 'admin-contact@domain.tld'),
                new Settings('MBIN_SSO_ONLY_MODE', 'true'),
                new Settings('MBIN_USE_FEDERATION_ALLOW_LIST', 'true'),
            ],
        );

        $this->assertSame('sender@domain.tld', $manager->get('KBIN_SENDER_EMAIL'));
        $this->assertSame('contact@domain.tld', $manager->get('KBIN_CONTACT_EMAIL'));
        $this->assertFalse($manager->get('MBIN_SSO_ONLY_MODE'));
        $this->assertFalse($manager->getUseAllowList());
    }

    public function testLockedSettingReadsItsOwnEnvironmentBindingWhateverTheVariableIsCalled(): void
    {
        // MBIN_SSO_ONLY_MODE is set by SSO_ONLY_MODE, which the container binds to $mbinSsoOnlyMode.
        $manager = $this->createManager(
            lockedSettings: 'MBIN_SSO_ONLY_MODE',
            rows: [new Settings('MBIN_SSO_ONLY_MODE', 'false')],
            mbinSsoOnlyMode: true,
        );

        $this->assertTrue($manager->get('MBIN_SSO_ONLY_MODE'));
    }

    public function testUnlockedSettingStillReadsItsDatabaseRow(): void
    {
        $manager = $this->createManager(
            lockedSettings: 'KBIN_TITLE',
            rows: [
                new Settings('KBIN_TITLE', 'database title'),
                new Settings('KBIN_META_TITLE', 'database meta title'),
            ],
        );

        $this->assertFalse($manager->isLocked('KBIN_META_TITLE'));
        $this->assertSame('database meta title', $manager->get('KBIN_META_TITLE'));
    }

    public function testNothingIsLockedByDefault(): void
    {
        $manager = $this->createManager(lockedSettings: '', rows: [new Settings('KBIN_TITLE', 'database title')]);

        $this->assertSame([], $manager->getLockedSettings());
        $this->assertFalse($manager->isLocked('KBIN_TITLE'));
        $this->assertSame('database title', $manager->get('KBIN_TITLE'));
    }

    public function testListToleratesWhitespaceAndEmptyEntries(): void
    {
        $manager = $this->createManager(lockedSettings: " KBIN_SENDER_EMAIL ,\tKBIN_CONTACT_EMAIL,,\n", rows: []);

        $this->assertSame(['KBIN_SENDER_EMAIL', 'KBIN_CONTACT_EMAIL'], $manager->getLockedSettings());
    }

    public function testEveryLockableSettingCanBeLocked(): void
    {
        $manager = $this->createManager(lockedSettings: implode(',', SettingsManager::LOCKABLE_SETTINGS), rows: []);

        $this->assertSame(SettingsManager::LOCKABLE_SETTINGS, $manager->getLockedSettings());
    }

    public function testSaveNeverWritesALockedSetting(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (Settings $settings) use (&$persisted) {
            $persisted[$settings->name] = $settings->value;
        });
        $manager = $this->createManager(lockedSettings: 'KBIN_TITLE', rows: [], entityManager: $entityManager);

        $dto = $manager->getDto();
        $dto->KBIN_TITLE = 'admin title';
        $dto->KBIN_META_TITLE = 'admin meta title';
        $manager->save($dto);

        $this->assertArrayNotHasKey('KBIN_TITLE', $persisted);
        $this->assertSame('admin meta title', $persisted['KBIN_META_TITLE']);
    }

    public function testSetRefusesALockedSettingAndWritesNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $manager = $this->createManager(lockedSettings: 'KBIN_TITLE', rows: [], entityManager: $entityManager);

        try {
            $manager->set('KBIN_TITLE', 'admin title');
            $this->fail('set() accepted a locked setting');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('KBIN_TITLE', $e->getMessage());
        }

        $this->assertSame('env title', $manager->get('KBIN_TITLE'));
    }

    public function testSetStillWritesAnUnlockedSetting(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (Settings $settings) use (&$persisted) {
            $persisted[$settings->name] = $settings->value;
        });
        $manager = $this->createManager(lockedSettings: 'KBIN_TITLE', rows: [], entityManager: $entityManager);

        $manager->set('KBIN_META_TITLE', 'admin meta title');

        $this->assertSame('admin meta title', $persisted['KBIN_META_TITLE']);
        $this->assertArrayNotHasKey('KBIN_TITLE', $persisted);
    }

    public function testUnknownNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MBIN_LOCKED_SETTINGS names "KBIN_TITEL", which is not a setting.');

        $this->createManager(lockedSettings: 'KBIN_DOMAIN,KBIN_TITEL', rows: []);
    }

    public function testNameIsMatchedExactly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"kbin_title"');

        $this->createManager(lockedSettings: 'kbin_title', rows: []);
    }

    public function testSettingWithoutAnEnvironmentFallbackIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MBIN_LOCKED_SETTINGS names "KBIN_MERCURE_ENABLED", which has no environment variable to fall back on');

        $this->createManager(lockedSettings: 'KBIN_MERCURE_ENABLED', rows: []);
    }

    /**
     * @param Settings[] $rows
     */
    private function createManager(string $lockedSettings, array $rows, ?EntityManagerInterface $entityManager = null, bool $mbinSsoOnlyMode = false): SettingsManager
    {
        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('findAll')->willReturn($rows);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('test');

        return new SettingsManager(
            entityManager: $entityManager ?? $this->createStub(EntityManagerInterface::class),
            repository: $settingsRepository,
            requestStack: $this->createStub(RequestStack::class),
            kernel: $kernel,
            instanceRepository: $this->createStub(InstanceRepository::class),
            kbinDomain: 'domain.tld',
            kbinTitle: 'env title',
            kbinMetaTitle: 'env meta title',
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
            mbinSsoOnlyMode: $mbinSsoOnlyMode,
            mbinMaxImageBytes: 1500000,
            mbinDownvotesMode: DownvotesMode::Enabled,
            mbinNewUsersNeedApproval: false,
            logger: $this->createStub(LoggerInterface::class),
            mbinUseFederationAllowList: false,
            mbinSearchLang: 'english',
            mbinLockedSettings: $lockedSettings,
        );
    }
}
