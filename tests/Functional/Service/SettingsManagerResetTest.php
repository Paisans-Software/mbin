<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Settings;
use App\Tests\WebTestCase;

class SettingsManagerResetTest extends WebTestCase
{
    public function testServicesResetterReloadsSettingsChangedInTheDatabase(): void
    {
        $title = $this->settingsManager->get('KBIN_TITLE');

        // Another worker saves a new title: the row changes, this instance does not.
        $row = $this->settingsRepository->findOneBy(['name' => 'KBIN_TITLE']) ?? new Settings('KBIN_TITLE', '');
        $row->value = 'Saved by another worker';
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        self::assertSame($title, $this->settingsManager->get('KBIN_TITLE'));

        // What the kernel does after each request, and the messenger worker after each message.
        self::getContainer()->get('services_resetter')->reset();

        self::assertSame('Saved by another worker', $this->settingsManager->get('KBIN_TITLE'));
    }
}
