<?php

declare(strict_types=1);

namespace App\Controller\Traits;

use App\Entity\Contracts\ContentInterface;
use App\Entity\Magazine;
use App\Security\Voter\MagazineVoter;

/**
 * A magazine, or content in one, that the viewer may not see answers exactly like one
 * that does not exist: 404, never 403.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
trait MagazineViewTrait
{
    private function assertMagazineViewable(Magazine $magazine): void
    {
        if (!$this->isGranted(MagazineVoter::VIEW, $magazine)) {
            throw $this->createNotFoundException();
        }
    }

    private function assertTargetViewable(object $target): void
    {
        if ($target instanceof Magazine) {
            $this->assertMagazineViewable($target);
        } elseif ($target instanceof ContentInterface) {
            $this->assertMagazineViewable($target->getMagazine());
        }
    }
}
