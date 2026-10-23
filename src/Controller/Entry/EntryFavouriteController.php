<?php

declare(strict_types=1);

namespace App\Controller\Entry;

use App\Controller\AbstractController;
use App\Controller\Traits\MagazineViewTrait;
use App\Entity\Entry;
use App\Entity\Magazine;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EntryFavouriteController extends AbstractController
{
    // FORK DIVERGENCE — do not offer this upstream: private magazines
    use MagazineViewTrait;

    public function __invoke(
        #[MapEntity(mapping: ['magazine_name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(id: 'entry_id')]
        Entry $entry,
        Request $request,
    ): Response {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $this->assertMagazineViewable($entry->magazine);

        return $this->render('entry/favourites.html.twig', [
            'magazine' => $magazine,
            'entry' => $entry,
            'favourites' => $entry->favourites,
        ]);
    }
}
