<?php

namespace App\Services;

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
        $errors = [];

        // dealer_sku is the unique identifier for catalog items. Without it,
        // the row cannot be matched to an existing item or created as a new
        // one. Unlike other "required" business fields (description,
        // manufacturer, brand) which can be left null and completed later,
        // a missing dealer_sku is a data quality error that prevents import.
        if (empty($rowData['dealer_sku'])) {
            $errors[] = [
                'field_key' => 'dealer_sku',
                'message' => 'Seller SKU is required to identify and import a catalog item.',
            ];
        }

        // NOTE: Other required and conditional field checks have been removed.
        //
        // The application now imports a vendor's existing catalog so they
        // can complete and edit it inside the application before submission.
        // Missing business fields (e.g. description, manufacturer, brand)
        // should NOT prevent importing — they simply become null on the
        // CatalogItem and can be completed later from the Catalog Item List.
        //
        // Only type-level data quality errors (non-numeric values in number
        // fields, values exceeding max length) are still reported. These
        // represent genuinely bad data, not missing fields.

        foreach ($fieldDefinitions as $definition) {
            $value = $rowData[$definition->field_key] ?? null;
            $isEmpty = is_null($value) || $value === '';

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
