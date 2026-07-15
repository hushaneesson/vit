<div>
    <div class="overflow-hidden bg-white border border-gray-200 shadow-sm rounded-xl">
        <h1 class="p-4 text-lg font-semibold text-center text-sky-700">Book an appointment with {{ $user->name }}</h1>
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">

            <button wire:click="previousMonth"
                class="inline-flex items-center justify-center w-8 h-8 text-gray-400 transition-colors rounded-lg hover:text-gray-600 hover:bg-gray-100">
                <x-heroicon-m-chevron-left class="w-4 h-4" />
            </button>


            <h3 class="text-sm font-semibold text-gray-800">
                {{ \Carbon\Carbon::create($year, $month)->format('F Y') }}
            </h3>


            <button wire:click="nextMonth"
                class="inline-flex items-center justify-center w-8 h-8 text-gray-400 transition-colors rounded-lg hover:text-gray-600 hover:bg-gray-100">
                <x-heroicon-m-chevron-right class="w-4 h-4" />
            </button>

        </div>



        {{-- Week Header --}}
        <div class="grid grid-cols-7 border-b border-gray-100">

            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                <div class="py-2 text-xs font-semibold tracking-wide text-center text-gray-400 uppercase">
                    {{ $day }}
                </div>
            @endforeach

        </div>



        {{-- Calendar Days --}}
        <div class="grid grid-cols-7">

            @foreach ($this->calendar as $day)
                <div
                    class="
                    min-h-[110px]
                    p-1.5
                    border-b
                    border-r
                    border-gray-100

                    {{ $day['date']->month !== $month ? 'bg-gray-50' : 'bg-white' }}
                ">

                    {{-- Date --}}
                    <div
                        class="
                        flex
                        items-center
                        justify-center
                        w-6
                        h-6
                        mb-2
                        text-xs
                        font-semibold
                        rounded-full

                        @if ($day['date']->isToday()) bg-sky-500 text-white
                        @elseif($day['date']->month !== $month)
                            text-gray-300
                        @else
                            text-gray-700 @endif
                    ">
                        {{ $day['date']->day }}
                    </div>



                    {{-- Available Slots --}}
                    <div class="space-y-1">


                        @foreach (collect($day['slots'])->take(3) as $slot)
                            <button
                                class="
                                w-full
                                px-1.5
                                py-1
                                text-[11px]
                                font-medium
                                text-sky-700
                                bg-sky-50
                                border
                                border-sky-100
                                rounded-md
                                transition
                                hover:bg-sky-500
                                hover:text-white
                            ">
                                {{ $slot }}
                            </button>
                        @endforeach



                        {{-- More Slots Button --}}
                        @if (count($day['slots']) > 3)
                            <button wire:click="viewSlots('{{ $day['date']->format('Y-m-d') }}')"
                                class="
                                w-full
                                px-1.5
                                py-1
                                text-[11px]
                                font-semibold
                                text-sky-600
                                border
                                border-sky-200
                                rounded-md
                                hover:bg-sky-50
                            ">
                                +{{ count($day['slots']) - 3 }} more
                            </button>
                        @endif



                        @if (empty($day['slots']) && $day['date']->month === $month)
                            <span class="block mt-2 text-[11px] text-gray-400">
                                No availability
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach


        </div>


    </div>

    <div x-data x-show="$wire.showModal" x-cloak x-on:keydown.escape.window="$wire.closeModal()"
        class="fixed inset-0 z-50 flex items-center justify-center px-4">
        {{-- Backdrop --}}
        <div class="fixed inset-0 transition-opacity bg-gray-900/50" x-on:click="$wire.closeModal()"
            x-show="$wire.showModal" x-transition.opacity></div>

        {{-- Panel --}}
        <div class="relative w-full max-w-md p-6 bg-white shadow-xl rounded-xl" x-show="$wire.showModal" x-transition>
            <div class="flex items-start justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-800">
                    @if ($selectedDate)
                        {{ \Carbon\Carbon::parse($selectedDate)->format('l, F j, Y') }}
                    @endif
                </h3>

                <button type="button" wire:click="closeModal" class="text-gray-400 hover:text-gray-600">
                    <x-heroicon-m-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="space-y-2">
                @foreach ($selectedSlots ?? [] as $slot)
                    <button type="button"
                        class="w-full px-3 py-2 text-sm font-medium border rounded-lg text-sky-700 bg-sky-50 border-sky-100 hover:bg-sky-500 hover:text-white">
                        {{ $slot }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
