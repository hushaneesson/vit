<x-layouts.vendor title="My Appointments">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:items-center md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">My Appointments</h1>
                <p class="text-sm text-gray-500">{{ auth('client')->user()->vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.appointments.create') }}" class="w-full md:w-auto btn btn-primary">
                <i class="fas fa-calendar" aria-hidden="true"></i> Book Appointment
            </a>
        </div>

        <div class="grid gap-5 p-5 overflow-hidden bg-white rounded-lg shadow-lg lg:grid-cols-2">
            @if ($upcomingAppointments->isNotEmpty())
                @foreach ($upcomingAppointments as $upcomingAppointment)
                    <div class="p-5 space-y-8 border rounded-lg shadow-sm md:p-10 border-slate-200 bg-slate-50">
                        <div class="flex items-start gap-4">
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

                        <div class="flex justify-end mt-4">
                            <button type="button" class="btn btn-gray"
                                data-open-cancel-modal="cancel-modal-{{ $upcomingAppointment->id }}">
                                <i class="fas fa-ban"></i> Cancel Appointment
                            </button>
                        </div>

                        @php
                            $isValidationTarget = (string) old('appointment_id') === (string) $upcomingAppointment->id;
                        @endphp

                        <div id="cancel-modal-{{ $upcomingAppointment->id }}"
                            class="fixed inset-0 z-50 items-center justify-center hidden p-4" data-cancel-modal
                            aria-hidden="true" role="dialog" aria-modal="true">
                            <div class="absolute inset-0 bg-black/40" data-close-cancel-modal></div>

                            <div class="relative z-10 w-full max-w-lg bg-white shadow-2xl rounded-xl">
                                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
                                    <h2 class="text-lg font-semibold text-slate-900">Cancel appointment</h2>
                                    <button type="button"
                                        class="p-2 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                                        aria-label="Close cancellation modal" data-close-cancel-modal>
                                        <i class="fas fa-times" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <form method="POST"
                                    action="{{ route('vendor.appointments.cancel', $upcomingAppointment) }}"
                                    onsubmit="return confirm('Cancel this appointment?')" class="p-5 space-y-4">
                                    @csrf
                                    @method('PATCH')

                                    <input type="hidden" name="appointment_id" value="{{ $upcomingAppointment->id }}">

                                    <div>
                                        <label for="cancellation_reason_{{ $upcomingAppointment->id }}"
                                            class="block mb-1 text-sm font-medium text-gray-700">
                                            Cancellation reason
                                        </label>
                                        <textarea id="cancellation_reason_{{ $upcomingAppointment->id }}" name="cancellation_reason" rows="4" required
                                            minlength="5" maxlength="1000"
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md border-slate-300 focus:outline-none focus:ring-2 focus:ring-sky-200 focus:border-sky-400">{{ $isValidationTarget ? old('cancellation_reason') : '' }}</textarea>

                                        @if ($isValidationTarget)
                                            @error('cancellation_reason')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <div class="flex justify-end gap-3 pt-2">
                                        <button type="button" class="btn btn-light" data-close-cancel-modal>
                                            Keep Appointment
                                        </button>
                                        <button type="submit" class="btn btn-gray">
                                            Confirm Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.body;
            const modalSelector = '[data-cancel-modal]';

            const hideModal = (modal) => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.setAttribute('aria-hidden', 'true');
                body.classList.remove('overflow-hidden');
            };

            const showModal = (modal) => {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.setAttribute('aria-hidden', 'false');
                body.classList.add('overflow-hidden');

                const textarea = modal.querySelector('textarea[name="cancellation_reason"]');
                if (textarea) {
                    setTimeout(() => textarea.focus(), 50);
                }
            };

            document.querySelectorAll('[data-open-cancel-modal]').forEach((button) => {
                button.addEventListener('click', () => {
                    const modalId = button.getAttribute('data-open-cancel-modal');
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        showModal(modal);
                    }
                });
            });

            document.querySelectorAll('[data-close-cancel-modal]').forEach((button) => {
                button.addEventListener('click', () => {
                    const modal = button.closest(modalSelector);
                    if (modal) {
                        hideModal(modal);
                    }
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') {
                    return;
                }

                document.querySelectorAll(`${modalSelector}.flex`).forEach((modal) => {
                    hideModal(modal);
                });
            });

            const failedAppointmentId = @json(old('appointment_id'));
            const hasReasonError = {{ $errors->has('cancellation_reason') ? 'true' : 'false' }};

            if (failedAppointmentId && hasReasonError) {
                const failedModal = document.getElementById(`cancel-modal-${failedAppointmentId}`);
                if (failedModal) {
                    showModal(failedModal);
                }
            }
        });
    </script>
</x-layouts.vendor>
