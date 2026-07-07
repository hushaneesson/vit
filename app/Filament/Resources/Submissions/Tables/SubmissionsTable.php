<?php

namespace App\Filament\Resources\Submissions\Tables;

use App\Jobs\UploadSubmissionToVit;
use App\Models\Submission;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;


class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->searchable(),
                TextColumn::make('catalog_name')
                    ->searchable(),
                TextColumn::make('product_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('file_size')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 1).' KB' : '—'),
                TextColumn::make('submission_date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'uploaded' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('upload_attempts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_upload_error')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uploaded_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn () => Vendor::query()->pluck('name', 'id')),
                SelectFilter::make('status')
                    ->options([
                        'pending_upload' => 'Pending upload',
                        'processing' => 'Processing',
                        'uploaded' => 'Uploaded',
                        'failed' => 'Failed',
                    ]),
                Filter::make('submission_date')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('submission_date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('submission_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('uploadToVit')
                    ->label('Upload to VIT')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (Submission $record) => in_array($record->status, ['pending_upload', 'failed']))
                    ->requiresConfirmation()
                    ->action(function (Submission $record) {
                        UploadSubmissionToVit::dispatch($record->id);

                        Notification::make()
                            ->title('Upload queued')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('uploadSelectedToVit')
                        ->label('Upload selected to VIT')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records
                                ->whereIn('status', ['pending_upload', 'failed'])
                                ->each(fn (Submission $submission) => UploadSubmissionToVit::dispatch($submission->id));

                            Notification::make()
                                ->title('Uploads queued')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retryFailedUploads')
                        ->label('Retry failed uploads')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records
                                ->where('status', 'failed')
                                ->each(fn (Submission $submission) => UploadSubmissionToVit::dispatch($submission->id));

                            Notification::make()
                                ->title('Retries queued')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
