<?php

namespace App\Services;

use App\Models\ClassificationType;
use App\Notifications\NewClassificationTypeNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Builds the VIT `classifications` column value from structured
 * classification input (Phase 6). Format is "Key=Value" pairs joined with
 * "|", e.g. "UNSPSC=12345678|Country of Origin=US|UPC_RTL=012345678905".
 *
 * UNSPSC and Country of Origin are always required, even if the vendor
 * didn't explicitly pick a "classification" — every other key is only
 * included if the vendor supplied a value for it. Unknown keys (not present
 * in classification_types) trigger an email alert to the VIT gateway
 * address so an admin can review/add the new type.
 */
class ClassificationEngine
{
    /** Keys that must always be present regardless of vendor selection. */
    protected const ALWAYS_REQUIRED_KEYS = ['UNSPSC', 'Country of Origin'];

    /**
     * @param  array<string, string>  $values  classification key => value, e.g.
     *                                         ['UNSPSC' => '12345678', 'Country of Origin' => 'US', 'UPC_RTL' => '012345678905']
     * @return string the "|"-joined classifications string for the VIT column
     *
     * @throws InvalidArgumentException if an always-required key is missing or a format rule fails
     */
    public function build(array $values, string $vendorName, string $catalogName): string
    {
        $this->assertAlwaysRequiredPresent($values);

        $pairs = [];

        foreach ($values as $key => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $this->validateFormat($key, $value);
            $this->alertIfUnknownType($key, $value, $vendorName, $catalogName);

            $pairs[] = "{$key}={$value}";
        }

        return implode('|', $pairs);
    }

    protected function assertAlwaysRequiredPresent(array $values): void
    {
        foreach (self::ALWAYS_REQUIRED_KEYS as $key) {
            if (empty(trim((string) ($values[$key] ?? '')))) {
                throw new InvalidArgumentException("The {$key} classification value is always required.");
            }
        }
    }

    /**
     * Format rules per VIT spec. Only NIGP Code has an explicit length
     * constraint (5 or 7 digits); other keys are free text but must not be
     * empty (already guaranteed by caller).
     */
    protected function validateFormat(string $key, string $value): void
    {
        if ($key === 'NIGP Code' && ! preg_match('/^\d{5}(\d{2})?$/', $value)) {
            throw new InvalidArgumentException('NIGP Code must be 5 or 7 digits.');
        }

        if ($key === 'MSDS URL' && ! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('MSDS Link must be a valid URL.');
        }
    }

    protected function alertIfUnknownType(string $key, string $value, string $vendorName, string $catalogName): void
    {
        $known = ClassificationType::query()->where('key', $key)->exists();

        if ($known) {
            return;
        }

        Notification::route('mail', config('vit.gateway_email'))
            ->notify(new NewClassificationTypeNotification($vendorName, $catalogName, $key, $value));
    }
}
