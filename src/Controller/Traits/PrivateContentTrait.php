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
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // in a restricted magazine whoever may not see it gets what a nonexistent thread gets
            $deny = true === $entry->getMagazine()?->isRestricted()
                ? fn () => $this->createNotFoundException()
                : fn () => $this->createAccessDeniedException();

            if (null === $this->getUser()) {
                throw $deny();
            }

            if (false === $this->getUser()->isFollowing($entry->user)) {
                throw $deny();
            }
        }
    }
}
