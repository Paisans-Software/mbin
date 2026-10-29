<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\DTO\UserDto;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AgentSyncCommandTest extends KernelTestCase
{
    private const DECLARED = [
        '--client-id' => 'engagement-agent',
        '--client-secret' => 'first-secret-0123456789',
        // Scopes this base carries. The agent scopes come from the agent API's own branch.
        '--scopes' => 'read write magazine:subscribe',
        '--username' => 'community-bot',
        '--contact-email' => 'bot@example.com',
    ];

    private CommandTester $tester;
    private UserRepository $users;
    private ClientManagerInterface $clients;

    public function testCreatesClientAndBot(): void
    {
        $this->tester->execute(self::DECLARED);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $client = $this->client();
        $this->assertTrue($client->isConfidential());
        $this->assertSame('first-secret-0123456789', $client->getSecret());
        $this->assertSame(['client_credentials'], array_map('strval', $client->getGrants()));
        $this->assertSame(['read', 'write', 'magazine:subscribe'], array_map('strval', $client->getScopes()));
        $this->assertSame('bot@example.com', $client->getContactEmail());

        $bot = $this->users->findOneByUsername('community-bot');
        $this->assertNotNull($bot);
        $this->assertSame($bot->getId(), $client->getUser()->getId());
        $this->assertSame(User::USER_TYPE_SERVICE, $bot->type);
        $this->assertTrue($bot->isVerified);
        $this->assertContains('ROLE_AGENT', $bot->getRoles());
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->tester->execute(self::DECLARED);
        $botId = $this->users->findOneByUsername('community-bot')->getId();

        $this->tester->execute(self::DECLARED);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString('already matches', $this->tester->getDisplay());
        $this->assertSame($botId, $this->client()->getUser()->getId());
    }

    public function testRotatesSecret(): void
    {
        $this->tester->execute(self::DECLARED);

        $this->tester->execute(['--client-secret' => 'second-secret-0123456789'] + self::DECLARED);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('second-secret-0123456789', $this->client()->getSecret());
    }

    public function testUpdatesScopes(): void
    {
        $this->tester->execute(self::DECLARED);

        $this->tester->execute(['--scopes' => 'read'] + self::DECLARED);

        $this->assertSame(['read'], array_map('strval', $this->client()->getScopes()));
    }

    public function testRestoresRevokedAgentRole(): void
    {
        $this->tester->execute(self::DECLARED);
        $bot = $this->users->findOneByUsername('community-bot');
        $bot->roles = [];
        $this->getContainer()->get('doctrine')->getManager()->flush();

        $this->tester->execute(self::DECLARED);

        $this->assertContains('ROLE_AGENT', $this->users->findOneByUsername('community-bot')->getRoles());
    }

    public function testEmptyIdIsNoop(): void
    {
        $this->tester->execute(['--client-id' => ''] + self::DECLARED);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertNull($this->users->findOneByUsername('community-bot'));
    }

    public function testUnknownScopeFails(): void
    {
        $this->tester->execute(['--scopes' => 'read no-such-scope'] + self::DECLARED);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertNull($this->clients->find('engagement-agent'));
    }

    public function testUsernameTakenByNonBotFails(): void
    {
        $dto = (new UserDto())->create('community-bot', 'person@example.com');
        $dto->plainPassword = 'secret';
        $this->getContainer()->get(UserManager::class)->create($dto, false);

        $this->tester->execute(self::DECLARED);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertNull($this->clients->find('engagement-agent'));
        $this->assertNotContains('ROLE_AGENT', $this->users->findOneByUsername('community-bot')->getRoles());
    }

    private function client(): Client
    {
        $this->getContainer()->get('doctrine')->getManager()->clear();
        $client = $this->clients->find('engagement-agent');
        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());

        $this->tester = new CommandTester($application->find('mbin:agent:sync'));
        $this->users = $this->getContainer()->get(UserRepository::class);
        $this->clients = $this->getContainer()->get(ClientManagerInterface::class);
    }
}
