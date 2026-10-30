<?php

declare(strict_types=1);

namespace App\Controller\Api\Agent;

use App\Entity\Client;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait AgentClientCredentialsTrait
{
    /**
     * An agent acts only through its own client: a token from the client
     * credentials grant names the client's bot account as its user. A token an
     * agent account obtained some other way, such as by signing in to a third
     * party application, is refused.
     *
     * @throws AccessDeniedHttpException
     */
    private function requireOwnClientCredentials(): void
    {
        $token = $this->getOAuthToken();
        $client = null !== $token ? $this->entityManager->find(Client::class, $token->getOAuthClientId()) : null;
        if (null === $client || $client->getUser()?->getId() !== $this->getUserOrThrow()->getId()) {
            throw new AccessDeniedHttpException();
        }
    }
}
