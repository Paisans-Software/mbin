<?php

declare(strict_types=1);

namespace App\Controller\Magazine;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Service\MagazineAccessRequestManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestController extends AbstractController
{
    public function __construct(private readonly MagazineAccessRequestManager $manager)
    {
    }

    #[IsGranted('ROLE_USER')]
    public function request(#[MapEntity(mapping: ['name' => 'name'])] Magazine $magazine, Request $request): Response
    {
        $this->validateCsrf('magazine_access_request', $request->getPayload()->get('token'));

        try {
            $this->manager->request($magazine, $this->getUserOrThrow());
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('front_magazine', ['name' => $magazine->name]);
    }
}
