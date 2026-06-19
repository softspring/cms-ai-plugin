<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Admin\Site;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AiSiteInstructionsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $textareaOptions = [
            'required' => false,
            'empty_data' => '',
            'attr' => [
                'rows' => 4,
            ],
        ];

        $builder
            ->add('siteDescription', TextareaType::class, $textareaOptions)
            ->add('targetAudience', TextareaType::class, $textareaOptions)
            ->add('editorialTone', TextareaType::class, $textareaOptions)
            ->add('brandVoice', TextareaType::class, $textareaOptions)
            ->add('contentGuidelines', TextareaType::class, $textareaOptions)
            ->add('seoGuidelines', TextareaType::class, $textareaOptions)
            ->add('forbiddenTopics', TextareaType::class, $textareaOptions)
            ->add('extraInstructions', TextareaType::class, $textareaOptions)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'sfs_cms_ai',
            'label_format' => 'site.ai.form.%name%.label',
        ]);
    }
}
