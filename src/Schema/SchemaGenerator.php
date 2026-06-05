<?php

namespace Softspring\CmsAiPlugin\Schema;

use Softspring\Component\DynamicFormType\Form\Type\DynamicFormType;
use Softspring\Component\FormSchema\Schema\SchemaExtractor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;

class SchemaGenerator
{
    public function __construct(protected SchemaExtractor $schemaExtractor)
    {
    }

    public function generateSchema(FormInterface|AbstractType|string $form, array $options = []): array
    {
        return $this->schemaExtractor->extract($form, $options);
    }

    public function dynamicSchema(array $formFields, array $options = []): array
    {
        return $this->generateSchema(DynamicFormType::class, array_merge($options, [
            'form_fields' => $formFields,
        ]));
    }

    public function moduleSchema(array $module): array
    {
        if (isset($module['json_schema']) && is_array($module['json_schema'])) {
            return $module['json_schema'];
        }

        if (isset($module['module_options']['json_schema']) && is_array($module['module_options']['json_schema'])) {
            return $module['module_options']['json_schema'];
        }

        $formFields = $module['form_fields'] ?? $module['module_options']['form_fields'] ?? null;

        if (is_array($formFields)) {
            return $this->dynamicSchema($formFields);
        }

        return [
            'type' => 'object',
            'properties' => [],
        ];
    }
}
