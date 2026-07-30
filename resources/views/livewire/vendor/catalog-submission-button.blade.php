<div>
    @php
        $stats = $this->catalogItemStats;
        $pendingSubmission = $this->existingPendingSubmission;
    @endphp

    {{-- Catalog completeness summary --}}
    @if ($stats)
        <div class="p-5 border rounded-lg bg-slate-50 border-slate-200">
            <h3 class="text-sm font-semibold tracking-wide uppercase text-slate-600">Catalog Status</h3>
            <dl class="grid grid-cols-4 gap-3 mt-3">
                <div class="p-3 text-center bg-white border rounded-lg border-slate-200">
                    <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">Total Products</dt>
                    <dd class="mt-1 text-xl font-semibold text-slate-900">{{ $stats['total'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-emerald-200">
                    <dt class="text-xs font-medium tracking-wide uppercase text-emerald-700">Complete</dt>
                    <dd class="mt-1 text-xl font-semibold text-emerald-700">{{ $stats['complete'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-amber-200">
                    <dt class="text-xs font-medium tracking-wide uppercase text-amber-700">Incomplete</dt>
                    <dd class="mt-1 text-xl font-semibold text-amber-700">{{ $stats['incomplete'] }}</dd>
                </div>
                <div class="p-3 text-center bg-white border rounded-lg border-slate-200">
                    <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">% Complete</dt>
                    <dd
                        class="mt-1 text-xl font-semibold {{ $stats['completeness_percent'] >= 80 ? 'text-emerald-600' : 'text-amber-600' }}">
                        {{ $stats['completeness_percent'] }}%
                    </dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-slate-400">
                Incomplete items will be excluded from the VIT upload. Only complete items (acceptable or excellent) are
                submitted for review.
            </p>
        </div>
    @endif

    {{-- Review request section --}}
    @if ($pendingSubmission)
        <div class="p-4 mt-4 border rounded-lg bg-sky-50 border-sky-200">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-sky-600 shrink-0" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <div class="flex-1">
                    <div class="text-sm text-sky-800">
                        <p class="font-semibold">Review requested</p>
                        <p class="mt-1">Your catalog has been submitted</p>
                    </div>
                    <button wire:click="withdrawReview({{ $pendingSubmission->id }})" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-4 py-2 mt-3 text-sm font-medium transition bg-white border rounded-lg text-slate-700 border-slate-300 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="withdrawReview">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 15 9.75 15M9 18.75 9.75 18.75M15 15 15.75 15M15 18.75 15.75 18.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            Withdraw Submission
                        </span>
                        <span wire:loading wire:target="withdrawReview">Withdrawing&hellip;</span>
                    </button>
                </div>
            </div>
        </div>
    @elseif ($stats && $stats['complete'] > 0)
        <div class="flex flex-col items-center gap-3 mt-6 sm:flex-row sm:justify-start">
            <button wire:click="requestReview" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="requestReview">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Submit catalog
                </span>
                <span wire:loading wire:target="requestReview">Submitting&hellip;</span>
            </button>
        </div>
        {{-- @elseif ($stats && $stats['complete'] === 0)
        <div class="p-4 mt-4 border rounded-lg bg-amber-50 border-amber-200">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                <div class="text-sm text-amber-800">
                    <p class="font-semibold">No complete items</p>
                    <p class="mt-1">Your catalog has no complete items to submit for review. Please
                        upload a catalog with valid data first.</p>
                </div>
            </div>
        </div> --}}
    @endif

    @error('review')
        <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
    @enderror
</div>
