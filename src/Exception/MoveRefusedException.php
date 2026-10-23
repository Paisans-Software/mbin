<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Magazine;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Content may not cross the boundary of a restricted magazine in either direction.
 */
final class MoveRefusedException extends \Exception
{
    public function __construct(public readonly Magazine $from, public readonly Magazine $to)
    {
        parent::__construct(\sprintf('Moving content from magazine %s to %s is refused: a restricted magazine is involved', $from->name, $to->name));
    }

    public static function assertAllowed(Magazine $from, Magazine $to): void
    {
        if ($from->isRestricted() || $to->isRestricted()) {
            throw new self($from, $to);
        }
    }
}
