<x-layouts.vendor title="My Appointments">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">My Appointments</h1>
                <p class="text-sm text-gray-500">{{ auth('client')->user()->vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.appointments.create') }}" class="btn btn-primary">
                + {{ $upcomingAppointment ? 'Book another appointment' : 'Book an appointment' }}
            </a>
        </div>

        <div class="p-5 overflow-hidden bg-white rounded-lg shadow-lg">
            @if ($upcomingAppointment)
                <div class="flex items-start gap-4">
                    {{-- Date block --}}
                    <div
                        class="flex flex-col items-center justify-center border rounded-lg w-14 h-14 shrink-0 border-sky-100 bg-sky-50 text-sky-700">
                        <span class="text-[10px] font-semibold uppercase leading-none">
                            {{ $upcomingAppointment->starts_at->format('M') }}
                        </span>
                        <span class="text-lg font-bold leading-none">
                            {{ $upcomingAppointment->starts_at->format('d') }}
                        </span>
                    </div>

                    {{-- Details --}}
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800">
                            {{ $upcomingAppointment->starts_at->format('l, F j, Y') }}
                        </p>
                        <p class="text-sm text-gray-500">
                            {{ $upcomingAppointment->starts_at->format('h:i A') }}
                            &ndash;
                            {{ $upcomingAppointment->ends_at->format('h:i A') }}
                        </p>

                        @if ($upcomingAppointment->status)
                            <span
                                class="inline-flex items-center px-2 py-0.5 mt-2 text-[11px] font-medium rounded-full bg-emerald-50 text-emerald-700">
                                {{ ucfirst($upcomingAppointment->status) }}
                            </span>
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col items-center p-20 text-center">
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
