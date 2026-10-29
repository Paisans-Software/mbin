<?php

declare(strict_types=1);

namespace App\Controller\Api\Agent;

use App\Controller\Api\BaseApi;
use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Magazine;
use App\Entity\User;
use App\Security\Voter\MagazineVoter;
use App\Service\MagazineManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lets an account holding ROLE_AGENT subscribe a local user to a magazine, or
 * unsubscribe them, on that user's behalf. The agent may only subscribe a user
 * where the user could subscribe themselves, so the magazine voter decides as it
 * would for them, and it never lifts a user's block. Unsubscribing is never
 * refused. Only a client credentials token, whose client's bot account is the
 * agent itself, may call either endpoint.
 */
class AgentMagazineSubscriptionApi extends BaseApi
{
    use AgentClientCredentialsTrait;

    #[OA\Response(
        response: 204,
        description: 'Draft: the user is subscribed to the magazine. Answers the same when they already were.',
    )]
    #[OA\Response(
        response: 400,
        description: 'Draft: the user is remote, banned, deleted, an admin or an agent, has blocked the magazine or could not subscribe to it themselves, or the magazine is not visible',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\BadRequestErrorSchema::class))
    )]
    #[OA\Response(
        response: 401,
        description: 'Permission denied due to missing or expired token',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\UnauthorizedErrorSchema::class))
    )]
    #[OA\Response(
        response: 403,
        description: 'The token lacks the agent:magazine:subscribe scope, its user is not an agent, or it is not a client credentials token of the agent\'s own client',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\ForbiddenErrorSchema::class))
    )]
    #[OA\Response(
        response: 404,
        description: 'User or magazine not found',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\NotFoundErrorSchema::class))
    )]
    #[OA\Response(
        response: 429,
        description: 'You are being rate limited',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\TooManyRequestsErrorSchema::class)),
    )]
    #[OA\Parameter(name: 'user_id', in: 'path', description: 'The user to subscribe', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'magazine_id', in: 'path', description: 'The magazine to subscribe them to', schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'agent')]
    #[Security(name: 'oauth2', scopes: ['agent:magazine:subscribe'])]
    #[IsGranted('ROLE_AGENT')]
    #[IsGranted('ROLE_OAUTH2_AGENT:MAGAZINE:SUBSCRIBE')]
    public function subscribe(
        #[MapEntity(id: 'user_id')]
        User $user,
        #[MapEntity(id: 'magazine_id')]
        Magazine $magazine,
        MagazineManager $manager,
        MagazineVoter $voter,
        RateLimiterFactoryInterface $apiUpdateLimiter,
    ): JsonResponse {
        $headers = $this->rateLimit($apiUpdateLimiter);
        $this->requireOwnClientCredentials();

        $this->refuseTarget($user, $magazine, $voter);

        if (!$magazine->isSubscribed($user)) {
            try {
                $manager->subscribe($magazine, $user);
            } catch (UniqueConstraintViolationException) {
                // Another request subscribed the user first, which is the state asked for.
            }
        }

        return new JsonResponse(status: 204, headers: $headers);
    }

    #[OA\Response(
        response: 204,
        description: 'Draft: the user is no longer subscribed to the magazine. Answers the same when they were not.',
    )]
    #[OA\Response(
        response: 401,
        description: 'Permission denied due to missing or expired token',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\UnauthorizedErrorSchema::class))
    )]
    #[OA\Response(
        response: 403,
        description: 'The token lacks the agent:magazine:subscribe scope, its user is not an agent, or it is not a client credentials token of the agent\'s own client',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\ForbiddenErrorSchema::class))
    )]
    #[OA\Response(
        response: 404,
        description: 'User or magazine not found',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\NotFoundErrorSchema::class))
    )]
    #[OA\Response(
        response: 429,
        description: 'You are being rate limited',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\TooManyRequestsErrorSchema::class)),
    )]
    #[OA\Parameter(name: 'user_id', in: 'path', description: 'The user to unsubscribe', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'magazine_id', in: 'path', description: 'The magazine to unsubscribe them from', schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'agent')]
    #[Security(name: 'oauth2', scopes: ['agent:magazine:subscribe'])]
    #[IsGranted('ROLE_AGENT')]
    #[IsGranted('ROLE_OAUTH2_AGENT:MAGAZINE:SUBSCRIBE')]
    public function unsubscribe(
        #[MapEntity(id: 'user_id')]
        User $user,
        #[MapEntity(id: 'magazine_id')]
        Magazine $magazine,
        MagazineManager $manager,
        RateLimiterFactoryInterface $apiUpdateLimiter,
    ): JsonResponse {
        $headers = $this->rateLimit($apiUpdateLimiter);
        $this->requireOwnClientCredentials();

        if ($magazine->isSubscribed($user)) {
            $manager->unsubscribe($magazine, $user);
        }

        return new JsonResponse(status: 204, headers: $headers);
    }

    /**
     * @throws BadRequestHttpException naming why the agent may not act for this user
     */
    private function refuseTarget(User $user, Magazine $magazine, MagazineVoter $voter): void
    {
        $reason = match (true) {
            null !== $user->apId => 'The user is not local.',
            $user->isDeleted || null !== $user->markedForDeletionAt => 'The user is deleted.',
            $user->isBanned => 'The user is banned.',
            $user->isAdmin() => 'The user is an admin.',
            $user->isAgent() => 'The user is an agent.',
            VisibilityInterface::VISIBILITY_VISIBLE !== $magazine->visibility => 'The magazine is not visible.',
            $user->isBlockedMagazine($magazine) => 'The user has blocked this magazine.',
            !$voter->canSubscribe($magazine, $user) => 'The user cannot subscribe to this magazine.',
            default => null,
        };

        if (null !== $reason) {
            throw new BadRequestHttpException($reason);
        }
    }
}
