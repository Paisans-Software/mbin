<?php

declare(strict_types=1);

namespace App\Controller\Traits;

use App\Entity\Contracts\ContentInterface;
use App\Entity\Magazine;
use App\Security\Voter\MagazineVoter;

/**
 * @method createAccessDeniedException()
 */
trait PrivateContentTrait
{
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

    // FORK DIVERGENCE — do not offer this upstream: private magazines
    private function assertMagazineViewable(Magazine $magazine): void
    {
        if (!$this->isGranted(MagazineVoter::VIEW, $magazine)) {
            throw $this->createNotFoundException();
        }
    }

    // FORK DIVERGENCE — do not offer this upstream: private magazines
    private function assertTargetViewable(object $target): void
    {
        if ($target instanceof Magazine) {
            $this->assertMagazineViewable($target);
        } elseif ($target instanceof ContentInterface) {
            $this->assertMagazineViewable($target->getMagazine());
        }
    }
}
