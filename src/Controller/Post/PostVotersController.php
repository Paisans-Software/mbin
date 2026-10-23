<?php

declare(strict_types=1);

namespace App\Controller\Post;

use App\Controller\AbstractController;
use App\Controller\Traits\PrivateContentTrait;
use App\Entity\Magazine;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Twig\Runtime\EscaperRuntime;

class PostVotersController extends AbstractController
{
    // FORK DIVERGENCE — do not offer this upstream: private magazines
    use PrivateContentTrait;

    public function __invoke(
        #[MapEntity(mapping: ['magazine_name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(id: 'post_id')]
        Post $post,
        Request $request,
    ): Response {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $this->assertMagazineViewable($post->magazine);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'html' => $this->renderView('components/voters_inline.html.twig', [
                    'voters' => $post->getUpVotes()->map(fn ($vote) => $vote->user->username),
                    'attributes' => new ComponentAttributes([], new EscaperRuntime()),
                    'count' => 0,
                ]),
            ]);
        }

        return $this->render('post/voters.html.twig', [
            'magazine' => $magazine,
            'post' => $post,
            'votes' => $post->getUpVotes(),
        ]);
    }
}
