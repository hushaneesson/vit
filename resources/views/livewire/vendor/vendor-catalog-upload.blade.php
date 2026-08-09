<div>
    <div class="max-w-4xl px-4 py-6 mx-auto sm:py-10 catalog-upload-mapper">

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
            <ol class="flex items-center mb-8 sm:mb-10">
                @foreach ($steps as $key => $info)
                    @php
                        $isDone = array_search($key, $stepKeys) < $currentIndex;
                        $isCurrent = $key === $step;
                    @endphp
                    <li class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1 shrink-0">
                            <span @class([
                                'flex items-center justify-center w-7 h-7 sm:w-9 sm:h-9 rounded-full text-xs sm:text-sm font-semibold border-2 shrink-0 transition-colors',
                                'bg-emerald-600 border-emerald-600 text-white' => $isDone,
                                'bg-white border-slate-900 text-slate-900' => $isCurrent,
                                'bg-white border-slate-300 text-slate-400' => !$isDone && !$isCurrent,
                            ])>
                                @if ($isDone)
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <span @class([
                                'text-[10px] sm:text-xs font-semibold whitespace-nowrap',
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
                                'flex-1 h-0.5 mx-1.5 sm:mx-3 -mt-6 sm:-mt-9',
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
            <div class="p-4 bg-white border rounded-xl sm:p-8 border-slate-200">
                <h2 class="text-lg font-semibold sm:text-xl text-slate-900">Upload your product catalog</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Upload your file exactly as it is. We'll help you match the columns in the next step.
                </p>

                {{-- Drop zone --}}
                <label for="catalog-file" @class([
                    'relative flex flex-col items-center justify-center gap-2 px-4 py-8 mt-4 text-center transition border-2 border-dashed rounded-lg cursor-pointer sm:px-6 sm:py-12',
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
                        <span
                            class="px-2 text-sm font-semibold break-all text-slate-900">{{ $file->getClientOriginalName() }}</span>
                        <span class="text-xs text-slate-500">Click to choose a different file</span>
                    @else
                        <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5" />
                        </svg>
                        <span class="text-sm font-medium text-center text-slate-900">Click to browse, or drag a file
                            here</span>
                        <span class="text-xs text-slate-500">CSV, XLS, or XLSX &middot; up to 50MB</span>
                    @endif

                    <input id="catalog-file" type="file" wire:model="file" accept=".csv,.xls,.xlsx"
                        class="sr-only" />
                </label>

                <div class="flex flex-wrap items-center gap-4 mt-4">
                    <button wire:click="uploadFile" wire:loading.attr="disabled" wire:target="uploadFile"
                        @disabled(!$file)
                        class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg disabled:opacity-40 disabled:cursor-not-allowed transition sm:w-auto
                               {{ $file ? 'bg-slate-900 hover:bg-slate-800' : 'bg-slate-400' }}">
                        <span wire:loading.remove wire:target="uploadFile">Continue to mapping &rarr;</span>
                        <span wire:loading wire:target="uploadFile">Processing file&hellip;</span>
                    </button>

                    <div wire:loading wire:target="file" class="flex items-center gap-2 text-sm text-slate-500">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                            </path>
                        </svg>
                        Reading file &hellip;
                    </div>
                </div>

                @error('file')
                    <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
                @enderror


            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- STEP 2: Column mapping                                        --}}
        {{-- ============================================================ --}}
        @if ($step === 'mapping')
            <div class="p-4 bg-white border rounded-xl sm:p-8 border-slate-200">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold sm:text-xl text-slate-900">Match your columns</h2>
                        <p class="py-2 mt-1 text-sm text-slate-500">
                            <span class="block">Map the columns from your file to the VIT fields below. Only map the
                                columns that exist in your file — anything missing can be completed later from the
                                Catalog Item List.</span>
                            <span class="block">
                                Fields marked <span class="font-semibold text-amber-600">*</span> are recommended for
                                VIT submission. Mapping more fields now will reduce manual editing later.
                            </span>
                        </p>
                    </div>
                    <button wire:click="$set('mapping', @js($this->catalogFields->pluck('field_key')->mapWithKeys(fn($k) => [$k => null])->toArray()))" wire:loading.attr="disabled"
                        class="flex items-center self-start gap-1 px-3 py-1.5 text-xs font-medium text-slate-500 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 shrink-0 transition disabled:opacity-50 disabled:cursor-not-allowed"
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
                    $totalRecommended = $this->catalogFields->where('requirement_type', 'required')->count();
                    $mappedRecommended = $totalRecommended - $this->unmappedRequiredFields()->count();
                    $progressPercent =
                        $totalRecommended > 0 ? round(($mappedRecommended / $totalRecommended) * 100) : 0;
                @endphp
                <div class="flex items-center gap-3 mt-4">
                    <div class="flex-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500 {{ $mappedRecommended === $totalRecommended ? 'bg-emerald-500' : 'bg-slate-500' }}"
                            style="width: {{ $progressPercent }}%"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">
                        {{ $mappedRecommended }} of {{ $totalRecommended }} recommended fields
                    </span>
                </div>

                {{-- Scrolls horizontally on narrow screens instead of squeezing columns unreadably --}}
                <div class="mt-4 overflow-x-auto border rounded-lg border-slate-200">
                    <table class="w-full min-w-[640px] text-sm border-collapse">
                        <thead>
                            <tr class="text-xs font-semibold tracking-wide uppercase bg-slate-50 text-slate-500">
                                <th class="p-3 text-left border-b border-slate-200">VIT Field</th>
                                <th class="p-3 text-left border-b border-slate-200">Description</th>
                                <th class="p-3 text-left border-b border-slate-200">Priority</th>
                                <th class="p-3 text-left border-b border-slate-200 w-80">Uploaded File Column</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->catalogFields as $field)
                                @php
                                    $currentSelection = $this->mapping[$field->field_key] ?? null;
                                    $isSuggested = !empty($this->suggestedIndexes[$field->field_key]);
                                @endphp
                                <tr wire:key="mapping-row-{{ $field->field_key }}" @class([
                                    'hover:bg-slate-50/60 transition',
                                    'bg-indigo-50/30' => $isSuggested,
                                ])>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-slate-900">{{ $field->web_app_label }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        @if ($field->description)
                                            <p class="text-xs break-words text-slate-500">{{ $field->description }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="p-3">
                                        @if ($field->requirement_type === 'required')
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                                Recommended <span class="text-amber-400">*</span>
                                            </span>
                                        @else
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-slate-50 text-slate-500 border border-slate-200">
                                                Optional
                                            </span>
                                        @endif
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <select wire:key="mapping-select-{{ $field->field_key }}"
                                                wire:model.live="mapping.{{ $field->field_key }}"
                                                class="w-full px-2.5 py-1.5 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                <option wire:key="mapping-opt-{{ $field->field_key }}-empty"
                                                    value="">— Do not import</option>
                                                @foreach ($this->availableColumnsFor($field->field_key) as $col)
                                                    <option
                                                        wire:key="mapping-opt-{{ $field->field_key }}-{{ $col->index }}"
                                                        value="{{ $col->index }}">
                                                        {{ $col->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($isSuggested && $currentSelection === null)
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0"
                                                    title="Suggested match based on column name">
                                                    Suggested
                                                </span>
                                            @elseif ($isSuggested && $currentSelection !== null)
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 shrink-0">
                                                    Suggested
                                                </span>
                                            @endif
                                        </div>
                                        @if ($currentSelection !== null)
                                            @php
                                                $sampleValue = collect($sampleRows)->first(
                                                    fn($row) => trim((string) ($row[$currentSelection] ?? '')) !== '',
                                                );
                                            @endphp
                                            <div class="mt-1.5 text-xs text-slate-500">
                                                Example: {{ $sampleValue[$currentSelection] ?? '—' }}
                                            </div>
                                        @endif
                                        @if ($field->is_multi_value && $currentSelection !== null)
                                            <div class="mt-1.5">
                                                <label class="block text-xs font-medium text-slate-600">
                                                    Separator in your file:
                                                </label>
                                                <select wire:model.live="separators.{{ $field->field_key }}"
                                                    class="mt-0.5 px-2 py-1 text-sm border rounded border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                    <option value=",">Comma (,)</option>
                                                    <option value=";">Semicolon (;)</option>
                                                    <option value="|">Pipe (|)</option>
                                                </select>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->unmappedRequiredFields()->isNotEmpty())
                    <div
                        class="flex items-start gap-2 p-3 mt-4 text-sm border rounded-lg bg-sky-50 text-sky-800 border-sky-200">
                        <svg class="w-5 h-5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m11.25 11.25.04-.02a.75.75 0 0 1 1.063.452l.255.766a.75.75 0 0 0 1.063.452l.04-.02a.75.75 0 0 1 1.063.452l.255.766a.75.75 0 0 0 1.063.452l.04-.02a.75.75 0 0 1 1.063.452l.255.766a.75.75 0 0 0 1.063.452l.04-.02a.75.75 0 0 1 1.063.452l.255.766M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <span>
                            <strong>Not in this file:</strong> {{ $this->unmappedRequiredFields()->join(', ') }}.
                            You can continue without these — they can be completed later from the Catalog Item List
                            before requesting review.
                        </span>
                    </div>
                @endif

                @error('mapping')
                    <p class="mt-4 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <div class="flex flex-col-reverse gap-3 mt-6 sm:flex-row sm:items-center sm:justify-between">
                    <button wire:click="startOver" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Start over
                    </button>

                    <button wire:click="confirmMapping" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-2 px-6 py-2.5 text-sm font-semibold text-white rounded-lg disabled:opacity-40 disabled:cursor-not-allowed transition bg-slate-900 hover:bg-slate-800">
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
            <div wire:poll.2s="refreshStatus"
                class="p-6 text-center bg-white border rounded-xl sm:p-10 border-slate-200">
                <div class="flex items-center justify-center mx-auto rounded-full w-14 h-14 bg-slate-100">
                    <svg class="w-6 h-6 animate-spin text-slate-900" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold sm:text-xl text-slate-900">Processing your file&hellip;</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-500">
                    This may take a few minutes for larger files. You can leave this page &mdash; we'll keep working
                    in the background.
                </p>

                {{-- Live counts — update as the poll refreshes --}}
                <div class="flex flex-wrap items-center justify-center gap-4 mt-6 sm:gap-6">
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
                    @if ($progress['invalid_rows'] > 0)
                        <div class="text-center">
                            <div class="text-xs font-medium uppercase text-rose-600">Errors</div>
                            <div class="text-lg font-semibold text-rose-700">{{ $progress['invalid_rows'] }}</div>
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
            <div class="p-4 bg-white border rounded-xl sm:p-8 border-slate-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-emerald-100">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-center sm:text-xl text-slate-900">Upload complete</h2>

                {{-- Visual summary cards — only show a card when its value > 0 --}}
                <dl class="flex flex-wrap gap-3 mt-6">
                    <div
                        class="p-4 text-center border rounded-lg border-slate-200 bg-slate-50 min-w-[100px] sm:min-w-[120px] flex-1">
                        <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">Total Rows</dt>
                        <dd class="mt-1 text-xl font-semibold sm:text-2xl text-slate-900">
                            {{ $progress['total_rows'] }}</dd>
                    </div>
                    @if ($progress['created_rows'] > 0)
                        <div
                            class="p-4 text-center border rounded-lg border-emerald-200 bg-emerald-50 min-w-[100px] sm:min-w-[120px] flex-1">
                            <dt class="text-xs font-medium tracking-wide uppercase text-emerald-700">Created</dt>
                            <dd class="mt-1 text-xl font-semibold sm:text-2xl text-emerald-700">
                                {{ $progress['created_rows'] }}
                            </dd>
                        </div>
                    @endif
                    @if ($progress['updated_rows'] > 0)
                        <div
                            class="p-4 text-center border rounded-lg border-amber-200 bg-amber-50 min-w-[100px] sm:min-w-[120px] flex-1">
                            <dt class="text-xs font-medium tracking-wide uppercase text-amber-700">Updated</dt>
                            <dd class="mt-1 text-xl font-semibold sm:text-2xl text-amber-700">
                                {{ $progress['updated_rows'] }}
                            </dd>
                        </div>
                    @endif
                    @if ($progress['unchanged_rows'] > 0)
                        <div
                            class="p-4 text-center border rounded-lg border-sky-200 bg-sky-50 min-w-[100px] sm:min-w-[120px] flex-1">
                            <dt class="text-xs font-medium tracking-wide uppercase text-sky-700">Unchanged</dt>
                            <dd class="mt-1 text-xl font-semibold sm:text-2xl text-sky-700">
                                {{ $progress['unchanged_rows'] }}
                            </dd>
                        </div>
                    @endif
                    @if ($progress['invalid_rows'] > 0)
                        <div
                            class="p-4 text-center border rounded-lg border-rose-200 bg-rose-50 min-w-[100px] sm:min-w-[120px] flex-1">
                            <dt class="text-xs font-medium tracking-wide uppercase text-rose-700">Invalid</dt>
                            <dd class="mt-1 text-xl font-semibold sm:text-2xl text-rose-700">
                                {{ $progress['invalid_rows'] }}</dd>
                        </div>
                    @endif
                </dl>

                {{-- Warning-only rows: imported successfully but surfaced business-data warnings --}}
                @if ($this->warningRows->isNotEmpty())
                    <div class="mt-6 overflow-hidden border rounded-lg border-amber-200">
                        <div class="flex items-center justify-between px-4 py-2.5 bg-amber-50">
                            <span class="text-xs font-semibold tracking-wide uppercase text-amber-700">
                                Imported with warnings &mdash; <span class="font-normal lowercase">CatalogItem
                                    created/updated</span>
                            </span>
                            <span class="text-xs text-amber-600">{{ $this->warningRows->count() }} row(s)</span>
                        </div>
                        <div class="overflow-x-auto overflow-y-auto max-h-48">
                            <table class="w-full min-w-[420px] text-sm border-collapse">
                                <thead class="sticky top-0 bg-slate-50">
                                    <tr class="text-xs font-semibold tracking-wide uppercase text-slate-500">
                                        <th class="p-2.5 pl-4 text-left border-b border-slate-200">Row</th>
                                        <th class="p-2.5 text-left border-b border-slate-200">Warnings</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-amber-100">
                                    @foreach ($this->warningRows as $warningRow)
                                        <tr class="hover:bg-amber-50/40">
                                            <td class="p-2.5 pl-4 font-mono text-xs text-slate-900">
                                                {{ $warningRow->row_number }}</td>
                                            <td class="p-2.5 text-xs text-amber-700">
                                                @php
                                                    $payload = is_string($warningRow->errors)
                                                        ? json_decode($warningRow->errors, true)
                                                        : $warningRow->errors;
                                                    $warningMessages =
                                                        is_array($payload) && !empty($payload['warnings'])
                                                            ? collect($payload['warnings'])
                                                                ->pluck('message')
                                                                ->implode('; ')
                                                            : (string) $warningRow->errors;
                                                @endphp
                                                {{ $warningMessages }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- Failed rows --}}
                @if ($progress['invalid_rows'] > 0 && $this->failedRows->isNotEmpty())
                    @php
                        $totalMessages = $this->failedRows->count();
                        $showFullTable = $totalMessages <= 10;
                        $emailSent = $this->validationReportEmailed;
                        $emailFailed = $this->validationReportFailed;
                    @endphp

                    {{-- Case 1: 10 or fewer messages — show the full table as before --}}
                    @if ($showFullTable)
                        <div class="mt-6 overflow-hidden border rounded-lg border-rose-200">
                            <div class="flex items-center justify-between px-4 py-2.5 bg-rose-50">
                                <span class="text-xs font-semibold tracking-wide uppercase text-rose-700">
                                    Failed rows &mdash; <span class="font-normal lowercase">these were skipped</span>
                                </span>
                                <span class="text-xs text-rose-500">{{ $totalMessages }} row(s)</span>
                            </div>
                            <div class="overflow-x-auto overflow-y-auto max-h-48">
                                <table class="w-full min-w-[420px] text-sm border-collapse">
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
                                                    @php
                                                        $fbMsgs = is_array($failedRow->errors)
                                                            ? collect($failedRow->errors)
                                                                ->pluck('message')
                                                                ->implode('; ')
                                                            : (string) $failedRow->errors;
                                                    @endphp
                                                    {{ $fbMsgs }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Case 2: More than 10 messages, email sent successfully --}}
                    @elseif ($emailSent)
                        <div class="p-4 mt-6 border rounded-lg bg-sky-50 border-sky-200">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 mt-0.5 text-sky-600 shrink-0" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.981l7.5-4.039a2.25 2.25 0 0 1 2.134 0l7.5 4.039a2.25 2.25 0 0 1 1.183 1.98V19.5Z" />
                                </svg>
                                <div class="text-sm text-sky-800">
                                    <p class="font-semibold">Validation report emailed</p>
                                    <p class="mt-1">
                                        There are <strong>{{ $totalMessages }}</strong> validation messages. To keep
                                        this page readable, only a summary is shown.
                                        A complete validation report has been emailed to
                                        <strong>{{ auth('client')->user()?->email ?? 'your account email' }}</strong>.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Case 3: More than 10 messages, email failed — show first 10 with a notice --}}
                    @else
                        <div class="p-4 mt-6 border rounded-lg bg-amber-50 border-amber-200">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 mt-0.5 text-amber-600 shrink-0" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                                <div class="text-sm text-amber-800">
                                    <p class="font-semibold">Could not email full report</p>
                                    <p class="mt-1">
                                        There are <strong>{{ $totalMessages }}</strong> validation messages. The full
                                        report could not be emailed.
                                        Showing the first 10 below.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 overflow-hidden border rounded-lg border-rose-200">
                            <div class="flex items-center justify-between px-4 py-2.5 bg-rose-50">
                                <span class="text-xs font-semibold tracking-wide uppercase text-rose-700">
                                    Failed rows (first 10) &mdash; <span class="font-normal lowercase">these were
                                        skipped</span>
                                </span>
                                <span class="text-xs text-rose-500">{{ $totalMessages }} total row(s)</span>
                            </div>
                            <div class="overflow-x-auto overflow-y-auto max-h-48">
                                <table class="w-full min-w-[420px] text-sm border-collapse">
                                    <thead class="sticky top-0 bg-slate-50">
                                        <tr class="text-xs font-semibold tracking-wide uppercase text-slate-500">
                                            <th class="p-2.5 pl-4 text-left border-b border-slate-200">Row</th>
                                            <th class="p-2.5 text-left border-b border-slate-200">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-rose-100">
                                        @foreach ($this->failedRows->take(10) as $failedRow)
                                            <tr class="hover:bg-rose-50/40">
                                                <td class="p-2.5 pl-4 font-mono text-xs text-slate-900">
                                                    {{ $failedRow->row_number }}</td>
                                                <td class="p-2.5 text-xs text-rose-700">
                                                    @php
                                                        $fbMsgs2 = is_array($failedRow->errors)
                                                            ? collect($failedRow->errors)
                                                                ->pluck('message')
                                                                ->implode('; ')
                                                            : (string) $failedRow->errors;
                                                    @endphp
                                                    {{ $fbMsgs2 }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endif

                <div
                    class="flex flex-col-reverse gap-3 mt-6 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center">
                    <a href="{{ route('vendor.catalog.index') }}"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold text-slate-900 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6.75c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6.75a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z" />
                        </svg>
                        View my catalog
                    </a>

                    <button wire:click="startOver" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold text-white bg-slate-900 rounded-lg hover:bg-slate-800 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        Upload another file
                    </button>
                </div>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- ERROR STATE                                                   --}}
        {{-- ============================================================ --}}
        @if ($step === 'error')
            <div class="p-6 text-center bg-white border rounded-xl sm:p-8 border-rose-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-rose-100">
                    <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold sm:text-xl text-rose-600">Something went wrong</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-600">
                    {{ $progress['failure_reason'] ?? 'Please try again or contact support.' }}
                </p>
                <button wire:click="startOver" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-slate-900 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition disabled:opacity-50 disabled:cursor-not-allowed sm:w-auto">
                    Try again
                </button>
            </div>
        @endif

    </div>
</div>
