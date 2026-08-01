<div>
    @php
        $stats = $this->catalogItemStats;
        $pendingSubmission = $this->existingPendingSubmission;
    @endphp

    {{-- Catalog completeness summary --}}
    @if ($stats)
        <div class="p-4 bg-white rounded-lg shadow-lg sm:p-5">
            <h3 class="text-sm font-semibold tracking-wide uppercase text-slate-600">Catalog Status</h3>
            <dl class="grid gap-3 mt-3 md:grid-cols-2 lg:grid-cols-4">
                <div class="p-3 text-center bg-white border rounded-lg border-slate-400">
                    <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">Total Products</dt>
                    <dd class="mt-1 text-xl font-semibold text-slate-900">{{ $stats['total'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-emerald-400">
                    <dt class="text-xs font-medium tracking-wide uppercase text-emerald-600">Complete</dt>
                    <dd class="mt-1 text-xl font-semibold text-emerald-600">{{ $stats['complete'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-amber-400">
                    <dt class="text-xs font-medium tracking-wide uppercase text-amber-600">Incomplete</dt>
                    <dd class="mt-1 text-xl font-semibold text-amber-600">{{ $stats['incomplete'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-slate-400">
                    <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">% Complete</dt>
                    <dd
                        class="mt-1 text-xl font-semibold {{ $stats['completeness_percent'] >= 80 ? 'text-emerald-600' : 'text-amber-600' }}">
                        {{ $stats['completeness_percent'] }}%
                    </dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-slate-400">
                Incomplete items will be excluded from the VIT upload. Only complete items (acceptable or excellent) are
                submitted.
            </p>
        </div>
    @endif
    {{-- Catalog submission section --}}
    @if ($pendingSubmission)
        <div
            class="flex flex-col gap-3 p-4 mt-4 border rounded-lg sm:flex-row sm:items-center sm:justify-between bg-sky-50 border-sky-200">
            <div class="flex items-center min-w-0 gap-3">
                <svg class="w-5 h-5 text-sky-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-sky-700">
                    <span class="font-semibold">Catalog submitted</span>
                </p>
            </div>
            <button wire:click="withdrawSubmission({{ $pendingSubmission->id }})" wire:loading.attr="disabled"
                class="btn btn-gray shrink-0">
                <span class="flex items-center gap-1" wire:loading.remove wire:target="withdrawSubmission">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 15 9.75 15M9 18.75 9.75 18.75M15 15 15.75 15M15 18.75 15.75 18.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Withdraw Submission
                </span>
                <span wire:loading wire:target="withdrawSubmission">Withdrawing&hellip;</span>
            </button>
        </div>
    @elseif ($stats && $stats['complete'] > 0)
        <div class="flex flex-col items-start gap-3 mt-6 sm:flex-row">
            <button wire:click="submitCatalog" wire:loading.attr="disabled" class="btn btn-success">
                <span class="flex items-center gap-1" wire:loading.remove wire:target="submitCatalog">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Submit Catalog
                </span>
                <span wire:loading wire:target="submitCatalog">Submitting&hellip;</span>
            </button>
        </div>
    @endif

    {{-- @error('review')
        <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
    @enderror --}}
</div>
