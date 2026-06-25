<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Admin;

use Softspring\CmsAiPlugin\Media\AiImageModelChoices;
use Softspring\CmsAiPlugin\Media\MediaImageGenerationRequirements;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AiMediaGenerateForm extends AbstractType
{
    public function __construct(
        protected MediaTypesCollection $mediaTypesCollection,
        protected MediaImageGenerationRequirements $generationRequirements,
        protected AiImageModelChoices $imageModelChoices,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $modelsByPlatform = $this->imageModelChoices->getModelsByPlatform();
        $platforms = array_combine(array_keys($modelsByPlatform), array_keys($modelsByPlatform)) ?: [];
        $selectedPlatform = $options['selected_platform'] ?: (array_key_first($platforms) ?: 'openai');
        if (!isset($modelsByPlatform[$selectedPlatform])) {
            $selectedPlatform = array_key_first($platforms) ?: 'openai';
        }

        $models = $modelsByPlatform[$selectedPlatform] ?? ['gpt-image-1' => 'gpt-image-1'];

        $builder
            ->add('previewToken', HiddenType::class, [
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'required' => true,
                'label' => 'media.image_generation.create.form.type.label',
                'choices' => $this->getMediaTypeChoices(),
                'choice_translation_domain' => false,
                'attr' => [
                    'data-ai-media-type-input' => '',
                    'onchange' => 'this.form.requestSubmit(this.form.querySelector("[name$=\"[refresh]\"]"))',
                ],
            ])
            ->add('platform', ChoiceType::class, [
                'required' => true,
                'label' => 'media.image_generation.form.platform.label',
                'choices' => $platforms,
                'data' => $selectedPlatform,
                'choice_translation_domain' => false,
                'attr' => [
                    'data-ai-media-image-models' => json_encode($modelsByPlatform, JSON_THROW_ON_ERROR),
                    'data-ai-media-image-model-input' => 'ai_media_generate_form_model',
                ],
            ])
            ->add('model', ChoiceType::class, [
                'required' => true,
                'label' => 'media.image_generation.form.model.label',
                'choices' => $models,
                'data' => $models[$options['selected_model']] ?? ($models['gpt-image-1'] ?? array_key_first($models)),
                'choice_translation_domain' => false,
            ])
            ->add('prompt', TextareaType::class, [
                'required' => true,
                'label' => 'media.image_generation.form.prompt.label',
                'help' => 'media.image_generation.create.form.prompt.help',
                'attr' => [
                    'rows' => 6,
                    'placeholder' => 'media.image_generation.form.prompt.placeholder',
                ],
            ])
            ->add('refresh', SubmitType::class, [
                'label' => 'media.image_generation.create.actions.refresh',
                'attr' => ['class' => 'd-none'],
            ])
            ->add('preview', SubmitType::class, [
                'label' => 'media.image_generation.create.actions.preview',
                'attr' => ['class' => 'btn btn-outline-primary'],
            ])
            ->add('create', SubmitType::class, [
                'label' => 'media.image_generation.create.actions.create',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'sfs_cms_ai',
            'selected_platform' => null,
            'selected_model' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function getMediaTypeChoices(): array
    {
        $choices = [];

        foreach ($this->mediaTypesCollection->getTypes(false) as $key => $type) {
            if ('image' !== ($type['type'] ?? null)) {
                continue;
            }

            if (!$this->generationRequirements->supportsPngOutput($type['upload_requirements'] ?? [])) {
                continue;
            }

            $choices[$type['name'] ?? $key] = $key;
        }

        return $choices;
    }
}
