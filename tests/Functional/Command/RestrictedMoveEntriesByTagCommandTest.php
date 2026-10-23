<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Entry;
use App\Tests\Functional\Controller\RestrictedContentFixtureTrait;
use App\Tests\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedMoveEntriesByTagCommandTest extends WebTestCase
{
    use RestrictedContentFixtureTrait;

    public function testRestrictedEntriesAreSkippedAndPublicOnesMove(): void
    {
        $this->createRestrictedFixture();
        $admin = $this->getUserByUsername('admin');
        $target = $this->getMagazineByName('target', $admin);
        $secret = $this->createEntry('Secret tagged', $this->circle, $admin, 'https://secret.example/t', body: 'body #movetag');
        $public = $this->createEntry('Public tagged', $this->open, $admin, 'https://public.example/t', body: 'body #movetag');
        // the command reads a tags property Entry no longer declares (an upstream bug, owed
        // separately); without it the command fails on the public entry
        // @phpstan-ignore property.notFound, property.notFound
        $secret->tags = $public->tags = ['movetag'];
        $this->entityManager->flush();
        [$secretId, $publicId] = [$secret->getId(), $public->getId()];

        $tester = new CommandTester((new Application(self::$kernel))->find('mbin:entries:move'));
        $tester->execute(['magazine' => 'target', 'tag' => 'movetag']);

        $this->entityManager->clear();
        self::assertSame('circle', $this->entityManager->find(Entry::class, $secretId)->magazine->name);
        self::assertSame('target', $this->entityManager->find(Entry::class, $publicId)->magazine->name);
        self::assertStringContainsString('Entry '.$secretId.' skipped', $tester->getDisplay());
    }
}
