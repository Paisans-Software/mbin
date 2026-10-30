<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\OAuth2ClientDto;
use App\DTO\UserDto;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * FORK DIVERGENCE: do not offer this upstream. The deployment declares one
 * agent OAuth client in the environment, and this command makes Mbin match it
 * at every start. The docker entrypoint runs it after migrations when
 * MBIN_AGENT_CLIENT_ID is set.
 *
 * It creates the client and its bot account together, names the client
 * DECLARED_NAME and describes it as DECLARED_DESCRIPTION, and from then on
 * updates only a client carrying both marks. It never adopts a client or an
 * account it did not create: an existing client under the declared id without
 * the marks, or an existing account under the declared username that is not
 * that client's bot, is refused. The bot account is verified and holds
 * ROLE_AGENT. Its other roles are left alone.
 *
 * The secret is read from MBIN_AGENT_CLIENT_SECRET only, never from an option,
 * so it does not appear in a process list.
 */
#[AsCommand(
    name: 'mbin:agent:sync',
    description: 'Create or update the agent OAuth client and bot account declared by the MBIN_AGENT_* variables.',
)]
class AgentSyncCommand extends Command
{
    public const string DECLARED_NAME = 'Agent declared by MBIN_AGENT_CLIENT_ID';
    public const string DECLARED_DESCRIPTION = 'Created and kept by mbin:agent:sync from the MBIN_AGENT_* variables. Edits made elsewhere are overwritten at the next start.';

    /**
     * The scopes an agent client may hold, with whatever each grants through
     * the security role hierarchy. Nothing under admin is among them.
     */
    public const array ALLOWED_SCOPES = [
        'agent:magazine:subscribe',
        'agent:magazine:create',
        'read',
        'write',
        'moderate:magazine_admin:update',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly ClientManagerInterface $clients,
        private readonly RoleHierarchyInterface $roleHierarchy,
        #[Autowire('%env(default::MBIN_AGENT_CLIENT_ID)%')]
        private readonly ?string $clientId = null,
        #[Autowire('%env(default::MBIN_AGENT_CLIENT_SECRET)%')]
        private readonly ?string $clientSecret = null,
        #[Autowire('%env(default::MBIN_AGENT_CLIENT_SCOPES)%')]
        private readonly ?string $scopes = null,
        #[Autowire('%env(default::MBIN_AGENT_USERNAME)%')]
        private readonly ?string $username = null,
        #[Autowire('%env(default::MBIN_AGENT_CONTACT_EMAIL)%')]
        private readonly ?string $contactEmail = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Defaults to MBIN_AGENT_CLIENT_ID')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Space-separated; defaults to MBIN_AGENT_CLIENT_SCOPES')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Defaults to MBIN_AGENT_USERNAME')
            ->addOption('contact-email', null, InputOption::VALUE_REQUIRED, 'Defaults to MBIN_AGENT_CONTACT_EMAIL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = trim((string) ($input->getOption('client-id') ?? $this->clientId));
        if ('' === $id) {
            $io->note('No agent client is declared.');

            return Command::SUCCESS;
        }

        $secret = (string) $this->clientSecret;
        $username = trim((string) ($input->getOption('username') ?? $this->username));
        $email = trim((string) ($input->getOption('contact-email') ?? $this->contactEmail));
        $scopes = preg_split('/\s+/', trim((string) ($input->getOption('scopes') ?? $this->scopes)), -1, PREG_SPLIT_NO_EMPTY);

        if ('' === $secret || '' === $username || '' === $email || [] === $scopes) {
            return $this->refuse($io, 'MBIN_AGENT_CLIENT_SECRET, MBIN_AGENT_CLIENT_SCOPES, MBIN_AGENT_USERNAME and MBIN_AGENT_CONTACT_EMAIL must all be set with MBIN_AGENT_CLIENT_ID.');
        }

        $refused = array_values(array_filter($scopes, fn (string $scope) => !$this->isAllowed($scope)));
        if ([] !== $refused) {
            return $this->refuse($io, \sprintf('MBIN_AGENT_CLIENT_SCOPES names scopes an agent client may not hold: %s.', implode(' ', $refused)));
        }

        $client = $this->clients->find($id);
        \assert(null === $client || $client instanceof Client);
        if (null !== $client && !$this->isDeclared($client)) {
            return $this->refuse($io, \sprintf('An OAuth client "%s" exists that mbin:agent:sync did not create. It is left alone. Choose another MBIN_AGENT_CLIENT_ID.', $id));
        }

        $bot = $this->users->findOneByUsername($username);
        if (null !== $bot && $bot->getId() !== $client?->getUser()?->getId()) {
            return $this->refuse($io, \sprintf('The username "%s" belongs to an account that is not this agent client\'s bot. Choose another MBIN_AGENT_USERNAME.', $username));
        }
        if (null !== $bot && (null !== $bot->apId || User::USER_TYPE_SERVICE !== $bot->type)) {
            return $this->refuse($io, \sprintf('The account "%s" is not a local bot account. Choose another MBIN_AGENT_USERNAME.', $username));
        }

        if (null !== $client && null !== $bot && $this->matches($client, $secret, $scopes, $email) && $this->botIsReady($bot)) {
            $io->success('The agent client already matches its declaration.');

            return Command::SUCCESS;
        }

        $this->entityManager->wrapInTransaction(function () use (&$client, &$bot, $id, $secret, $scopes, $email, $username): void {
            $bot ??= $this->createBot($username, $email);
            // An agent's bot signs in like any other account, so it must be verified.
            // The role string is the agent API's; this branch does not carry User::isAgent().
            $bot->isVerified = true;
            if (!\in_array('ROLE_AGENT', $bot->roles, true)) {
                $bot->roles[] = 'ROLE_AGENT';
            }

            $client ??= new Client(self::DECLARED_NAME, $id, $secret);
            $client->setDescription(self::DECLARED_DESCRIPTION);
            $client->setGrants(new Grant('client_credentials'));
            $client->setScopes(...array_map(fn (string $scope) => new Scope($scope), $scopes));
            $client->setContactEmail($email);
            $client->setUser($bot);
            $client->setActive(true);
            $this->clients->save($client);

            if ($client->getSecret() !== $secret) {
                // The OAuth2 bundle's client has no setter for its secret, so a
                // rotation writes the column. Tokens already issued stay valid
                // until they expire, as they would after any rotation.
                $this->entityManager->createQuery('UPDATE '.Client::class.' c SET c.secret = :secret WHERE c.identifier = :id')
                    ->execute(['secret' => $secret, 'id' => $id]);
            }
        });

        $io->success('The agent client and its bot account now match their declaration.');

        return Command::SUCCESS;
    }

