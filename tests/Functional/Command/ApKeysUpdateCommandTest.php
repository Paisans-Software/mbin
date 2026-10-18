<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Repository\SiteRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ApKeysUpdateCommandTest extends KernelTestCase
{
    private Command $command;
    private ?SiteRepository $repository;

    public function testKeepsTheInstanceKey(): void
    {
        $site = $this->repository->findAll()[0];
        $privateKey = $site->privateKey;
        $publicKey = $site->publicKey;
        $this->assertNotNull($privateKey);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->getContainer()->get('doctrine')->getManager()->clear();
        $site = $this->repository->findAll()[0];
        $this->assertSame($privateKey, $site->privateKey);
        $this->assertSame($publicKey, $site->publicKey);
    }

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());

        $this->command = $application->find('mbin:ap:keys:update');
        $this->repository = $this->getContainer()->get(SiteRepository::class);
    }
}
