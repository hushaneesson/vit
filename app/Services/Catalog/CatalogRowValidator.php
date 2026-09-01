<?php

namespace App\Services\Catalog;

use Illuminate\Support\Collection;

class CatalogRowValidator
{
    /**
     * @param  Collection<int, object>  $fieldDefinitions  active definitions (from VitFieldDefinition), keyed by field_key
     * @param  array<string, mixed>  $rowData  keyed by field_key
     * @return array{errors: array<int, array{field_key: string, message: string}>}
     */
    public function validate(Collection $fieldDefinitions, array $rowData): array
    {
        $warnings = [];
        $errors = [];

        // dealer_sku is the unique identifier for catalog items. Without it,
        // the row cannot be matched to an existing item or created as a new
        // one. Missing dealer_sku remains a blocking validation error for now.
        if (empty($rowData['dealer_sku'])) {
            $errors[] = [
                'field_key' => 'dealer_sku',
                'message' => 'Seller SKU is required to identify and import a catalog item.',
            ];
        }

        // The mapper allows incomplete uploads. Missing required fields other
        // than dealer_sku are business-data warnings, not blockers. Missing
        // conditional fields become warnings when their trigger condition is
        // active in the row. Only genuinely malformed or unsafe data stays in
        // the blocking error bucket.
        foreach ($fieldDefinitions as $definition) {
            $value = $rowData[$definition->field_key] ?? null;
            $isEmpty = is_null($value) || $value === '' || $value === [];

            // Required field missing (unmapped or empty) — warning unless it
            // is the dealer_sku identity key, which stays blocking.
            if ($definition->requirement_type === 'required' && $isEmpty) {
                if (($definition->field_key ?? null) === 'dealer_sku') {
                    continue;
                }

                // The field that maps to catalog_items.name is structurally
                // required: the DB column is NOT NULL, so a row without it
                // cannot be inserted. Treat it as a blocking error so it is
                // never passed to CatalogItem::create() with a null name.
                if (($definition->model_attribute ?? null) === 'name') {
                    $errors[] = [
                        'field_key' => $definition->field_key,
                        'message' => "Required field '{$definition->web_app_label}' is missing and is required to create a catalog item.",
                    ];
                    continue;
                }

                $warnings[] = [
                    'field_key' => $definition->field_key,
                    'message' => "Required field '{$definition->web_app_label}' is missing.",
                ];
                continue;
            }

            // Skip further checks if the value is empty
            if ($isEmpty) {
                continue;
            }

            // Type-level data quality checks
            $typeError = $this->validateType($definition, $value);
            if ($typeError) {
                $errors[] = [
                    'field_key' => $definition->field_key,
                    'message' => $typeError,
                ];
            }

            // Conditional check: if this field's trigger is active, the
            // conditional field must be present in the row.
            if ($definition->conditional_on_field && $definition->conditional_on_value) {
                $triggerValue = $rowData[$definition->conditional_on_field] ?? null;
                if ($this->matchesCondition($triggerValue, $definition->conditional_on_value)) {
                    // Trigger is active — check if the dependent field has a value
                    $dependentKey = $definition->field_key;
                    $dependentValue = $rowData[$dependentKey] ?? null;
                    if (is_null($dependentValue) || $dependentValue === '' || $dependentValue === []) {
                        $warnings[] = [
                            'field_key' => $dependentKey,
                            'message' => "Required when '{$definition->web_app_label}' is set: '{$definition->web_app_label}'.",
                        ];
                    }
                }
            }
        }

        return [
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function validateType($definition, mixed $value): ?string
    {
        if (is_array($value)) {
            if (! ($definition->is_multi_value ?? false)) {
                return "{$definition->web_app_label} must be a single value, not an array.";
            }

            $isKeyValue = $definition->is_key_value ?? false;

            foreach ($value as $item) {
                if (is_array($item)) {
                    if ($isKeyValue) {
                        // Key/value fields: skip incomplete entries instead of erroring.
                        // Incomplete entries (missing key or value) are filtered out
                        // during import so they don't appear in stored data.
                        if (!isset($item['key']) || !isset($item['value'])) {
                            continue;
                        }
                    } else {
                        // Normal array fields: items must be scalar strings
                        return "{$definition->web_app_label} contains a nested array value that is not supported.";
                    }
                } elseif ($isKeyValue) {
                    // Key/value field but item is not an array - skip non-array entries
                    // rather than erroring, so malformed data is simply ignored.
                    continue;
                }
            }

            if ($isKeyValue) {
                // For key/value fields, validate the joined representation length
                $stringParts = [];
                foreach ($value as $item) {
                    if (is_array($item) && isset($item['key']) && isset($item['value'])) {
                        $stringParts[] = (string) $item['key'] . '=' . (string) $item['value'];
                    }
                }
                $joinedValue = implode($definition->join_separator ?? ',', $stringParts);
            } else {
                $joinedValue = implode($definition->join_separator ?? ',', array_map(
                    static fn(mixed $item) => is_scalar($item) || $item === null ? (string) $item : '',
                    $value,
                ));
            }

            return $definition->max_length && mb_strlen($joinedValue) > $definition->max_length
                ? "{$definition->web_app_label} exceeds the maximum length of {$definition->max_length}."
                : null;
        }

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

    /**
     * Check whether a trigger value matches the condition from the definition.
     */
    private function matchesCondition(mixed $triggerValue, string $conditionalOnValue): bool
    {
        if (is_null($triggerValue)) {
            return false;
        }

        if (is_array($triggerValue)) {
            foreach ($triggerValue as $candidate) {
                if ($this->matchesCondition($candidate, $conditionalOnValue)) {
                    return true;
                }
            }

            return false;
        }

        if (is_object($triggerValue)) {
            return false;
        }

        $normalizedTrigger = strtolower(trim((string) $triggerValue));
        $normalizedExpected = strtolower(trim($conditionalOnValue));

        // Boolean-like triggers
        if (in_array($normalizedExpected, ['true', 'false', '1', '0', 'yes', 'no'], true)) {
            return in_array($normalizedTrigger, ['true', 'false', '1', '0', 'yes', 'no'], true)
                && $normalizedTrigger === $normalizedExpected;
        }

        // String contains (for multi-value fields like classifications)
        if (str_contains($normalizedTrigger, $normalizedExpected)) {
            return true;
        }

        return $normalizedTrigger === $normalizedExpected;
    }
}
