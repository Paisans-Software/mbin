<?php

declare(strict_types=1);

namespace App\ArgumentValueResolver;

use App\Entity\Magazine;
use App\Repository\MagazineRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MagazineResolver implements ValueResolverInterface
{
    public function __construct(private readonly MagazineRepository $repository)
    {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): \Generator
    {
        if (Magazine::class !== $argument->getType()) {
            return;
        }

        $magazineName = $request->attributes->get('magazine_name') ?? $request->attributes->get('name');

        $magazine = $this->repository->findOneByName($magazineName);

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // a missing name is a 404, the same one a withheld magazine gets, not the
        // TypeError (a 500) a non-nullable controller argument throws on null
        if (null === $magazine && !$argument->isNullable()) {
            throw new NotFoundHttpException();
        }

        yield $magazine;
    }
}
