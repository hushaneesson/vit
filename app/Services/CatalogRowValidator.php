<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CatalogRowValidator
{
    /**
     * @param  Collection<int, \App\Models\CatalogFieldDefinition>  $fieldDefinitions  active definitions, keyed by field_key
     * @param  array<string, mixed>  $rowData  keyed by field_key
     * @return array{errors: array<int, array{field_key: string, message: string}>}
     */
    public function validate(Collection $fieldDefinitions, array $rowData): array
    {
        $errors = [];

        foreach ($fieldDefinitions as $definition) {
            $value = $rowData[$definition->field_key] ?? null;
            $isEmpty = is_null($value) || $value === '';

            if ($definition->requirement_type === 'required' && $isEmpty) {
                $errors[] = [
                    'field_key' => $definition->field_key,
                    'message' => "{$definition->web_app_label} is required.",
                ];

                continue;
            }

            if ($definition->requirement_type === 'conditional' && $isEmpty) {
                if ($this->conditionIsTriggered($definition, $rowData)) {
                    $errors[] = [
                        'field_key' => $definition->field_key,
                        'message' => "{$definition->web_app_label} is required based on another field's value.",
                    ];
                }

                continue;
            }

            if ($isEmpty) {
                continue;
            }

            $typeError = $this->validateType($definition, $value);
            if ($typeError) {
                $errors[] = [
                    'field_key' => $definition->field_key,
                    'message' => $typeError,
                ];
            }
        }

        return ['errors' => $errors];
    }

    private function conditionIsTriggered($definition, array $rowData): bool
    {
        if (! $definition->conditional_on_field) {
            return false;
        }

        $triggerValue = $rowData[$definition->conditional_on_field] ?? null;

        if (is_null($definition->conditional_on_value)) {
            return ! is_null($triggerValue) && $triggerValue !== '';
        }

        return (string) $triggerValue === (string) $definition->conditional_on_value;
    }

    private function validateType($definition, mixed $value): ?string
    {
        return match ($definition->field_type) {
            'number' => is_numeric($value) ? null : "{$definition->web_app_label} must be a number.",
            'decimal' => is_numeric($value) ? null : "{$definition->web_app_label} must be a decimal number.",
            'boolean' => in_array(strtolower((string) $value), ['1', '0', 'true', 'false', 'yes', 'no', ''], true)
                ? null
                : "{$definition->web_app_label} must be yes/no or true/false.",
            default => $definition->max_length && mb_strlen((string) $value) > $definition->max_length
                ? "{$definition->web_app_label} exceeds the maximum length of {$definition->max_length}."
                : null,
        };
    }
}
