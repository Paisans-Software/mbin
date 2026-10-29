<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\DTO\UserDto;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AgentCommandTest extends KernelTestCase
{
    private Command $command;
    private UserRepository $repository;

    public function testGrantsAndRevokesAgentRole(): void
    {
        $dto = (new UserDto())->create('actor', 'contact@example.com');
        $dto->plainPassword = 'secret';

        $this->getContainer()->get(UserManager::class)
            ->create($dto, false);

        $this->assertFalse($this->repository->findOneByUsername('actor')->isAgent());

        $tester = new CommandTester($this->command);
        $tester->execute(['username' => 'actor']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Agent privileges have been granted.', $tester->getDisplay());
        $this->assertTrue($this->repository->findOneByUsername('actor')->isAgent());

        $tester->execute(['username' => 'actor', '--remove' => true]);

        $this->assertStringContainsString('Agent privileges have been revoked.', $tester->getDisplay());
        $this->assertFalse($this->repository->findOneByUsername('actor')->isAgent());
    }

    public function testUnknownUserFails(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute(['username' => 'nobody']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());

        $this->command = $application->find('mbin:user:agent');
        $this->repository = $this->getContainer()->get(UserRepository::class);
    }
}
