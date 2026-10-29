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

/**
 * FORK DIVERGENCE — do not offer this upstream: the deployment declares one
 * agent OAuth client in the environment, and this command makes Mbin match it
 * at every start. The docker entrypoint runs it after migrations when
 * MBIN_AGENT_CLIENT_ID is set. It creates or updates that client and its bot
 * account, and gives the account ROLE_AGENT. It touches nothing else.
 */
#[AsCommand(
    name: 'mbin:agent:sync',
    description: 'Create or update the agent OAuth client and bot account declared by the MBIN_AGENT_* variables.',
)]
class AgentSyncCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly ClientManagerInterface $clients,
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
            ->addOption('client-secret', null, InputOption::VALUE_REQUIRED, 'Defaults to MBIN_AGENT_CLIENT_SECRET')
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

        $secret = (string) ($input->getOption('client-secret') ?? $this->clientSecret);
        $username = trim((string) ($input->getOption('username') ?? $this->username));
        $email = trim((string) ($input->getOption('contact-email') ?? $this->contactEmail));
        $scopes = preg_split('/\s+/', trim((string) ($input->getOption('scopes') ?? $this->scopes)), -1, PREG_SPLIT_NO_EMPTY);

        if ('' === $secret || '' === $username || '' === $email || [] === $scopes) {
            $io->error('MBIN_AGENT_CLIENT_SECRET, MBIN_AGENT_CLIENT_SCOPES, MBIN_AGENT_USERNAME and MBIN_AGENT_CONTACT_EMAIL must all be set with MBIN_AGENT_CLIENT_ID.');

            return Command::FAILURE;
        }

        $unknown = array_diff($scopes, OAuth2ClientDto::AVAILABLE_SCOPES);
        if ([] !== $unknown) {
            $io->error(\sprintf('MBIN_AGENT_CLIENT_SCOPES names scopes Mbin does not have: %s.', implode(' ', $unknown)));

            return Command::FAILURE;
        }

        $bot = $this->users->findOneByUsername($username);
        if (null !== $bot && User::USER_TYPE_SERVICE !== $bot->type) {
            $io->error(\sprintf('The username "%s" belongs to an account that is not a bot. Choose another MBIN_AGENT_USERNAME.', $username));

            return Command::FAILURE;
        }

        $changed = false;
        if (null === $bot) {
            $bot = $this->createBot($username, $email);
            $changed = true;
        }

        if (!$bot->isVerified || ['ROLE_AGENT'] !== $bot->roles) {
            // An agent's bot signs in like any other account, so it must be verified.
            // The role string is the agent API's; this branch does not carry User::isAgent().
            $bot->isVerified = true;
            $bot->roles = ['ROLE_AGENT'];
            $this->entityManager->flush();
            $changed = true;
        }

        $client = $this->clients->find($id);
        if (null === $client) {
            $client = new Client('Agent', $id, $secret);
        }
        \assert($client instanceof Client);

        if ($this->matches($client, $secret, $scopes, $email, $bot)) {
            $io->success($changed ? 'The agent bot account has been updated.' : 'The agent client already matches its declaration.');

            return Command::SUCCESS;
        }

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

        $io->success('The agent client has been updated to match its declaration.');

        return Command::SUCCESS;
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

    /**
     * @param string[] $scopes
     */
    private function matches(Client $client, string $secret, array $scopes, string $email, User $bot): bool
    {
        return $client->getSecret() === $secret
            && ['client_credentials'] === array_map('strval', $client->getGrants())
            && $scopes === array_map('strval', $client->getScopes())
            && $client->getContactEmail() === $email
            && $client->getUser()?->getId() === $bot->getId()
            && $client->isActive();
    }
}
