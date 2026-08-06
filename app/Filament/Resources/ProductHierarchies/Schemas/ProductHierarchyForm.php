<?php

namespace App\Filament\Resources\ProductHierarchies\Schemas;

use App\Models\ProductHierarchy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Admin-only 3-level product hierarchy management (Phase 3). Level 3
 * (leaf) rows carry the hierarchy_number VIT expects on the "hierarchy"
 * column, looked up from the concatenated "!" path built in Phase 4/6.
 */
class ProductHierarchyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('level')
                    ->options([1 => 'Level 1', 2 => 'Level 2', 3 => 'Level 3'])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('parent_id', null)),
                Select::make('parent_id')
                    ->label('Parent')
                    ->options(function ($get) {
                        $level = (int) $get('level');
                        if ($level <= 1) {
                            return [];
                        }

                        return ProductHierarchy::query()
                            ->where('level', $level - 1)
                            ->pluck('name', 'id');
                    })
                    ->visible(fn ($get) => (int) $get('level') > 1)
                    ->searchable(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('hierarchy_number')
                    ->helperText('Required for Level 3 (leaf) entries — the code VIT expects on the "hierarchy" column.')
                    ->maxLength(255),
            ]);
    }
}
