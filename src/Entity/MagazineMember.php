<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Repository\MagazineMemberRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * A user admitted to a private or hidden magazine.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
#[Entity(repositoryClass: MagazineMemberRepository::class)]
#[UniqueConstraint(name: 'magazine_member_idx', columns: ['magazine_id', 'user_id'])]
class MagazineMember
{
    use CreatedAtTrait {
        CreatedAtTrait::__construct as createdAtTraitConstruct;
    }

    #[ManyToOne(targetEntity: Magazine::class, inversedBy: 'members')]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Magazine $magazine;
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user;
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $addedBy;
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    // @phpstan-ignore property.onlyRead (written by Doctrine, as every generated id in this codebase)
    private int $id;

    public function __construct(Magazine $magazine, User $user, ?User $addedBy)
    {
        $this->magazine = $magazine;
        $this->user = $user;
        $this->addedBy = $addedBy;

        $this->createdAtTraitConstruct();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __sleep()
    {
        return [];
    }
}
