<?php

namespace App\Filament\Resources\ClassificationTypes\Pages;

use App\Filament\Resources\ClassificationTypes\ClassificationTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClassificationType extends EditRecord
{
    protected static string $resource = ClassificationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
