<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Settings;
use App\Tests\WebTestCase;
use Doctrine\DBAL\ParameterType;

class SettingsManagerResetTest extends WebTestCase
{
    public function testServicesResetterReloadsSettingsChangedInTheDatabase(): void
    {
        $title = $this->settingsManager->get('KBIN_TITLE');

        // Another worker saves a new title: the row changes, this instance does not.
        $row = $this->settingsRepository->findAllIndexedByName()['KBIN_TITLE'] ?? new Settings('KBIN_TITLE', '');
        $row->value = 'Saved by another worker';
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        self::assertSame($title, $this->settingsManager->get('KBIN_TITLE'));

        // What the kernel does after each request, and the messenger worker after each message.
        self::getContainer()->get('services_resetter')->reset();

        self::assertSame('Saved by another worker', $this->settingsManager->get('KBIN_TITLE'));
    }

    public function testSaveIgnoresRowsTheSecondLevelCacheNamesButTheDatabaseDoesNotHold(): void
    {
        // Save every setting, then read them back, so that Doctrine's second-level cache
        // holds both the rows and the result of a query listing them.
        $this->settingsManager->set('KBIN_TITLE', 'first');
        self::getContainer()->get('services_resetter')->reset();
        $this->settingsManager->get('KBIN_TITLE');

        // Remove the rows behind the cache's back, and rewind the sequence so that the next
        // insert reuses their ids. This is what a cache shared by several databases looks
        // like (parallel test workers), and what a rolled-back transaction leaves behind.
        $connection = $this->entityManager->getConnection();
        $sequence = $connection->fetchAssociative('SELECT last_value, is_called FROM settings_id_seq');
        $lowestId = (int) $connection->fetchOne('SELECT MIN(id) FROM settings');
        $connection->executeStatement('DELETE FROM settings');
        $connection->executeStatement("SELECT setval('settings_id_seq', $lowestId, false)");

        try {
            self::getContainer()->get('services_resetter')->reset();
            $this->settingsManager->set('KBIN_TITLE', 'second');
        } finally {
            // A sequence is not rolled back with the test's transaction, so put it back.
            $connection->executeStatement(
                'SELECT setval(\'settings_id_seq\', ?, ?)',
                [$sequence['last_value'], $sequence['is_called']],
                [ParameterType::INTEGER, ParameterType::BOOLEAN],
            );
        }

        self::getContainer()->get('services_resetter')->reset();

        self::assertSame('second', $this->settingsManager->get('KBIN_TITLE'));
        self::assertSame(
            'second',
            $connection->fetchOne('SELECT value FROM settings WHERE name = ?', ['KBIN_TITLE']),
        );
    }
}
