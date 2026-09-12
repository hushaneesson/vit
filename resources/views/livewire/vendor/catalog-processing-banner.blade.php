<div wire:poll.2s>
    @if ($this->activeUpload)
        @php $upload = $this->activeUpload; @endphp
        <div class="p-4 mb-6 border border-sky-200 rounded-xl bg-sky-50">
            <div class="flex items-start gap-3">
                <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-sky-100">
                    <svg class="w-4 h-4 animate-spin text-sky-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-semibold text-sky-900">Update in progress</h3>
                    <p class="mt-1 text-sm text-sky-700">
                        This catalog is currently being updated.
                        You can continue using the system while the update completes.
                    </p>
                    @if ($upload->total_rows > 0)
                        <div class="mt-3">
                            @php
                                $processed = $upload->success_rows + $upload->invalid_rows;
                                $percent = min(100, round(($processed / $upload->total_rows) * 100));
                            @endphp
                            <div class="flex items-center justify-between text-xs text-sky-600">
                                <span>Processing {{ number_format($upload->total_rows) }}  records</span>
                                <span>{{ $percent }}%</span>
                            </div>
                            <div class="w-full h-2 mt-1 rounded-full bg-sky-200">
                                <div class="h-2 rounded-full bg-sky-600 transition-all duration-300"
                                    style="width: {{ $percent }}%"></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($showCompletionNotification && $completedUpload)
        <div class="p-4 mb-6 border border-emerald-200 rounded-xl bg-emerald-50">
            <div class="flex items-start gap-3">
                <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-emerald-100">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-semibold text-emerald-900">Catalog update complete</h3>
                    <p class="mt-1 text-sm text-emerald-700">
                        @if ($completedUpload->invalid_rows > 0)
                            The catalog finished updating with {{ number_format($completedUpload->invalid_rows) }}
                            row(s) that need attention.
                        @else
                            The catalog has finished updating successfully.
                        @endif
                        You can now view the import report.
                    </p>
                    <div class="flex items-center gap-3 mt-3">
                        <button type="button" wire:click="viewReport"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white rounded-lg bg-emerald-600 hover:bg-emerald-700 transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zm3.75 11.625a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                            View import report
                        </button>
                        <button type="button" wire:click="dismissCompletionNotification"
                            class="px-3 py-1.5 text-sm font-medium transition rounded-lg text-emerald-700 hover:bg-emerald-100">
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
