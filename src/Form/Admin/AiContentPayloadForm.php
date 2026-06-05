<?php

namespace Softspring\CmsAiPlugin\Form\Admin;

use Softspring\CmsBundle\Form\Type\DynamicFormType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Locales;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

class AiContentPayloadForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('defaultLocale', ChoiceType::class, [
                'choices' => array_combine(
                    array_map(fn (string $locale): string => Locales::getName($locale), $options['locales']),
                    $options['locales']
                ),
                'choice_translation_domain' => false,
            ])
            ->add('locales', ChoiceType::class, [
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(
                    array_map(fn (string $locale): string => Locales::getName($locale), $options['locales']),
                    $options['locales']
                ),
                'choice_translation_domain' => false,
                'constraints' => [
                    new Count(min: 1),
                ],
            ])
            ->add('sites', ChoiceType::class, [
                'multiple' => true,
                'expanded' => true,
                'choices' => $options['site_choices'],
                'constraints' => [
                    new Count(min: 1),
                ],
            ])
        ;

        if (!empty($options['content_config']['extra_fields'])) {
            $builder->add('extraData', DynamicFormType::class, [
                'form_fields' => $options['content_config']['extra_fields'],
                'translation_domain' => 'sfs_cms_contents',
            ]);
        }

        $builder
            ->add('routes', CollectionType::class, [
                'entry_type' => AiRoutePayloadForm::class,
                'entry_options' => [
                    'locales' => $options['locales'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'constraints' => [
                    new Count(min: 1),
                ],
            ])
            ->add('indexing', DynamicFormType::class, [
                'form_fields' => $options['content_config']['indexing'] ?? [],
                'translation_domain' => 'sfs_cms_contents',
                'label' => "admin_{$options['content_type']}.form.indexing.label",
                'label_format' => "admin_{$options['content_type']}.form.indexing.%name%.label",
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'content_type' => null,
            'content_config' => [],
            'locales' => [],
            'site_choices' => [],
            'translation_domain' => 'sfs_cms_contents',
        ]);

        $resolver->setRequired('content_type');
        $resolver->setRequired('content_config');
        $resolver->setAllowedTypes('content_type', 'string');
        $resolver->setAllowedTypes('content_config', 'array');
        $resolver->setAllowedTypes('locales', 'array');
        $resolver->setAllowedTypes('site_choices', 'array');
    }
}
