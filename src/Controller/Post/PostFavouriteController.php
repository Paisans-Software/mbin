<?php

declare(strict_types=1);

namespace App\Controller\Post;

use App\Controller\AbstractController;
use App\Controller\Traits\PrivateContentTrait;
use App\Entity\Magazine;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PostFavouriteController extends AbstractController
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

        return $this->render('post/favourites.html.twig', [
            'magazine' => $magazine,
            'post' => $post,
            'favourites' => $post->favourites,
        ]);
    }
}
