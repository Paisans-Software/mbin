<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\AgentSyncCommand;
use App\DTO\UserDto;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class AgentSyncCommandTest extends KernelTestCase
{
    private const SECRET = 'first-secret-0123456789';
    private const DECLARED = [
        '--client-id' => 'engagement-agent',
        // Scopes this base carries. The agent scopes come from the agent API's own branch.
        '--scopes' => 'read write moderate:magazine_admin:update',
        '--username' => 'community-bot',
        '--contact-email' => 'bot@example.com',
    ];

    private CommandTester $tester;
    private UserRepository $users;
    private ClientManagerInterface $clients;
    private EntityManagerInterface $entityManager;

    public function testCreatesClientAndBot(): void
    {
        $this->sync();

        $this->assertSucceeded();
        $client = $this->client();
        $this->assertSame(AgentSyncCommand::DECLARED_NAME, $client->getName());
        $this->assertSame(AgentSyncCommand::DECLARED_DESCRIPTION, $client->getDescription());
        $this->assertTrue($client->isConfidential());
        $this->assertSame(self::SECRET, $client->getSecret());
        $this->assertSame(['client_credentials'], array_map('strval', $client->getGrants()));
        $this->assertSame(['read', 'write', 'moderate:magazine_admin:update'], array_map('strval', $client->getScopes()));
        $this->assertSame('bot@example.com', $client->getContactEmail());

        $bot = $this->users->findOneByUsername('community-bot');
        $this->assertSame($bot->getId(), $client->getUser()->getId());
        $this->assertSame(User::USER_TYPE_SERVICE, $bot->type);
        $this->assertNull($bot->apId);
        $this->assertTrue($bot->isVerified);
        $this->assertContains('ROLE_AGENT', $bot->getRoles());
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->sync();
        $botId = $this->users->findOneByUsername('community-bot')->getId();

        $this->sync();

        $this->assertSucceeded();
        $this->assertStringContainsString('already matches', $this->tester->getDisplay());
        $this->assertSame($botId, $this->client()->getUser()->getId());
    }

    public function testScopeOrderDoesNotMatter(): void
    {
        $this->sync();

        $this->sync(['--scopes' => 'moderate:magazine_admin:update write read']);

        $this->assertStringContainsString('already matches', $this->tester->getDisplay());
    }

    public function testRotatesSecret(): void
    {
        $this->sync();

        $this->sync(secret: 'second-secret-0123456789');

        $this->assertSucceeded();
        $this->assertSame('second-secret-0123456789', $this->client()->getSecret());
    }

    public function testUpdatesScopes(): void
    {
        $this->sync();

        $this->sync(['--scopes' => 'read']);

        $this->assertSame(['read'], array_map('strval', $this->client()->getScopes()));
    }

    public function testAcceptsAScopeAnAllowedScopeGrants(): void
    {
        $this->sync(['--scopes' => 'read entry:create']);

        $this->assertSucceeded();
    }

    public function testRestoresAgentRoleAndKeepsOtherRoles(): void
    {
        $this->sync();
        $bot = $this->users->findOneByUsername('community-bot');
        $bot->roles = ['ROLE_EXAMPLE'];
        $this->entityManager->flush();

        $this->sync();

        $this->assertSame(['ROLE_EXAMPLE', 'ROLE_AGENT'], $this->users->findOneByUsername('community-bot')->roles);
    }

    public function testEmptyIdIsNoop(): void
    {
        $this->sync(['--client-id' => '']);

        $this->assertSucceeded();
        $this->assertNull($this->users->findOneByUsername('community-bot'));
    }

    public function testMissingSecretFails(): void
    {
        $this->sync(secret: '');

        $this->assertRefusedWithoutChange();
    }

    public function testUnknownScopeFails(): void
    {
        $this->sync(['--scopes' => 'read no-such-scope']);

        $this->assertRefusedWithoutChange();
    }

    public function testAdminScopeFails(): void
    {
        $this->sync(['--scopes' => 'read admin:user:ban']);

        $this->assertRefusedWithoutChange();
    }

    public function testScopeOutsideTheAllowlistFails(): void
    {
        $this->sync(['--scopes' => 'read delete']);

        $this->assertRefusedWithoutChange();
    }

    public function testUsernameOfAPersonFails(): void
    {
        $dto = (new UserDto())->create('community-bot', 'person@example.com');
        $dto->plainPassword = 'secret';
        $this->getContainer()->get(UserManager::class)->create($dto, false);

        $this->sync();

        $this->assertRefusedWithoutChange();
        $this->assertNotContains('ROLE_AGENT', $this->users->findOneByUsername('community-bot')->getRoles());
    }

    public function testUsernameOfAnotherBotFails(): void
    {
        $dto = (new UserDto())->create('community-bot', 'other-bot@example.com');
        $dto->plainPassword = 'secret';
        $dto->isBot = true;
        $this->getContainer()->get(UserManager::class)->create($dto, false, false, true);

        $this->sync();

        $this->assertRefusedWithoutChange();
        $this->assertNotContains('ROLE_AGENT', $this->users->findOneByUsername('community-bot')->getRoles());
    }

    public function testRemoteAccountWithTheUsernameFails(): void
    {
        $dto = (new UserDto())->create('community-bot', 'remote@example.com', apId: 'https://remote.test/u/community-bot');
        $dto->plainPassword = 'secret';
        $dto->isBot = true;
        $this->getContainer()->get(UserManager::class)->create($dto, false, false, true);

        $this->sync();

        $this->assertRefusedWithoutChange();
    }

    public function testClientItDidNotCreateFails(): void
    {
        $existing = new Client('Someone else', 'engagement-agent', 'their-secret');
        $existing->setGrants(new Grant('client_credentials'));
        $existing->setContactEmail('someone@example.com');
        $this->clients->save($existing);

        $this->sync();

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertSame('their-secret', $this->client()->getSecret());
        $this->assertNull($this->users->findOneByUsername('community-bot'));
    }

    /**
     * @param array<string, string> $options
     */
    private function sync(array $options = [], string $secret = self::SECRET): void
    {
        $command = new AgentSyncCommand(
            $this->entityManager,
            $this->users,
            $this->getContainer()->get(UserManager::class),
            $this->clients,
            $this->getContainer()->get(RoleHierarchyInterface::class),
            clientSecret: $secret,
        );
        $command->setName('mbin:agent:sync');
        $this->tester = new CommandTester($command);
        $this->tester->execute($options + self::DECLARED);
    }

    private function assertSucceeded(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $this->tester->getDisplay());
    }

    private function assertRefusedWithoutChange(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertNull($this->clients->find('engagement-agent'));
    }

    private function client(): Client
    {
        $this->entityManager->clear();
        $client = $this->clients->find('engagement-agent');
        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->users = $this->getContainer()->get(UserRepository::class);
        $this->clients = $this->getContainer()->get(ClientManagerInterface::class);
        $this->entityManager = $this->getContainer()->get(EntityManagerInterface::class);
    }
}
