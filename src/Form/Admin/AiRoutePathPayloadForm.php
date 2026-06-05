<?php

namespace Softspring\CmsAiPlugin\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Locales;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AiRoutePathPayloadForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('path', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => array_combine(
                    array_map(fn (string $locale): string => Locales::getName($locale), $options['locales']),
                    $options['locales']
                ),
                'choice_translation_domain' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'locales' => [],
        ]);

        $resolver->setAllowedTypes('locales', 'array');
    }
}
