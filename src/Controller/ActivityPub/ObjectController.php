<?php

declare(strict_types=1);

namespace App\Controller\ActivityPub;

use App\Repository\ActivityRepository;
use App\Service\ActivityPub\ActivityJsonBuilder;
use App\Service\ActivityPub\RestrictedMagazineFederationGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

class ObjectController
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly ActivityJsonBuilder $activityJsonBuilder,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly RestrictedMagazineFederationGuard $restrictedGuard,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $activity = $this->activityRepository->findOneBy(['uuid' => $uuid, 'isRemote' => false]);
        if (null === $activity) {
            return new JsonResponse(status: 404);
        }

        $json = $this->activityJsonBuilder->buildActivityJson($activity);

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // an activity is never delivered when it is about a restricted magazine, and
        // fetching it by id must not be the way around that. The magazine's own follow
        // handshake is delivered, so it stays fetchable for a server verifying it.
        if ($this->restrictedGuard->touchesRestrictedMagazine($json) && !$this->restrictedGuard->isAllowedHandshake($json)) {
            return new JsonResponse(status: 404);
        }

        $response = new JsonResponse($json);
        $response->headers->set('Content-Type', 'application/activity+json');

        return $response;
    }
}
