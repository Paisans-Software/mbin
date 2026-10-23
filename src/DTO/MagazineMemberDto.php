<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberDto
{
    #[Assert\NotBlank]
    public ?string $username = null;
}
