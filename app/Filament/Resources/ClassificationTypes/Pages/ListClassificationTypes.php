<?php

namespace App\Filament\Resources\ClassificationTypes\Pages;

use App\Filament\Resources\ClassificationTypes\ClassificationTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClassificationTypes extends ListRecords
{
    protected static string $resource = ClassificationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
