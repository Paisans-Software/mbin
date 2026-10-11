<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\SettingsManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class FederationSettingsType extends AbstractType
{
    /**
     * The setting each field edits, so that a locked one renders disabled.
     */
    private const array FIELD_SETTINGS = [
        'federationEnabled' => 'KBIN_FEDERATION_ENABLED',
        'federationUsesAllowList' => 'MBIN_USE_FEDERATION_ALLOW_LIST',
        'federationPageEnabled' => 'KBIN_FEDERATION_PAGE_ENABLED',
    ];

    public function __construct(
        private readonly SettingsManager $settingsManager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('federationEnabled', CheckboxType::class, ['required' => false])
            ->add('federationUsesAllowList',
                CheckboxType::class,
                [
                    'required' => false,
                    'help' => 'federation_page_use_allowlist_help',
                ],
            )
            ->add('federationPageEnabled', CheckboxType::class, ['required' => false])
            ->add('submit', SubmitType::class)
        ;

        // A disabled field ignores whatever a request submits for it.
        foreach (self::FIELD_SETTINGS as $field => $setting) {
            if ($this->settingsManager->isLocked($setting)) {
                $builder->get($field)->setDisabled(true);
            }
        }
    }
}
