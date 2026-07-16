<div>
    <div class="max-w-4xl px-4 py-10 mx-auto catalog-upload-mapper">

        {{-- STEP RAIL with friendly descriptions --}}
        @php
            $steps = [
                'upload' => ['label' => 'Upload', 'desc' => 'Choose your file'],
                'mapping' => ['label' => 'Map Columns', 'desc' => 'Match to VIT fields'],
                'processing' => ['label' => 'Process', 'desc' => 'We handle the rest'],
                'summary' => ['label' => 'Done', 'desc' => 'Review results'],
            ];
            $stepKeys = array_keys($steps);
            $currentIndex = array_search($step, $stepKeys) !== false ? array_search($step, $stepKeys) : 0;
        @endphp

        @unless ($step === 'error')
            <ol class="flex items-center mb-10">
                @foreach ($steps as $key => $info)
                    @php
                        $isDone = array_search($key, $stepKeys) < $currentIndex;
                        $isCurrent = $key === $step;
                    @endphp
                    <li class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1 shrink-0">
                            <span @class([
                                'flex items-center justify-center w-9 h-9 rounded-full text-sm font-semibold border-2 shrink-0 transition-colors',
                                'bg-emerald-600 border-emerald-600 text-white' => $isDone,
                                'bg-white border-slate-900 text-slate-900' => $isCurrent,
                                'bg-white border-slate-300 text-slate-400' => !$isDone && !$isCurrent,
                            ])>
                                @if ($isDone)
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <span @class([
                                'text-xs font-semibold whitespace-nowrap',
                                'text-slate-900' => $isCurrent || $isDone,
                                'text-slate-400' => !$isCurrent && !$isDone,
                            ])>{{ $info['label'] }}</span>
                            <span @class([
                                'text-[10px] whitespace-nowrap hidden sm:block',
                                'text-slate-400' => $isCurrent || $isDone,
                                'text-slate-300' => !$isCurrent && !$isDone,
                            ])>{{ $info['desc'] }}</span>
                        </div>
                        @if (!$loop->last)
                            <div @class([
                                'flex-1 h-0.5 mx-3 -mt-7 sm:-mt-9',
                                'bg-emerald-500' => $isDone,
                                'bg-slate-200' => !$isDone,
                            ])></div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endunless

        {{-- ============================================================ --}}
        {{-- STEP 1: Upload                                                --}}
        {{-- ============================================================ --}}
        @if ($step === 'upload')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <h2 class="text-xl font-semibold text-slate-900">Upload your product catalog</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Upload your file exactly as it is. We'll help you match the columns in the next step.
                </p>

                <div class="mt-6">
                    <label for="catalog-name" class="block text-sm font-medium text-slate-700">
                        Catalog Name <span class="text-rose-500">*</span>
                    </label>
                    <input id="catalog-name" type="text" wire:model="catalogName"
                        placeholder="e.g. Q3 2026 Product Catalog"
                        class="block w-full px-3 py-2 mt-1 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                    @error('catalogName')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Drop zone --}}
                <label for="catalog-file" @class([
                    'relative flex flex-col items-center justify-center gap-2 px-6 py-12 mt-4 text-center transition border-2 border-dashed rounded-lg cursor-pointer',
                    'border-emerald-400 bg-emerald-50/40' => $file,
                    'border-slate-300 hover:border-slate-400 hover:bg-slate-50' => !$file,
                ])>
                    @if ($file)
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </div>
                        <span class="text-sm font-semibold text-slate-900">{{ $file->getClientOriginalName() }}</span>
                        <span class="text-xs text-slate-500">Click to choose a different file</span>
                    @else
                        <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5" />
                        </svg>
                        <span class="text-sm font-medium text-slate-900">Click to browse, or drag a file here</span>
                        <span class="text-xs text-slate-500">CSV, XLS, or XLSX &middot; up to 50MB</span>
                    @endif

                    <input id="catalog-file" type="file" wire:model="file" accept=".csv,.xls,.xlsx"
                        class="sr-only" />
                </label>

                <div wire:loading wire:target="file" class="flex items-center gap-2 mt-3 text-sm text-slate-500">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    Reading file&hellip;
                </div>

                @error('file')
                    <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <p class="mt-3 text-xs text-slate-400">
                    Your catalog will be named
                    &ldquo;{{ $catalogName ?: $file?->getClientOriginalName() ?: 'Untitled' }}&rdquo;
                </p>

                <button wire:click="uploadFile" wire:loading.attr="disabled" wire:target="uploadFile"
                    @disabled(!$file)
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-4 text-sm font-semibold text-white rounded-lg disabled:opacity-40 disabled:cursor-not-allowed transition
                           {{ $file ? 'bg-slate-900 hover:bg-slate-800' : 'bg-slate-400' }}">
                    <span wire:loading.remove wire:target="uploadFile">Continue to mapping &rarr;</span>
                    <span wire:loading wire:target="uploadFile">Processing file&hellip;</span>
                </button>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- STEP 2: Column mapping                                        --}}
        {{-- ============================================================ --}}
        @if ($step === 'mapping')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-xl font-semibold text-slate-900">Match your columns</h2>
                        <p class="py-2 mt-1 text-sm text-slate-500">
                            <span class="block">Tell us which column in your file matches ours.</span>
                            <span class="block">
                                Fields marked <span class="font-semibold text-rose-600">*</span> are required.
                            </span>
                        </p>
                    </div>
                    <button wire:click="$set('mapping', @js(array_fill(0, count($columns), null)))"
                        class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-slate-500 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 shrink-0 transition"
                        title="Clear all column mappings and start over">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                        </svg>
                        Reset all
                    </button>
                </div>

                {{-- Mapping progress --}}
                @php
                    $totalRequired = $this->catalogFields->where('requirement_type', 'required')->count();
                    $mappedRequired = $totalRequired - $this->unmappedRequiredFields->count();
                    $progressPercent = $totalRequired > 0 ? round(($mappedRequired / $totalRequired) * 100) : 0;
                @endphp
                <div class="flex items-center gap-3 mt-4">
                    <div class="flex-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500 {{ $mappedRequired === $totalRequired ? 'bg-emerald-500' : 'bg-slate-500' }}"
                            style="width: {{ $progressPercent }}%"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">
                        {{ $mappedRequired }} of {{ $totalRequired }} required fields
                    </span>
                </div>

                <div class="mt-4 overflow-hidden border rounded-lg border-slate-200">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-xs font-semibold tracking-wide uppercase bg-slate-50 text-slate-500">
                                <th class="p-3 text-left border-b border-slate-200">Your Column</th>
                                <th class="p-3 text-left border-b border-slate-200">Sample Value</th>
                                <th class="p-3 text-left border-b border-slate-200 w-80">Maps To</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($columns as $index => $columnName)
                                @php $currentSelection = $this->mapping[$index] ?? null; @endphp
                                <tr @class([
                                    'hover:bg-slate-50/60 transition',
                                    'bg-indigo-50/30' => !empty($suggestedIndexes[$index]),
                                ])>
                                    <td class="p-3 font-medium text-slate-900">{{ $columnName }}</td>
                                    <td class="p-3 font-mono text-xs text-slate-500 max-w-[140px] truncate"
                                        title="{{ $sampleRows[0][$index] ?? '' }}">
                                        {{ $sampleRows[0][$index] ?? '—' }}
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <select wire:model.live="mapping.{{ $index }}"
                                                class="w-full px-2.5 py-1.5 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                <option value="">— Skip this column</option>
                                                @foreach ($this->availableFieldsFor($index) as $field)
                                                    <option value="{{ $field->field_key }}"
                                                        title="{{ $field->description ?? '' }}">
                                                        {{ $field->web_app_label }}
                                                        @if ($field->requirement_type === 'required')
                                                            *
                                                        @endif
                                                    </option>
                                                @endforeach
                                            </select>

                                            @if (!empty($suggestedIndexes[$index]) && !$currentSelection)
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 shrink-0 border border-emerald-200"
                                                    title="We matched this based on your column name — please confirm it's correct">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                                                    </svg>
                                                    Suggested
                                                </span>
                                            @elseif (!empty($suggestedIndexes[$index]) && $currentSelection)
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-50 text-indigo-700 shrink-0 border border-indigo-200">
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd"
                                                            d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
                                                            clip-rule="evenodd" />
                                                    </svg>
                                                    Confirmed
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->unmappedRequiredFields->isNotEmpty())
                    <div
                        class="flex items-start gap-2 p-3 mt-4 text-sm border rounded-lg bg-amber-50 text-amber-800 border-amber-200">
                        <svg class="w-5 h-5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span>
                            <strong>Still needed:</strong> {{ $this->unmappedRequiredFields->join(', ') }}
                        </span>
                    </div>
                @endif

                <div class="pt-5 mt-6 border-t border-slate-100">
                    <label class="flex items-center gap-2 text-sm cursor-pointer text-slate-700">
                        <input type="checkbox" wire:model.live="saveAsTemplate"
                            class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                        Remember this mapping for next time
                    </label>

                    @if ($saveAsTemplate)
                        <input type="text" wire:model="templateName"
                            placeholder="Name this template (e.g. Our standard export)"
                            class="w-full max-w-sm px-3 py-2 mt-3 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                    @endif
                </div>

                @error('mapping')
                    <p class="mt-4 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <div class="flex items-center justify-between mt-6">
                    <button wire:click="startOver"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Start over
                    </button>

                    <button wire:click="confirmMapping" wire:loading.attr="disabled" @disabled($this->unmappedRequiredFields->isNotEmpty())
                        class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold text-white rounded-lg disabled:opacity-40 disabled:cursor-not-allowed transition
                               {{ $this->unmappedRequiredFields->isEmpty() ? 'bg-slate-900 hover:bg-slate-800' : 'bg-slate-400' }}">
                        <span wire:loading.remove wire:target="confirmMapping">
                            Process file &rarr;
                        </span>
                        <span wire:loading wire:target="confirmMapping">Starting&hellip;</span>
                    </button>
                </div>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- STEP 3: Processing                                            --}}
        {{-- ============================================================ --}}
        @if ($step === 'processing')
            <div wire:poll.2s="refreshStatus" class="p-10 text-center bg-white border rounded-xl border-slate-200">
                <div class="flex items-center justify-center mx-auto rounded-full w-14 h-14 bg-slate-100">
                    <svg class="w-6 h-6 animate-spin text-slate-900" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-slate-900">Processing your file&hellip;</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-500">
                    This may take a few minutes for larger files. You can leave this page &mdash; we'll keep working
                    in the background.
                </p>

                {{-- Live counts — update as the poll refreshes --}}
                <div class="flex items-center justify-center gap-6 mt-6">
                    @if ($progress['total_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-slate-400">Total</div>
                            <div class="text-lg font-semibold text-slate-900">{{ $progress['total_rows'] }}</div>
                        </div>
                    @endif
                    @if ($progress['success_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-emerald-600">Created</div>
                            <div class="text-lg font-semibold text-emerald-700">{{ $progress['success_rows'] }}</div>
                        </div>
                    @endif
                    @if ($progress['updated_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-amber-600">Updated</div>
                            <div class="text-lg font-semibold text-amber-700">{{ $progress['updated_rows'] }}</div>
                        </div>
                    @endif
                    @if ($progress['skipped_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-sky-600">Unchanged</div>
                            <div class="text-lg font-semibold text-sky-700">{{ $progress['skipped_rows'] }}</div>
                        </div>
                    @endif
                    @if ($progress['error_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-rose-600">Errors</div>
                            <div class="text-lg font-semibold text-rose-700">{{ $progress['error_rows'] }}</div>
                        </div>
                    @endif
                </div>

                <div class="w-full h-2 mt-6 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-slate-900 animate-pulse" style="width: 60%"></div>
                </div>

                <p class="mt-4 text-xs text-slate-400">
                    We'll show your results automatically when processing is complete.
                </p>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- STEP 4: Summary                                               --}}
        {{-- ============================================================ --}}
        @if ($step === 'summary')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-emerald-100">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-center text-slate-900">Upload complete</h2>

                {{-- Visual summary cards --}}
                <dl class="grid grid-cols-4 gap-3 mt-6">
                    <div class="p-4 text-center border rounded-lg border-slate-200 bg-slate-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">Total Rows</dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-900">{{ $progress['total_rows'] }}</dd>
                    </div>
                    <div class="p-4 text-center border rounded-lg border-emerald-200 bg-emerald-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-emerald-700">Created</dt>
                        <dd class="mt-1 text-2xl font-semibold text-emerald-700">{{ $progress['success_rows'] }}</dd>
                    </div>
                    <div class="p-4 text-center border rounded-lg border-amber-200 bg-amber-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-amber-700">
                            Updated
                            @if ($progress['skipped_rows'] > 0)
                                <span class="ml-1 text-xs font-normal text-amber-500">({{ $progress['skipped_rows'] }}
                                    unchanged)</span>
                            @endif
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold text-amber-700">{{ $progress['updated_rows'] }}</dd>
                    </div>
                    <div class="p-4 text-center border rounded-lg border-rose-200 bg-rose-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-rose-700">Errors</dt>
                        <dd class="mt-1 text-2xl font-semibold text-rose-700">{{ $progress['error_rows'] }}</dd>
                    </div>
                </dl>

                {{-- Items that were already up to date --}}
                @if ($progress['skipped_rows'] > 0 && $progress['skipped_item_names'])
                    <div class="p-3 mt-4 text-sm border rounded-lg bg-sky-50 text-sky-800 border-sky-200">
                        <span class="font-semibold">{{ $progress['skipped_rows'] }} item(s) already up to date:</span>
                        <ul class="mt-1 ml-4 overflow-y-auto list-disc max-h-32">
                            @foreach ($progress['skipped_item_names'] as $name)
                                <li>{{ $name }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Failed rows table --}}
                @if ($progress['error_rows'] > 0 && $this->failedRows->isNotEmpty())
                    <div class="mt-6 overflow-hidden border rounded-lg border-rose-200">
                        <div class="flex items-center justify-between px-4 py-2.5 bg-rose-50">
                            <span class="text-xs font-semibold tracking-wide uppercase text-rose-700">
                                Failed rows &mdash; <span class="font-normal lowercase">these were skipped</span>
                            </span>
                            <span class="text-xs text-rose-500">{{ $progress['error_rows'] }} row(s)</span>
                        </div>
                        <div class="overflow-y-auto max-h-48">
                            <table class="w-full text-sm border-collapse">
                                <thead class="sticky top-0 bg-slate-50">
                                    <tr class="text-xs font-semibold tracking-wide uppercase text-slate-500">
                                        <th class="p-2.5 pl-4 text-left border-b border-slate-200">Row</th>
                                        <th class="p-2.5 text-left border-b border-slate-200">Reason</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rose-100">
                                    @foreach ($this->failedRows as $failedRow)
                                        <tr class="hover:bg-rose-50/40">
                                            <td class="p-2.5 pl-4 font-mono text-xs text-slate-900">
                                                {{ $failedRow->row_number }}</td>
                                            <td class="p-2.5 text-xs text-rose-700">
                                                {{ is_array($failedRow->errors) ? implode('; ', $failedRow->errors) : $failedRow->errors }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-center gap-3 mt-6">
                    <a href="{{ route('vendor.catalog.index') }}"
                        class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-slate-900 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6.75c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6.75a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z" />
                        </svg>
                        View my catalog
                    </a>

                    <button wire:click="startOver"
                        class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white bg-slate-900 rounded-lg hover:bg-slate-800 transition">
                        Upload another file
                    </button>
                </div>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- ERROR STATE                                                   --}}
        {{-- ============================================================ --}}
        @if ($step === 'error')
            <div class="p-8 text-center bg-white border rounded-xl border-rose-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-rose-100">
                    <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-rose-600">Something went wrong</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-600">
                    {{ $progress['failure_reason'] ?? 'Please try again or contact support.' }}
                </p>
                <button wire:click="startOver"
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-slate-900 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                    Try again
                </button>
            </div>
        @endif

    </div>
</div>
