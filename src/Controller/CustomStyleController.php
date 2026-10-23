<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MagazineRepository;
use App\Security\Voter\MagazineVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomStyleController extends AbstractController
{
    public function __invoke(Request $request, MagazineRepository $repository): Response
    {
        $magazineName = $request->query->get('magazine');
        $magazine = $repository->findOneByName($magazineName);
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // a magazine the viewer cannot see answers exactly like a name that does not exist
        if (null !== $magazine && !$this->isGranted(MagazineVoter::VIEW, $magazine)) {
            $magazine = null;
        }

        $css = $this->renderView('styles/custom.css.twig', [
            'magazine' => $magazine,
        ]);

        return $this->createResponse($request, $css);
    }

    private function createResponse(Request $request, ?string $customCss): Response
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'text/css');
        $response->setPrivate();

        if (!empty($customCss)) {
            $response->setContent($customCss);
            $response->setEtag(md5($response->getContent()));
            $response->isNotModified($request);
        } else {
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        return $response;
    }
}
