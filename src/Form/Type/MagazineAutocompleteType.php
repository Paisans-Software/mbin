<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Magazine;
use App\Entity\MagazineBlock;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Repository\MagazineAccessClause;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class MagazineAutocompleteType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Magazine::class,
            'choice_label' => 'name',
            'placeholder' => 'select_magazine',
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // the choices a submitted id is checked against, and the base of the autocomplete
            // search: an id of a hidden magazine the viewer cannot see fails validation exactly
            // like an id that does not exist; private magazines stay discoverable
            'query_builder' => function (EntityRepository $repository): QueryBuilder {
                $qb = $repository->createQueryBuilder('entity');
                $viewer = $this->security->getUser();
                $canView = MagazineAccessClause::dql($qb, 'entity', $viewer instanceof User ? $viewer : null);
                if (null !== $canView) {
                    $qb->andWhere("entity.access <> '".MagazineAccess::Hidden->value."' OR $canView");
                }

                return $qb;
            },
            'filter_query' => function (QueryBuilder $qb, string $query) {
                if ($currentUser = $this->security->getUser()) {
                    $qb
                        ->andWhere(
                            \sprintf(
                                'entity.id NOT IN (SELECT IDENTITY(mb.magazine) FROM %s mb WHERE mb.user = :user)',
                                MagazineBlock::class,
                            )
                        )
                        ->setParameter('user', $currentUser);
                }

                // FORK DIVERGENCE — do not offer this upstream: private magazines
                // before the early return: an empty query still lists magazines, page by page
                $viewer = $this->security->getUser();
                MagazineAccessClause::applyDiscovery($qb, 'entity', $viewer instanceof User ? $viewer : null);

                if (!$query) {
                    return;
                }

                $qb->andWhere('entity.name LIKE :filter OR entity.title LIKE :filter')
                    ->andWhere('entity.visibility = :visibility')
                    ->setParameter('filter', '%'.$query.'%')
                    ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE)
                ;
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
