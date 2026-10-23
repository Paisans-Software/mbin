<?php

declare(strict_types=1);

namespace App\Controller\Magazine\Panel;

use App\Controller\AbstractController;
use App\DTO\MagazineMemberDto;
use App\Entity\Magazine;
use App\Entity\User;
use App\Form\MagazineMemberType;
use App\Repository\MagazineMemberRepository;
use App\Repository\UserRepository;
use App\Service\MagazineMemberManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberController extends AbstractController
{
    public function __construct(
        private readonly MagazineMemberManager $manager,
        private readonly MagazineMemberRepository $repository,
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function members(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        Request $request,
    ): Response {
        if (!$magazine->isRestricted()) {
            throw new NotFoundHttpException();
        }

        $form = $this->createForm(MagazineMemberType::class, $dto = new MagazineMemberDto());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->userRepository->findOneByUsername($dto->username, true);

            if (null === $user || null !== $user->apId) {
                $form->get('username')->addError(new FormError($this->translator->trans('member_must_be_local_user')));
            } else {
                $this->manager->add($magazine, $user, $this->getUserOrThrow());

                return $this->redirectToRoute('magazine_panel_members', ['name' => $magazine->name]);
            }
        }

        return $this->render(
            'magazine/panel/members.html.twig',
            [
                'members' => $this->repository->findMembers($magazine, $this->getPageNb($request)),
                'magazine' => $magazine,
                'form' => $form->createView(),
            ]
        );
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function remove(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(mapping: ['username' => 'username'])]
        User $user,
        Request $request,
    ): Response {
        if (!$magazine->isRestricted()) {
            throw new NotFoundHttpException();
        }

        // @phpstan-ignore arguments.count (the @method tag on AbstractController hides the real signature, as for every upstream caller)
        $this->validateCsrf('magazine_member_remove', $request->getPayload()->get('token'));

        $this->manager->remove($magazine, $user);

        return $this->redirectToRoute('magazine_panel_members', ['name' => $magazine->name]);
    }
}
