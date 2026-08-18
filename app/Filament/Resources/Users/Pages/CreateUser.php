<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Str::random(20);

        return $data;
    }

    protected function afterCreate(): void
    {
        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => $this->record->email],
            function (CanResetPassword $user, string $token): void {
                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                NotificationFacade::send($user, $notification);
            },
        );

        if ($status === Password::RESET_LINK_SENT) {
            Notification::make()
                ->title('User created and reset-link email sent.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('User created, but reset-link email could not be sent.')
            ->body(__($status))
            ->warning()
            ->send();
    }
}
