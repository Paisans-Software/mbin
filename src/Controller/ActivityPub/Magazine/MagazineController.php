<?php

declare(strict_types=1);

namespace App\Controller\ActivityPub\Magazine;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Factory\ActivityPub\GroupFactory;
use App\Repository\MagazineFollowRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

class MagazineController extends AbstractController
{
    public function __construct(
        private readonly GroupFactory $groupFactory,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly MagazineFollowRepository $magazineFollowRepository,
    ) {
    }

    // FORK DIVERGENCE — do not offer this upstream: private magazines
    // nullable so that a missing name is the same 404 as a withheld one, instead of the
    // TypeError (a 500) a non-nullable argument gets when no magazine resolves
    public function __invoke(?Magazine $magazine): JsonResponse
    {
        if (null === $magazine || $magazine->apId || !$this->actorIsServed($magazine)) {
            throw $this->createNotFoundException();
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
        return !$magazine->isRestricted() || [] !== $this->magazineFollowRepository->findByMagazine($magazine);
    }
}
