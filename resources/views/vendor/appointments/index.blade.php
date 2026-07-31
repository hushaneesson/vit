<x-layouts.vendor title="My Appointments">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:items-center md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">My Appointments</h1>
                <p class="text-sm text-gray-500">{{ auth('client')->user()->vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.appointments.create') }}" class="w-full md:w-auto btn btn-primary">
                + {{ $upcomingAppointments->isNotEmpty() ? 'Book Another Appointment' : 'Book An Appointment' }}
            </a>
        </div>

        <div class="grid gap-5 p-5 overflow-hidden bg-white rounded-lg shadow-lg md:grid-cols-2">
            @if ($upcomingAppointments->isNotEmpty())
                @foreach ($upcomingAppointments as $upcomingAppointment)
                    <div class="flex items-start gap-4 p-10">
                        {{-- Date block --}}
                        <div
                            class="flex flex-col items-center justify-center border rounded-lg w-14 h-14 shrink-0 border-sky-100 bg-sky-50 text-sky-700">
                            <span class="text-sm font-semibold leading-none uppercase ">
                                {{ $upcomingAppointment->start_date->format('M') }}
                            </span>
                            <span class="text-lg font-bold leading-none">
                                {{ $upcomingAppointment->start_date->format('d') }}
                            </span>
                        </div>

                        {{-- Details --}}
                        <div class="flex-1 min-w-0">
                            <p class="mb-2 font-semibold text-gray-800">
                                {{ $upcomingAppointment->start_date->format('l, F j, Y') }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ \Carbon\Carbon::parse($upcomingAppointment->periods->first()->start_time)->format('h:i A') }}
                                &ndash;
                                {{ \Carbon\Carbon::parse($upcomingAppointment->periods->first()->end_time)->format('h:i A') }}
                            </p>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="flex flex-col items-center py-20 text-center col-span-full">
                    <div class="flex items-center justify-center w-20 h-20 mb-3 rounded-full bg-gray-50">
                        <x-heroicon-o-calendar class="w-10 h-10 text-gray-400" />
                    </div>

                    <p class="font-medium text-gray-700 ">No appointments scheduled</p>
                    <p class="mt-1 text-gray-400">Book an appointment.</p>
                </div>
            @endif
        </div>
    </div>
</x-layouts.vendor>