    private function refuse(SymfonyStyle $io, string $reason): int
    {
        $io->error($reason);

        return Command::FAILURE;
    }

    /**
     * A scope is allowed when it is one of ALLOWED_SCOPES or is granted by one
     * of them through the role hierarchy, and Mbin knows it.
     */
    private function isAllowed(string $scope): bool
    {
        if (!\in_array($scope, OAuth2ClientDto::AVAILABLE_SCOPES, true)) {
            return false;
        }

        $reachable = $this->roleHierarchy->getReachableRoleNames(array_map(fn (string $allowed) => $this->role($allowed), self::ALLOWED_SCOPES));

        return \in_array($this->role($scope), $reachable, true);
    }

    private function role(string $scope): string
    {
        return 'ROLE_OAUTH2_'.strtoupper($scope);
    }

    private function isDeclared(Client $client): bool
    {
        return self::DECLARED_NAME === $client->getName() && self::DECLARED_DESCRIPTION === $client->getDescription();
    }

    private function createBot(string $username, string $email): User
    {
        $dto = new UserDto();
        $dto->username = $username;
        $dto->email = $email;
        // Only client credentials sign in as this account, so nobody needs its password.
        $dto->plainPassword = hash('sha512', random_bytes(32));
        $dto->isBot = true;

        return $this->userManager->create($dto, verifyUserEmail: false, rateLimit: false, preApprove: true);
    }

    private function botIsReady(User $bot): bool
    {
        return $bot->isVerified && \in_array('ROLE_AGENT', $bot->roles, true);
    }

    /**
     * @param string[] $scopes
     */
    private function matches(Client $client, string $secret, array $scopes, string $email): bool
    {
        $held = array_map('strval', $client->getScopes());
        sort($held);
        sort($scopes);

        return $client->getSecret() === $secret
            && ['client_credentials'] === array_map('strval', $client->getGrants())
            && $held === $scopes
            && $client->getContactEmail() === $email
            && $client->isActive();
    }
}
