<?php

namespace App\Filament\Resources\CatalogSubmissions\Tables;

use App\Enums\CatalogSubmissionStatus;
use App\Jobs\GenerateCatalogExportJob;
use App\Models\CatalogExport;
use App\Models\CatalogSubmission;
use App\Notifications\CatalogSubmissionReviewedNotification;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requestedByClient.name')
                    ->searchable()
                    ->description(fn($record) => $record->requested_at->format('Y-m-d')),
                TextColumn::make('total_items')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state) => Str::headline($state->value ?? ''))
                    ->color(fn($state): string => match ($state) {
                        CatalogSubmissionStatus::Draft => 'gray',
                        CatalogSubmissionStatus::ReviewRequested => 'warning',
                        CatalogSubmissionStatus::Approved => 'success',
                        CatalogSubmissionStatus::Rejected => 'danger ',
                        CatalogSubmissionStatus::ReadyForUpload => 'info',
                        CatalogSubmissionStatus::Uploaded => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'review_requested' => 'Review Requested',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'ready_for_upload' => 'Ready for Upload',
                        'uploaded' => 'Uploaded',
                    ]),
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn() => \App\Models\Vendor::query()->pluck('name', 'id')),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve & Generate Export')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(CatalogSubmission $record) => $record->status === CatalogSubmissionStatus::ReviewRequested)
                    ->requiresConfirmation()
                    ->modalHeading('Approve catalog submission')
                    ->modalDescription('This will approve the submission and generate the Excel export file. The vendor will be notified when the export is ready.')
                    ->action(function (CatalogSubmission $record) {
                        // Guard: must be in review_requested status
                        if ($record->status !== CatalogSubmissionStatus::ReviewRequested) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('This submission is not in "Review Requested" status.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Guard: must have catalog items attached
                        $itemCount = $record->catalogItems()->count();
                        if ($itemCount === 0) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('This submission has no catalog items attached. The vendor may need to re-submit.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            DB::transaction(function () use ($record) {
                                // 1. Update submission status
                                $record->update([
                                    'status' => CatalogSubmissionStatus::Approved,
                                    'approved_by' => auth()->id(),
                                    'approved_at' => now(),
                                ]);

                                // 2. Create CatalogExport linked to this submission
                                // Enforce 1:1 relationship at DB level; catch duplicate dispatch
                                try {
                                    $export = CatalogExport::create([
                                        'vendor_id'             => $record->vendor_id,
                                        'catalog_submission_id' => $record->id,
                                        'catalog_name'          => $record->catalogUpload?->catalog_name ?? 'Catalog Export',
                                        'total_items'           => $record->complete_items,
                                        'disk'                  => 'spaces',
                                        'status'                => \App\Enums\CatalogExportStatus::Pending,
                                    ]);
                                } catch (\Illuminate\Database\QueryException $e) {
                                    // Duplicate export attempt
                                    throw new \RuntimeException('An export for this submission already exists.');
                                }

                                // Link back
                                $record->update(['catalog_export_id' => $export->id]);

                                // 3. Dispatch the export generation job with export ID
                                GenerateCatalogExportJob::dispatch($export->id);
                            });

                            Notification::make()
                                ->title('Submission approved')
                                ->body('The export has been queued for generation.')
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
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(CatalogSubmission $record) => $record->status === CatalogSubmissionStatus::ReviewRequested)
                    ->requiresConfirmation()
                    ->modalHeading('Reject catalog submission')
                    ->modalDescription('Enter the reason for rejection. The vendor will be notified.')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('rejection_reason')
                            ->label('Rejection reason')
                            ->required()
                            ->placeholder('Explain why the submission was rejected...'),
                    ])
                    ->action(function (CatalogSubmission $record, array $data) {
                        if ($record->status !== CatalogSubmissionStatus::ReviewRequested) {
                            Notification::make()
                                ->title('Cannot reject')
                                ->body('This submission is not in "Review Requested" status.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $record->update([
                                'status' => CatalogSubmissionStatus::Rejected,
                                'rejected_by' => auth()->id(),
                                'rejected_at' => now(),
                                'rejection_reason' => $data['rejection_reason'],
                            ]);

                            // Notify the requesting client
                            $client = $record->requestedByClient;
                            if ($client && $client->email) {
                                \Illuminate\Support\Facades\Notification::route('mail', $client->email)
                                    ->notify(new CatalogSubmissionReviewedNotification(
                                        vendorName: $record->vendor->name,
                                        catalogName: $record->catalogUpload?->catalog_name ?? 'Untitled',
                                        status: 'rejected',
                                        rejectionReason: $data['rejection_reason'],
                                    ));
                            }

                            Notification::make()
                                ->title('Submission rejected')
                                ->body('The vendor has been notified.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Rejection failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('confirmUpload')
                    ->label('Confirm VIT Upload')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->color('success')
                    ->visible(fn(CatalogSubmission $record) => $record->status === CatalogSubmissionStatus::ReadyForUpload)
                    ->requiresConfirmation()
                    ->modalHeading('Confirm VIT upload')
                    ->modalDescription('This will mark the catalog as uploaded to the VIT FTP server. This action should only be taken after the file has been successfully transferred.')
                    ->action(function (CatalogSubmission $record) {
                        if ($record->status !== CatalogSubmissionStatus::ReadyForUpload) {
                            Notification::make()
                                ->title('Cannot upload')
                                ->body('This submission is not ready for upload.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $updateData = [
                                'status' => CatalogSubmissionStatus::Uploaded,
                            ];

                            // Record timestamp only if the field exists
                            if (in_array('uploaded_at', $record->getFillable())) {
                                $updateData['uploaded_at'] = now();
                            }

                            // Record acting user only if the field exists
                            if (in_array('uploaded_by', $record->getFillable())) {
                                $updateData['uploaded_by'] = auth()->id();
                            }

                            $record->update($updateData);

                            Notification::make()
                                ->title('VIT upload confirmed')
                                ->body('The catalog has been marked as uploaded.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Upload confirmation failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
