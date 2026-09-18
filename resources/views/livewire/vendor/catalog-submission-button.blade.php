<div>
    @php
        $stats = $this->catalogItemStats;
        $pendingSubmission = $this->existingPendingSubmission;

        if ($pendingSubmission && ! in_array($pendingSubmission->status, [
            \App\Enums\CatalogSubmissionStatus::ReviewRequested,
            \App\Enums\CatalogSubmissionStatus::ReadyForReview,
        ], true)) {
            $pendingSubmission = null;
        }
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
        @php
            $processingStatus = $pendingSubmission->processing_status;
            $isExportReady =
                $processingStatus === 'completed' ||
                $pendingSubmission->status === \App\Enums\CatalogSubmissionStatus::ReadyForReview;
            $isExportFailed = $processingStatus === 'failed';
        @endphp

        {{-- Export failed — clear failure state with a recovery path --}}
        @if ($isExportFailed)
            <div class="flex flex-col gap-3 p-4 mt-4 border rounded-lg sm:flex-row sm:items-center sm:justify-between bg-rose-50 border-rose-200">
                <div class="flex items-start min-w-0 gap-3">
                    <svg class="w-5 h-5 mt-0.5 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div class="min-w-0 text-sm text-rose-800">
                        <p class="font-semibold">We couldn&rsquo;t prepare your catalog for review.</p>
                        <p class="mt-1">
                            You can cancel this submission and submit again. If the problem continues, please
                            contact support.
                        </p>
                    </div>
                </div>
                <button wire:click="withdrawSubmission({{ $pendingSubmission->id }})"
                    wire:loading.attr="disabled" class="btn btn-gray shrink-0">
                    <span class="flex items-center gap-1" wire:loading.remove wire:target="withdrawSubmission">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 9.75 15M9 18.75 9.75 18.75M15 15 15.75 15M15 18.75 15.75 18.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Cancel Submission
                    </span>
                    <span wire:loading wire:target="withdrawSubmission">Canceling&hellip;</span>
                </button>
            </div>
        {{-- Export ready for review — completed state --}}
        @elseif ($isExportReady)
            <div
                class="flex flex-col gap-3 p-4 mt-4 border rounded-lg sm:flex-row sm:items-center sm:justify-between bg-emerald-50 border-emerald-200">
                <div class="flex items-start min-w-0 gap-3">
                    <svg class="w-5 h-5 mt-0.5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <div class="min-w-0 text-sm text-emerald-800">
                        <p class="font-semibold">Your catalog has been submitted.</p>
                        <p class="mt-1">Please note that only items with a status of "Acceptable" and above will be included.</p>
                        <a href="{{ route('vendor.catalog-submissions.download', $pendingSubmission) }}"
                            class="inline-flex items-center gap-1.5 mt-2 font-medium underline text-emerald-700 hover:text-emerald-900">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download your catalog file
                        </a>
                    </div>
                </div>
                <button wire:click="withdrawSubmission({{ $pendingSubmission->id }})"
                    wire:loading.attr="disabled" class="btn btn-gray shrink-0">
                    <span class="flex items-center gap-1" wire:loading.remove wire:target="withdrawSubmission">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 9.75 15M9 18.75 9.75 18.75M15 15 15.75 15M15 18.75 15.75 18.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Cancel Submission
                    </span>
                    <span wire:loading wire:target="withdrawSubmission">Canceling&hellip;</span>
                </button>
            </div>
        {{-- Export being generated — live status while the background job runs --}}
        @else
            <div wire:poll.2s="refreshPendingSubmission"
                class="flex flex-col gap-3 p-4 mt-4 border rounded-lg sm:flex-row sm:items-center sm:justify-between bg-sky-50 border-sky-200">
                <div class="flex items-start min-w-0 gap-3">
                    <svg class="w-5 h-5 mt-0.5 animate-spin text-sky-500 shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    <div class="min-w-0 text-sm text-sky-800">
                        <p class="font-semibold">Preparing your catalog for review&hellip;</p>
                        <p class="mt-1">
                            This may take a few minutes for larger catalogs. You can keep using the system &mdash;
                            we&rsquo;ll update this automatically.
                        </p>
                    </div>
                </div>
                <button wire:click="withdrawSubmission({{ $pendingSubmission->id }})"
                    wire:loading.attr="disabled" class="btn btn-gray shrink-0">
                    <span class="flex items-center gap-1" wire:loading.remove wire:target="withdrawSubmission">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 9.75 15M9 18.75 9.75 18.75M15 15 15.75 15M15 18.75 15.75 18.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Cancel Submission
                    </span>
                    <span wire:loading wire:target="withdrawSubmission">Canceling&hellip;</span>
                </button>
            </div>
        @endif
    @elseif ($stats && $stats['complete'] > 0 && $stats['can_submit'])
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
