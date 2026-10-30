<?php

declare(strict_types=1);

namespace App\Controller\Api\Agent;

use App\Controller\Api\Magazine\MagazineBaseApi;
use App\DTO\MagazineRequestDto;
use App\DTO\MagazineResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lets an account holding ROLE_AGENT create a magazine it then owns, even
 * when MBIN_RESTRICT_MAGAZINE_CREATION limits creation to admins.
 */
class AgentMagazineCreateApi extends MagazineBaseApi
{
    use AgentClientCredentialsTrait;

    #[OA\Response(
        response: 201,
        description: 'Draft: magazine created, owned by the agent',
        content: new Model(type: MagazineResponseDto::class),
        headers: [
            new OA\Header(header: 'X-RateLimit-Remaining', schema: new OA\Schema(type: 'integer'), description: 'Number of requests left until you will be rate limited'),
            new OA\Header(header: 'X-RateLimit-Retry-After', schema: new OA\Schema(type: 'integer'), description: 'Unix timestamp to retry the request after'),
            new OA\Header(header: 'X-RateLimit-Limit', schema: new OA\Schema(type: 'integer'), description: 'Number of requests available'),
        ]
    )]
    #[OA\Response(
        response: 400,
        description: 'The magazine is not valid',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\BadRequestErrorSchema::class))
    )]
    #[OA\Response(
        response: 401,
        description: 'Permission denied due to missing or expired token',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\UnauthorizedErrorSchema::class))
    )]
    #[OA\Response(
        response: 403,
        description: 'The token lacks the agent:magazine:create scope, its user is not an agent, or it is not a client credentials token of the agent\'s own client',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\ForbiddenErrorSchema::class))
    )]
    #[OA\Response(
        response: 429,
        description: 'You are being rate limited',
        content: new OA\JsonContent(ref: new Model(type: \App\Schema\Errors\TooManyRequestsErrorSchema::class)),
    )]
    #[OA\RequestBody(content: new Model(type: MagazineRequestDto::class))]
    #[OA\Tag(name: 'agent')]
    #[Security(name: 'oauth2', scopes: ['agent:magazine:create'])]
    #[IsGranted('ROLE_AGENT')]
    #[IsGranted('ROLE_OAUTH2_AGENT:MAGAZINE:CREATE')]
    public function __invoke(
        RateLimiterFactoryInterface $apiMagazineLimiter,
    ): JsonResponse {
        $headers = $this->rateLimit($apiMagazineLimiter);
        $this->requireOwnClientCredentials();

        $magazine = $this->createMagazine(ignoreCreationRestriction: true);

        return new JsonResponse(
            $this->serializeMagazine($this->manager->createDto($magazine)),
            status: 201,
            headers: $headers
        );
    }
}
