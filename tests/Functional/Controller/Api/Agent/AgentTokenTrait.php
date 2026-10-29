<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Agent;

trait AgentTokenTrait
{
    /**
     * A client credentials token for the test bot, holding ROLE_AGENT unless
     * $grantRole is false.
     */
    private function agentToken(string $scope, bool $grantRole = true): string
    {
        self::createOAuth2ClientCredsClient();
        // A bot account signs in like any other, so it must be verified.
        $bot = $this->userRepository->findOneByUsername('test_bot');
        $bot->isVerified = true;
        if ($grantRole) {
            $bot->setOrRemoveAgentRole();
        }
        $this->entityManager->flush();

        $this->client->request('POST', '/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'testclient',
            'client_secret' => 'testsecret',
            'scope' => $scope,
        ]);
        $json = self::getJsonResponse($this->client);

        return $json['token_type'].' '.$json['access_token'];
    }
}
