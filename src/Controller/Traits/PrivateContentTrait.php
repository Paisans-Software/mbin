<?php

declare(strict_types=1);

namespace App\Controller\Traits;

use App\Entity\Contracts\ContentInterface;

/**
 * @method createAccessDeniedException()
 */
trait PrivateContentTrait
{
    // FORK DIVERGENCE — do not offer this upstream: private magazines
    use MagazineViewTrait;

    private function handlePrivateContent(ContentInterface $entry): void
    {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $this->assertMagazineViewable($entry->getMagazine());

        if (true === $entry->isPrivate()) {
            if (null === $this->getUser()) {
                throw $this->createAccessDeniedException();
            }

            if (false === $this->getUser()->isFollowing($entry->user)) {
                throw $this->createAccessDeniedException();
            }
        }
    }
}
