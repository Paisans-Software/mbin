<?php

declare(strict_types=1);

namespace App\Controller\ActivityPub;

use App\Entity\Activity;
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
        // fetching it by id must not be the way around that. The entities decide, since
        // the URLs in the JSON carry whatever host the request came in on; the payload
        // check stays as a second opinion for objects held only as a URL. The magazine's
        // own follow handshake is delivered, so it stays fetchable for a server verifying it.
        if ($this->isWithheld($activity)
            || ($this->restrictedGuard->touchesRestrictedMagazine($json) && !$this->restrictedGuard->isAllowedHandshake($json))) {
            return new JsonResponse(status: 404);
        }

        $response = new JsonResponse($json);
        $response->headers->set('Content-Type', 'application/activity+json');

        return $response;
    }

    /**
     * FORK DIVERGENCE — do not offer this upstream: private magazines.
     */
    private function isWithheld(Activity $activity): bool
    {
        return !$this->isOwnFollowHandshake($activity) && $this->referencesRestrictedMagazine($activity);
    }

    /**
     * A Follow by a magazine, or the Undo of one, which a restricted magazine still sends.
     */
    private function isOwnFollowHandshake(Activity $activity): bool
    {
        // the actor properties have no default, so they are read with ?? throughout
        $follow = 'Undo' === $activity->type ? $activity->innerActivity : $activity;
        $actor = $activity->magazineActor ?? null;

        return null !== $follow
            && 'Follow' === $follow->type
            && null !== $actor
            && ($follow->magazineActor ?? null) === $actor
            && true !== $follow->objectMagazine?->isRestricted();
    }

    private function referencesRestrictedMagazine(Activity $activity): bool
    {
        $magazines = [
            $activity->audience,
            $activity->magazineActor ?? null,
            $activity->objectMagazine,
            $activity->objectMagazineBan?->magazine,
            $activity->objectEntry?->magazine,
            $activity->objectEntryComment?->magazine,
            $activity->objectPost?->magazine,
            $activity->objectPostComment?->magazine,
        ];

        foreach ($magazines as $magazine) {
            if (true === $magazine?->isRestricted()) {
                return true;
            }
        }

        return null !== $activity->innerActivity && $this->referencesRestrictedMagazine($activity->innerActivity);
    }
}
