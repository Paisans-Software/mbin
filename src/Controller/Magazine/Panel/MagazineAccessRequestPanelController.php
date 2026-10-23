<?php

declare(strict_types=1);

namespace App\Controller\Magazine\Panel;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Enum\MagazineAccess;
use App\Repository\MagazineAccessRequestRepository;
use App\Service\MagazineAccessRequestManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestPanelController extends AbstractController
{
    public function __construct(
        private readonly MagazineAccessRequestManager $manager,
        private readonly MagazineAccessRequestRepository $repository,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function requests(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        Request $request,
    ): Response {
        $this->assertPrivate($magazine);

        return $this->render(
            'magazine/panel/access_requests.html.twig',
            [
                'requests' => $this->repository->findPending($magazine, $this->getPageNb($request)),
                'magazine' => $magazine,
            ]
        );
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function approve(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        int $request_id,
        Request $request,
    ): Response {
        $accessRequest = $this->resolve($magazine, $request_id);
        // @phpstan-ignore arguments.count (the @method tag on AbstractController hides the real signature, as for every upstream caller)
        $this->validateCsrf('magazine_access_request_decide', $request->getPayload()->get('token'));

        $this->manager->approve($accessRequest, $this->getUserOrThrow());

        return $this->redirectToRoute('magazine_panel_access_requests', ['name' => $magazine->name]);
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function deny(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        int $request_id,
        Request $request,
    ): Response {
        $accessRequest = $this->resolve($magazine, $request_id);
        // @phpstan-ignore arguments.count (the @method tag on AbstractController hides the real signature, as for every upstream caller)
        $this->validateCsrf('magazine_access_request_decide', $request->getPayload()->get('token'));

        $this->manager->deny($accessRequest);

        return $this->redirectToRoute('magazine_panel_access_requests', ['name' => $magazine->name]);
    }

    private function assertPrivate(Magazine $magazine): void
    {
        if (MagazineAccess::Private !== $magazine->access) {
            throw new NotFoundHttpException();
        }
    }

    /** A request that does not exist, or belongs to another magazine, is a 404. */
    private function resolve(Magazine $magazine, int $requestId): MagazineAccessRequest
    {
        $this->assertPrivate($magazine);

        $accessRequest = $this->repository->find($requestId);
        if (null === $accessRequest || $accessRequest->magazine?->getId() !== $magazine->getId()) {
            throw new NotFoundHttpException();
        }

        return $accessRequest;
    }
}
