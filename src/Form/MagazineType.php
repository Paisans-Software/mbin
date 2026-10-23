<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\MagazineDto;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Form\EventListener\DisableFieldsOnMagazineEdit;
use App\Form\EventListener\RemoveRulesFieldIfEmpty;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MagazineType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['required' => true])
            ->add('title', TextType::class, ['required' => true])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('rules', TextareaType::class, [
                'required' => false,
                'help' => 'magazine_rules_deprecated',
            ])
            ->add('isAdult', CheckboxType::class, ['required' => false])
            ->add('isPostingRestrictedToMods', CheckboxType::class, ['required' => false])
            ->add('discoverable', CheckboxType::class, [
                'required' => false,
                'help' => 'magazine_discoverable_help',
            ])
            ->add('indexable', CheckboxType::class, [
                'required' => false,
                'help' => 'magazine_indexable_by_search_engines_help',
            ])
            // this is removed through the event subscriber below on magazine edit
            ->add('nameAsTag', CheckboxType::class, [
                'required' => false,
                'help' => 'magazine_name_as_tag_help',
            ])
            ->add('submit', SubmitType::class);

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // Only an admin picks the access level, and only while creating: it is never edited.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $user = $this->security->getUser();
            if (!$user instanceof User || !$user->isAdmin() || null !== $event->getData()?->getId()) {
                return;
            }

            $event->getForm()->add('access', EnumType::class, [
                'class' => MagazineAccess::class,
                'choice_label' => fn (MagazineAccess $access) => 'magazine_access.'.$access->value,
                'label' => 'magazine_access',
                'required' => true,
            ]);
        });

        $builder->addEventSubscriber(new DisableFieldsOnMagazineEdit());
        $builder->addEventSubscriber(new RemoveRulesFieldIfEmpty());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class' => MagazineDto::class,
            ]
        );
    }
}
