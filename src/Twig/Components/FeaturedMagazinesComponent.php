<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Magazine;
use App\Entity\User;
use App\Repository\MagazineAccessClause;
use App\Repository\MagazineRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Twig\Environment;

#[AsTwigComponent('featured_magazines', template: 'components/_cached.html.twig')]
final class FeaturedMagazinesComponent
{
    public ?Magazine $magazine = null;

    public function __construct(
        private readonly Environment $twig,
        private readonly MagazineRepository $repository,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly Security $security,
    ) {
    }

    public function getHtml(ComponentAttributes $attributes): string
    {
        return $this->render();
    }

    private function render(): string
    {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $qb = $this->repository->createQueryBuilder('m')
            ->where('m.apId IS NULL')
            ->andWhere('m.visibility = :visibility')
            ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE)
            ->orderBy('m.lastActive', 'DESC')
            ->setMaxResults(28);
        $viewer = $this->security->getUser();
        MagazineAccessClause::applyDiscovery($qb, 'm', $viewer instanceof User ? $viewer : null);
        $magazines = $qb->getQuery()->getResult();

        if ($this->magazine && !\in_array($this->magazine, $magazines)) {
            array_unshift($magazines, $this->magazine);
        }

        usort($magazines, fn ($a, $b) => $a->lastActive < $b->lastActive ? 1 : -1);

        return $this->twig->render(
            'components/featured_magazines.html.twig',
            [
                'magazines' => array_map(fn ($mag) => $mag->name, $magazines),
                'magazine' => $this->magazine,
            ]
        );
    }
}
