<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
#[OA\Schema()]
class MagazineMemberResponseDto implements \JsonSerializable
{
    public ?UserSmallResponseDto $user = null;
    public ?UserSmallResponseDto $addedBy = null;
    public ?\DateTimeInterface $createdAt = null;

    public static function create(
        UserSmallResponseDto $user,
        ?UserSmallResponseDto $addedBy,
        \DateTimeInterface $createdAt,
    ): self {
        $dto = new self();
        $dto->user = $user;
        $dto->addedBy = $addedBy;
        $dto->createdAt = $createdAt;

        return $dto;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'user' => $this->user->jsonSerialize(),
            'addedBy' => $this->addedBy?->jsonSerialize(),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
