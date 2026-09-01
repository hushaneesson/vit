<?php

namespace App\Services;

/**
 * Converts item weights between common units and pounds.
 *
 * catalog_items.item_weight is ALWAYS stored in pounds (required by the VIT
 * specification). The mapper's item_weight unit selection simply determines
 * how a value entered for a normal (non-VIT) upload is converted before it
 * is persisted.
 */
class WeightUnitConverter
{
    public const LB = 'lb';
    public const KG = 'kg';
    public const OZ = 'oz';
    public const G = 'g';

    /**
     * Multiplier applied to a value in the given unit to obtain pounds.
     */
    public const FACTORS_TO_POUNDS = [
        self::LB => 1.0,
        self::KG => 2.2046226218,
        self::OZ => 0.0625,      // 1 / 16
        self::G => 0.0022046226, // 1 / 453.59237
    ];

    public const DEFAULT_UNIT = self::LB;

    /**
     * @return array<int, string>  ['lb', 'kg', 'oz', 'g']
     */
    public static function supportedUnits(): array
    {
        return array_keys(self::FACTORS_TO_POUNDS);
    }

    public static function isSupported(string $unit): bool
    {
        return isset(self::FACTORS_TO_POUNDS[strtolower($unit)]);
    }

    /**
     * Convert a value expressed in $unit to pounds.
     *
     * Unsupported or blank units are treated as pounds (no conversion) so the
     * importer stays defensive against bad or legacy configuration.
     */
    public static function toPounds(float $value, string $unit): float
    {
        $unit = strtolower(trim($unit));

        if ($unit === '' || ! self::isSupported($unit)) {
            return $value;
        }

        return $value * self::FACTORS_TO_POUNDS[$unit];
    }

    /**
     * Convert a stored pounds value back into the given display unit.
     */
    public static function fromPounds(float $pounds, string $unit): float
    {
        $unit = strtolower(trim($unit));

        if ($unit === '' || ! self::isSupported($unit)) {
            return $pounds;
        }

        return $pounds / self::FACTORS_TO_POUNDS[$unit];
    }
}