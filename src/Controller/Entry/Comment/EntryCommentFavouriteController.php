<?php

declare(strict_types=1);

namespace App\Controller\Entry\Comment;

use App\Controller\AbstractController;
use App\Controller\Traits\PrivateContentTrait;
use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EntryCommentFavouriteController extends AbstractController
{
    // FORK DIVERGENCE — do not offer this upstream: private magazines
    use PrivateContentTrait;

    public function __invoke(
        #[MapEntity(mapping: ['magazine_name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(id: 'entry_id')]
        Entry $entry,
        #[MapEntity(id: 'comment_id')]
        EntryComment $comment,
        Request $request,
    ): Response {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $this->assertMagazineViewable($comment->magazine);

        return $this->render('entry/comment/favourites.html.twig', [
            'magazine' => $magazine,
            'entry' => $entry,
            'comment' => $comment,
            'favourites' => $comment->favourites,
        ]);
    }
}
