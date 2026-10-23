<?php

declare(strict_types=1);

namespace App\Controller\ActivityPub\Magazine;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Factory\ActivityPub\GroupFactory;
use App\Repository\MagazineFollowRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MagazineController extends AbstractController
{
    public function __construct(
        private readonly GroupFactory $groupFactory,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly MagazineFollowRepository $magazineFollowRepository,
    ) {
    }

    public function __invoke(Magazine $magazine): JsonResponse
    {
        if ($magazine->apId) {
            throw $this->createNotFoundException();
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // the same exception MagazineResolver throws for a missing name, so the two match
        if (!$this->actorIsServed($magazine)) {
            throw new NotFoundHttpException();
        }

        $response = new JsonResponse($this->groupFactory->create($magazine));

        $response->headers->set('Content-Type', 'application/activity+json');

        return $response;
    }

    /**
     * A restricted magazine's actor document exists only while the magazine follows
     * somebody: the remote side has to fetch it to verify the Follow, and holding a
     * follow is the moderators' explicit choice to be known to that server.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     */
    private function actorIsServed(Magazine $magazine): bool
    {
        return !$magazine->isRestricted() || $this->magazineFollowRepository->magazineHasFollows($magazine);
    }
}
