<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Services\VitFieldDefinition;
use Illuminate\Support\Facades\Log;

/**
 * Determines whether incoming attributes represent a meaningful change to an
 * existing CatalogItem.
 *
 * Comparison is structural rather than raw-string, so order differences in
 * multi-value fields do not produce false updates, and null/empty-string are
 * treated as equivalent.
 *
 * Comparison order:
 * 1. Multi-value fields are compared structurally and never fall through to
 *    scalar comparison (arrays can't be cast to strings).
 * 2. Numeric values are compared numerically.
 * 3. Any remaining arrays are compared as JSON.
 * 4. Remaining scalars are compared after trimming.
 */
class CatalogItemComparator
{
    /**
     * Per-instance cache for getFieldDefinition() lookups. VitFieldDefinition
     * is a static class backed by an in-memory array (not an Eloquent model),
     * so this isn't avoiding DB queries — it's avoiding a repeated linear
     * scan (firstWhere()) over the definitions for the same column within a
     * single comparison run.
     *
     * @var array<string, object|null>
     */
    protected array $fieldDefinitionCache = [];

    public function __construct(
        protected CatalogValueNormalizer $valueNormalizer
    ) {}

    /**
     * Compare incoming values against an existing CatalogItem.
     *
     * @param array<int, string> $nonComparableColumns
     */
    public function hasMeaningfulChanges(
        CatalogItem $existing,
        array $newAttrs,
        array $nonComparableColumns,
        ?string $sku = null
    ): bool {
        $detectedChanges = [];
        $multiValueFields = $this->getMultiValueFieldKeys();

        foreach ($newAttrs as $column => $newValue) {
            if (in_array($column, $nonComparableColumns, true)) {
                continue;
            }

            $oldValue = $this->valueNormalizer->normalizeValueForComparison(
                $existing->getRawOriginal($column)
            );
            $newValue = $this->valueNormalizer->normalizeValueForComparison($newValue);

            if ($this->areEquivalentlyEmpty($oldValue, $newValue)) {
                continue;
            }

            // Multi-value fields require structural comparison and never fall
            // through to the scalar branch below (arrays can't be cast to string).
            if (in_array($column, $multiValueFields, true)) {
                if (!$this->multiValueValuesAreEqual($column, $oldValue, $newValue)) {
                    $detectedChanges[$column] = [
                        'old' => $this->valueNormalizer->decodeComparisonValue($oldValue),
                        'new' => $this->valueNormalizer->decodeComparisonValue($newValue),
                    ];
                }
                continue;
            }

            if (is_numeric($oldValue) && is_numeric($newValue)) {
                if ((float) $oldValue !== (float) $newValue) {
                    $detectedChanges[$column] = ['old' => $oldValue, 'new' => $newValue];
                }
                continue;
            }

            if (is_array($oldValue) || is_array($newValue)) {
                $oldValue = json_encode($oldValue ?? []);
                $newValue = json_encode($newValue ?? []);
            }

            if (trim((string) $oldValue) !== trim((string) $newValue)) {
                $detectedChanges[$column] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $this->logAndReport($detectedChanges, $sku);
    }

    /**
     * Compare two multi-value fields according to their definition: key/value
     * structures (specifications, classifications) sort by key, plain arrays
     * sort as an order-independent set — either way, order alone shouldn't
     * register as a change.
     */
    public function multiValueValuesAreEqual(string $column, mixed $oldValue, mixed $newValue): bool
    {
        $decodedOld = $this->valueNormalizer->decodeComparisonValue($oldValue);
        $decodedNew = $this->valueNormalizer->decodeComparisonValue($newValue);

        // ?? false guards against definitions (e.g. replacement_sku) that don't
        // set is_key_value at all — accessing a key absent from the source
        // DEFINITIONS array is an undefined-property warning on the (object)
        // cast, not just an empty value, so this must short-circuit before the
        // property read completes.
        $sortByKey = (bool) ($this->getFieldDefinition($column)->is_key_value ?? false);

        $this->sortForComparison($decodedOld, $sortByKey);
        $this->sortForComparison($decodedNew, $sortByKey);

        return $decodedOld === $decodedNew;
    }

    /**
     * Sort a decoded multi-value array in place so ordering differences don't
     * register as changes.
     */
    protected function sortForComparison(mixed &$value, bool $sortByKey): void
    {
        if (!is_array($value)) {
            return;
        }

        if ($sortByKey) {
            usort($value, fn($a, $b) => ($a['key'] ?? '') <=> ($b['key'] ?? ''));
        } else {
            sort($value);
        }
    }

    /**
     * Memoized VitFieldDefinition::find() lookup, scoped to this instance.
     */
    protected function getFieldDefinition(string $column): ?object
    {
        return $this->fieldDefinitionCache[$column]
            ??= VitFieldDefinition::find($column);
    }

    /**
     * True if the two values are "empty" in an equivalent way: both null, or
     * one null and the other an empty string.
     */
    protected function areEquivalentlyEmpty(mixed $oldValue, mixed $newValue): bool
    {
        return ($oldValue === null && $newValue === null)
            || ($oldValue === null && $newValue === '')
            || ($newValue === null && $oldValue === '');
    }

    /**
     * All column names — field_key and resolved model_attribute — that should
     * be treated as multi-value during comparison.
     *
     * @return array<int, string>
     */
    protected function getMultiValueFieldKeys(): array
    {
        $fields = [];

        $definitions = VitFieldDefinition::all()->where('is_multi_value', true);

        foreach ($definitions as $definition) {
            $fields[] = $definition->field_key;

            $modelAttribute = $definition->model_attribute ?? $definition->field_key;

            // Mirror CatalogItemAttributeBuilder::resolveModelAttribute: relationship/
            // accessor paths fall back to the field_key.
            if (!str_contains((string) $modelAttribute, '.') && $modelAttribute !== 'countryOfOrigin') {
                $fields[] = $modelAttribute;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Log detected changes (if any) and report whether the item changed.
     *
     * @param array<string, array{old: mixed, new: mixed}> $detectedChanges
     */
    protected function logAndReport(array $detectedChanges, ?string $sku): bool
    {
        if (empty($detectedChanges)) {
            return false;
        }

        Log::info('Catalog import: detected changes for SKU', [
            'sku' => $sku,
            'changes' => $detectedChanges,
        ]);

        return true;
    }
}
