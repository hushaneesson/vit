<?php

namespace App\Filament\Resources\CatalogSubmissions\Tables;

use App\Enums\CatalogSubmissionStatus;
use App\Jobs\UploadCatalogSubmissionToVit;
use App\Models\CatalogSubmission;
use App\Notifications\CatalogSubmissionReviewedNotification;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requestedByClient.name')
                    ->description(fn($record) => $record->vendor->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requested_at')
                    ->dateTime('F d, Y', 'America/New_York')
                    ->description(fn($record) => $record->total_items . ' items')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->label() ?? '')
                    ->color(fn($state) => $state?->filamentColor() ?? 'gray'),

                // file processing status (pending, generating, completed, uploading, failed)
                // TODO: need to refactor this to be more clear to the user what it represents
                //and ensure a descrition error is shown when the file is missing or failed to generate
                TextColumn::make('processing_status')
                    ->badge()
                    ->formatStateUsing(fn($state) => Str::headline($state ?? ''))
                    ->color(fn($state): string => match ($state) {
                        'pending' => 'gray',
                        'generating' => 'warning',
                        'completed' => 'success',
                        'uploading' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(CatalogSubmissionStatus::cases())
                            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
                            ->all()
                    ),
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn() => \App\Models\Vendor::query()->pluck('name', 'id')),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Mark as Uploaded')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(CatalogSubmission $record) => $record->status === CatalogSubmissionStatus::ReadyForReview)
                    ->requiresConfirmation()
                    ->modalHeading('Approve catalog submission')
                    ->modalDescription('This will approve the submission and upload the already-generated Excel file to the VIT FTP server. The file will NOT be regenerated.')
                    ->action(function (CatalogSubmission $record) {
                        // Guard: must be in ready_for_review status
                        if ($record->status !== CatalogSubmissionStatus::ReadyForReview) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('This submission is not ready for review.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Guard: processing_status must be completed
                        if ($record->processing_status !== 'completed') {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('The Excel file has not been generated yet. Please wait for generation to complete.')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Guard: file_path must exist
                        if (!$record->file_path) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('The Excel file path is missing. Please contact support.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Guard: file must exist on storage
                        if (!Storage::disk($record->disk ?? 'local')->exists($record->file_path)) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('The generated Excel file is missing from storage.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $userId = auth()->id();

                            $updateData = [
                                'status' => CatalogSubmissionStatus::Approved,
                                'approved_at' => now(),
                                'processing_status' => 'uploading',
                            ];

                            if ($userId !== null) {
                                $updateData['approved_by'] = $userId;
                            }

                            $record->update($updateData);

                            // Dispatch the FTP upload job (uploads existing file, does NOT regenerate)
                            UploadCatalogSubmissionToVit::dispatch($record->id);

                            Notification::make()
                                ->title('Submission approved')
                                ->body('The Excel file is being uploaded to the VIT FTP server.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Approval failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('downloadExcel')
                    ->label('Download File')
                    ->icon('heroicon-o-arrow-down-on-square')
                    ->color('info')
                    ->action(function (CatalogSubmission $record) {
                        if (!$record->file_path) {
                            Notification::make()
                                ->title('File not available')
                                ->body('The Excel file has not been generated yet')
                                ->warning()
                                ->send();
                            return;
                        }

                        if (!Storage::disk($record->disk ?? 'local')->exists($record->file_path)) {
                            Notification::make()
                                ->title('File not found')
                                ->body('The generated file is missing from storage')
                                ->danger()
                                ->send();
                            return;
                        }

                        $filename = 'catalog-submission-' . $record->id . '-' . now()->format('Y-m-d') . '.xlsx';

                        return Storage::disk($record->disk ?? 'local')->download($record->file_path, $filename);
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
