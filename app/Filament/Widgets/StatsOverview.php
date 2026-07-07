<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Vendor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(
                'Total Vendors',
                Vendor::count()
            )
                ->description('Registered vendors')
                ->descriptionIcon('heroicon-m-building-storefront'),


            Stat::make(
                'Active Vendors',
                Vendor::where('status', 'active')->count()
            )
                ->description('Currently active')
                ->descriptionIcon('heroicon-m-check-circle'),


            Stat::make(
                'Total Clients',
                Client::count()
            )
                ->description('Registered clients')
                ->descriptionIcon('heroicon-m-users'),

            Stat::make(
                'Appointments Today',
                Appointment::whereDate('start_date', now()->toDateString())->count()
            )
                ->description('Scheduled appointments')
                ->descriptionIcon('heroicon-m-check-circle'),

            Stat::make(
                'Upcoming Appointments',
                Appointment::whereDate('start_date', '>', now()->toDateString())->count()
            )
                ->description('Scheduled appointments')
                ->descriptionIcon('heroicon-m-check-circle'),
        ];
    }
}
