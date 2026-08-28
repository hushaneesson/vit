<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientAppointmentCancelledNotification;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query
                    ->where('schedulable_type', \App\Models\User::class)
                    ->where('schedulable_id', Auth::id())
                    ->where('schedule_type', \Zap\Enums\ScheduleTypes::APPOINTMENT->value);
            })
            ->defaultSort('start_date', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label('Appointment')
                    ->description(fn($record): string => $record->description)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('start_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('periods')
                    ->label('Time')
                    ->getStateUsing(function (Appointment $record) {
                        return $record->periods
                            ->map(fn($period) => \Carbon\Carbon::parse($period->start_time)->format('h:i A')
                                . ' - '
                                . \Carbon\Carbon::parse($period->end_time)->format('h:i A'))
                            ->implode(', ');
                    })
                    ->wrap()
                    ->placeholder('No times set'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(bool $state): string => $state ? 'Scheduled' : 'Cancelled')
                    ->color(fn(bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        1 => 'Scheduled',
                        0 => 'Cancelled',
                    ])
                    ->default(1),
            ])
            ->recordActions([
                Action::make('cancelAppointment')
                    ->label('Cancel Appointment')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(Appointment $record): bool => (bool) $record->is_active)
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('cancellation_reason')
                            ->label('Cancellation reason')
                            ->required()
                            ->minLength(5)
                            ->maxLength(1000)
                            ->rows(4),
                    ])
                    ->action(function (Appointment $record, array $data): void {
                        $reason = trim((string) ($data['cancellation_reason'] ?? ''));

                        $metadata = is_array($record->metadata) ? $record->metadata : [];

                        $record->update([
                            'is_active' => 0,
                            'metadata' => array_merge($metadata, [
                                'cancellation_reason' => $reason,
                                'cancelled_at' => now()->toDateTimeString(),
                                'cancelled_by' => Auth::user()?->name,
                            ]),
                        ]);

                        $clientId = (int) data_get($record->metadata, 'client_id');
                        $client = $clientId ? Client::find($clientId) : null;
                        $bookedWith = $record->schedulable;
                        $firstPeriod = $record->periods->sortBy('start_time')->first();
                        $cancelledByName = Auth::user()?->name ?? 'Admin';

                        if ($client) {
                            $client->notifyNow(new ClientAppointmentCancelledNotification(
                                bookedWithName: $bookedWith instanceof User ? $bookedWith->name : 'your appointment provider',
                                date: $record->start_date->format('Y-m-d'),
                                startsAt: (string) data_get($firstPeriod, 'start_time', ''),
                                endsAt: (string) data_get($firstPeriod, 'end_time', ''),
                                cancelledByName: $cancelledByName,
                                cancellationReason: $reason,
                            ));
                        }


                        $this->getLivewire()->dispatch('notify', type: 'success', message: 'Appointment cancelled');
                    }),
            ]);
    }
}
