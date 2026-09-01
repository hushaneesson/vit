<div>
    <div class="px-4 py-6 mx-auto sm:py-10 catalog-upload-mapper">

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
                {{-- <p class="mt-1 text-sm text-slate-500">
                    Please Upload your file and We'll help you match the columns in the next step.
                </p> --}}

                {{-- Upload requirements notice --}}
                <div class="flex gap-3 p-4 mt-4 border rounded-lg border-sky-200">
                    <div class="flex items-center justify-center flex-shrink-0 rounded-full w-9 h-9">
                        <svg class="w-5 h-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>
                    <div class="text-sm text-slate-700">
                        <p class="font-semibold text-slate-900">Please review before uploading</p>
                        <ul class="pl-4 mt-2 space-y-1.5 list-disc">
                            <li>
                                We only accept files in <span class="font-medium">CSV, XLS, or XLSX</span> format.
                            </li>
                            <li>
                                If a product attribute spans multiple columns (for example, several specification
                                columns), those columns must be positioned consecutively, in a single block. You
                                will select this block as a range in the mapping step.
                            </li>
                            <li>
                                If your file is in a different format, such as PDF or Word, please convert it to
                                CSV or XLSX first. <a href="https://cloudconvert.com" target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-medium underline hover:text-slate-900">CloudConvert</a> is a free
                                online tool that can perform this conversion.
                            </li>
                        </ul>
                    </div>
                </div>

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
                            <span class="block">
                                Match your file columns to the VIT fields below. Only map fields available in your file.
                                Missing fields can be completed later in the Catalog Item List.
                            </span>
                            <span class="block mt-1">
                                Fields marked with <span class="font-medium text-red-600">*</span> are recommended for
                                VIT submission.
                                Mapping more fields now means less manual editing later.
                            </span>
                        </p>
                    </div>

                    <button wire:click="resetMapping" wire:loading.attr="disabled"
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
                    $totalRecommended = $this->catalogFields
                        ->whereIn('requirement_type', ['required', 'recommended'])
                        ->count();
                    $mappedRecommended = $totalRecommended - $this->unmappedRequiredFields()->count();
                    $progressPercent =
                        $totalRecommended > 0 ? round(($mappedRecommended / $totalRecommended) * 100) : 0;
                @endphp

                <div class="flex items-center gap-3 mt-4">
                    <div class="flex-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500 {{ $mappedRecommended === $totalRecommended ? 'bg-emerald-500' : 'bg-slate-500' }}"
                            style="width: {{ $progressPercent }}%">
                        </div>
                    </div>

                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">
                        {{ $mappedRecommended }} of {{ $totalRecommended }} recommended fields
                    </span>
                </div>

                {{-- Scrolls horizontally on narrow screens instead of squeezing columns unreadably --}}
                <div class="mt-4 overflow-x-auto overflow-y-auto border rounded-lg border-slate-200">
                    <table class="w-full min-w-[900px] table-fixed text-sm border-collapse">
                        <thead>
                            <tr class="text-xs font-semibold tracking-wide uppercase bg-slate-50 text-slate-500">
                                <th class="w-1/2 p-3 text-left border-b border-slate-200">VIT Field</th>
                                <th class="w-1/2 p-3 text-left border-b border-slate-200">Uploaded File Column</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->catalogFields as $field)
                                @php
                                    $currentSelection = $this->mapping[$field->field_key] ?? null;
                                    $isSuggested = !empty($this->suggestedIndexes[$field->field_key]);
                                    $availableColumns = $this->availableColumnsFor($field->field_key);
                                    $rangeActive =
                                        $field->is_multi_value &&
                                        (!empty($this->rangeStarts[$field->field_key]) ||
                                            !empty($this->rangeEnds[$field->field_key]));
                                @endphp

                                <tr wire:key="mapping-row-{{ $field->field_key }}" @class([
                                    'hover:bg-slate-50/60 transition',
                                    'bg-indigo-50/30' => $isSuggested,
                                ])>

                                    {{-- VIT Field --}}
                                    <td class="w-1/2 p-3">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-medium text-slate-900">
                                                {{ $field->web_app_label }}
                                            </span>

                                            @if ($field->requirement_type === 'required')
                                                <span class="text-lg font-medium text-red-600"
                                                    title="Required for VIT submission" aria-label="Required">
                                                    *
                                                </span>
                                            @endif
                                        </div>

                                        @if ($field->description)
                                            <p class="text-xs break-words text-slate-500">
                                                {{ $field->description }}
                                            </p>
                                        @endif
                                    </td>



                                    {{-- Uploaded File Column --}}
                                    <td class="w-1/2 p-3 align-top">
                                        @if ($field->is_multi_value && $rangeActive)
                                            {{-- RANGE MODE --}}
                                            <div>
                                                {{-- Label --}}
                                                <div class="my-2">
                                                    <span class="text-xs font-medium text-slate-600">
                                                        Attribute range
                                                    </span>
                                                </div>

                                                {{-- Selects --}}
                                                <div class="flex items-center gap-2 my-2">
                                                    <select wire:model.live="rangeStarts.{{ $field->field_key }}"
                                                        class="flex-1 min-w-0 px-2.5 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                        <option value="">Start column</option>

                                                        @foreach ($this->availableRangeColumnsFor($field->field_key) as $col)
                                                            <option value="{{ $col->index }}">
                                                                {{ $col->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>

                                                    <span class="text-xs text-slate-400 shrink-0">
                                                        to
                                                    </span>

                                                    <select wire:model.live="rangeEnds.{{ $field->field_key }}"
                                                        class="flex-1 min-w-0 px-2.5 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                        <option value="">End column</option>

                                                        @foreach ($this->availableRangeColumnsFor($field->field_key) as $col)
                                                            <option value="{{ $col->index }}">
                                                                {{ $col->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                {{-- Info card, only when populated --}}
                                                @if ($this->rangeColumnsFor($field->field_key))
                                                    @php
                                                        $rangeCols = $this->rangeColumnsFor($field->field_key);
                                                        $first = $this->columns[$rangeCols[0]] ?? $rangeCols[0];
                                                        $last =
                                                            $this->columns[$rangeCols[count($rangeCols) - 1]] ??
                                                            $rangeCols[count($rangeCols) - 1];

                                                        $firstExample = $this->sampleRows[0][$rangeCols[0]] ?? null;
                                                        $lastExample =
                                                            $this->sampleRows[0][$rangeCols[count($rangeCols) - 1]] ??
                                                            null;
                                                    @endphp

                                                    <div
                                                        class="px-2.5 py-2 my-2 text-xs rounded-lg bg-slate-50 border border-slate-200">
                                                        @if ($firstExample !== null || $lastExample !== null)
                                                            <p
                                                                class="flex flex-wrap items-baseline text-slate-500 gap-x-1">
                                                                <span
                                                                    class="font-medium text-slate-600">Examples:</span>

                                                                @if ($firstExample !== null)
                                                                    <span>{{ $firstExample }}</span>
                                                                @endif

                                                                @if ($firstExample !== null && $lastExample !== null)
                                                                    <span>to</span>
                                                                @endif

                                                                @if ($lastExample !== null)
                                                                    <span>{{ $lastExample }}</span>
                                                                @endif
                                                            </p>
                                                        @endif
                                                    </div>
                                                @endif

                                                {{-- Exit action, right-aligned --}}
                                                <div class="flex items-center justify-end my-2">
                                                    <button type="button"
                                                        wire:click="$set('rangeStarts.{{ $field->field_key }}', null); $set('rangeEnds.{{ $field->field_key }}', null)"
                                                        class="text-xs underline text-sky-600 hover:text-sky-700 shrink-0">
                                                        Switch to single column
                                                    </button>
                                                </div>
                                            </div>
                                        @else
                                            {{-- SINGLE COLUMN MODE --}}

                                            <div class="space-y-2">
                                                <div wire:key="combobox-{{ $field->field_key }}-{{ md5(json_encode($this->mapping)) }}"
                                                    x-data="{
                                                        open: false,
                                                        search: '',
                                                        selectedIndex: @entangle('mapping.' . $field->field_key).live,
                                                        columns: @js(
    $availableColumns
        ->map(
            fn($col) => [
                'index' => (string) $col->index,
                'name' => $col->name,
            ],
        )
        ->values(),
),
                                                        dropdownStyle: '',
                                                        get selectedName() {
                                                            const found = this.columns.find(
                                                                c => String(c.index) === String(this.selectedIndex)
                                                            );
                                                            return found ? found.name : '';
                                                        },
                                                        get filteredColumns() {
                                                            if (!this.search.trim()) {
                                                                return this.columns;
                                                            }
                                                            const q = this.search.trim().toLowerCase();
                                                            return this.columns.filter(c =>
                                                                c.name.toLowerCase().includes(q)
                                                            );
                                                        },
                                                        selectColumn(column) {
                                                            this.selectedIndex = column.index;
                                                            this.search = '';
                                                            this.open = false;
                                                        },
                                                        clearSelection() {
                                                            this.selectedIndex = '';
                                                            this.search = '';
                                                            this.open = false;
                                                        },
                                                        updateDropdownPosition() {
                                                            this.$nextTick(() => {
                                                                const trigger = this.$refs.trigger;
                                                                const dropdown = this.$refs.dropdown;
                                                                if (!trigger || !dropdown) {
                                                                    return;
                                                                }
                                                                const rect = trigger.getBoundingClientRect();
                                                                const viewportPadding = 12;
                                                                const gap = 4;
                                                                const spaceBelow =
                                                                    window.innerHeight - rect.bottom - viewportPadding;
                                                                const spaceAbove =
                                                                    rect.top - viewportPadding;
                                                                const minDropdownHeight = 180;
                                                                let maxHeight;
                                                                let top;
                                                                if (
                                                                    spaceBelow >= minDropdownHeight ||
                                                                    spaceBelow >= spaceAbove
                                                                ) {
                                                                    top = rect.bottom + gap;
                                                                    maxHeight = Math.max(spaceBelow, 120);
                                                                } else {
                                                                    maxHeight = Math.max(spaceAbove, 120);
                                                                    top = rect.top - maxHeight - gap;
                                                                }
                                                                const width = rect.width;
                                                                dropdown.style.position = 'fixed';
                                                                dropdown.style.left = rect.left + 'px';
                                                                dropdown.style.top = top + 'px';
                                                                dropdown.style.width = width + 'px';
                                                                dropdown.style.maxHeight = maxHeight + 'px';
                                                                dropdown.style.zIndex = '9999';
                                                            });
                                                        },
                                                        toggleDropdown() {
                                                            this.open = !this.open;
                                                            if (this.open) {
                                                                this.$nextTick(() => {
                                                                    this.updateDropdownPosition();
                                                                });
                                                            }
                                                        },
                                                        handleViewportChange() {
                                                            if (this.open) {
                                                                this.updateDropdownPosition();
                                                            }
                                                        }
                                                    }" x-init="window.addEventListener('resize', handleViewportChange);
                                                    window.addEventListener('scroll', handleViewportChange, true);
                                                    $cleanup(() => {
                                                        window.removeEventListener('resize', handleViewportChange);
                                                        window.removeEventListener('scroll', handleViewportChange, true);
                                                    });" class="relative">

                                                    {{-- Selected column + Suggested indicator --}}
                                                    <div class="flex items-center gap-2">
                                                        <div class="flex-1 min-w-0">
                                                            <button type="button" x-ref="trigger"
                                                                @click="toggleDropdown()"
                                                                @keydown.escape="open = false"
                                                                class="flex items-center justify-between w-full gap-2 px-2.5 py-1.5 text-sm text-left bg-white border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                                <span
                                                                    x-show="selectedIndex !== null && selectedIndex !== ''"
                                                                    x-text="selectedName"
                                                                    class="min-w-0 truncate text-slate-900"></span>

                                                                <span
                                                                    x-show="selectedIndex === null || selectedIndex === ''"
                                                                    class="text-slate-400">
                                                                    — Do not import
                                                                </span>

                                                                <svg class="w-4 h-4 text-slate-400 shrink-0"
                                                                    fill="none" viewBox="0 0 24 24"
                                                                    stroke="currentColor" stroke-width="1.5">
                                                                    <path stroke-linecap="round"
                                                                        stroke-linejoin="round"
                                                                        d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                                                </svg>
                                                            </button>
                                                        </div>

                                                        {{-- Suggested indicator --}}
                                                        @if ($isSuggested && $currentSelection === null && !$rangeActive)
                                                            <span
                                                                class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0"
                                                                title="Suggested match based on column name">
                                                                Suggested match available
                                                            </span>
                                                        @elseif ($isSuggested && $currentSelection !== null && !$rangeActive)
                                                            <span
                                                                class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 shrink-0">
                                                                Suggested
                                                            </span>
                                                        @endif
                                                    </div>

                                                    {{-- Dropdown --}}
                                                    <template x-teleport="body">
                                                        <div x-show="open" x-cloak x-ref="dropdown"
                                                            @click.outside="open = false"
                                                            @keydown.escape.window="open = false"
                                                            class="overflow-hidden bg-white border rounded-lg shadow-xl border-slate-300"
                                                            style="display: none;">

                                                            {{-- Search --}}
                                                            <div class="p-1.5 border-b border-slate-200 bg-white">
                                                                <input type="text" x-model="search"
                                                                    @keydown.escape="open = false"
                                                                    placeholder="Search file columns..."
                                                                    autocomplete="off"
                                                                    class="w-full px-2.5 py-1.5 text-sm bg-slate-50 border border-slate-200 rounded-md focus:outline-none focus:bg-white focus:border-slate-400 focus:ring-1 focus:ring-slate-400" />
                                                            </div>

                                                            {{-- Options --}}
                                                            <div class="overflow-y-auto overscroll-contain"
                                                                style="max-height: inherit;" @wheel.stop>

                                                                {{-- Do not import --}}
                                                                <button type="button" @click="clearSelection()"
                                                                    class="w-full px-3 py-2 text-sm text-left text-slate-600 hover:bg-slate-50"
                                                                    :class="selectedIndex === null ||
                                                                        selectedIndex === '' ?
                                                                        'bg-slate-100 font-medium text-slate-900' :
                                                                        ''">
                                                                    — Do not import
                                                                </button>

                                                                {{-- Columns --}}
                                                                <template x-for="column in filteredColumns"
                                                                    :key="column.index">
                                                                    <button type="button"
                                                                        @click="selectColumn(column)"
                                                                        class="w-full px-3 py-2 text-sm text-left hover:bg-slate-50"
                                                                        :class="String(selectedIndex) === String(column.index) ?
                                                                            'bg-slate-100 font-medium text-slate-900' :
                                                                            'text-slate-700'">
                                                                        <span x-text="column.name"
                                                                            class="block truncate"></span>
                                                                    </button>
                                                                </template>

                                                                {{-- No results --}}
                                                                <div x-show="filteredColumns.length === 0"
                                                                    class="px-3 py-3 text-sm text-slate-500">
                                                                    No matching columns found.
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>

                                                {{-- Selected column details --}}
                                                @if ($currentSelection !== null && isset($this->sampleRows[0][$currentSelection]))
                                                    <div
                                                        class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-50 border border-slate-200">
                                                        <span class="font-medium text-slate-600">Example:</span>
                                                        <span class="text-slate-500">
                                                            {{ $this->sampleRows[0][$currentSelection] }}
                                                        </span>
                                                    </div>
                                                @endif

                                                {{-- Separator + range option, same row --}}
                                                @if ($field->is_multi_value && $currentSelection !== null)
                                                    <div class="flex items-center justify-between gap-2">
                                                        <div class="flex items-center gap-2">
                                                            <label
                                                                class="text-xs text-slate-500 shrink-0">Separator:</label>
                                                            <select
                                                                wire:model.live="separators.{{ $field->field_key }}"
                                                                class="w-28 px-2 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                                <option value="">None</option>
                                                                <option value=",">Comma (,)</option>
                                                                <option value=";">Semicolon (;)</option>
                                                                <option value="|">Pipe (|)</option>
                                                            </select>
                                                        </div>

                                                        <button type="button"
                                                            wire:click="$set('rangeStarts.{{ $field->field_key }}', {{ array_key_first($this->columns) ?? 0 }}); $set('rangeEnds.{{ $field->field_key }}', {{ array_key_last($this->columns) ?? 0 }})"
                                                            class="text-xs underline text-sky-600 hover:text-sky-700 shrink-0">
                                                            Use a column range instead
                                                        </button>
                                                    </div>

                                                    @if (isset($this->separatorValidationErrors[$field->field_key]))
                                                        <p class="mt-1 text-xs text-rose-600">
                                                            {{ $this->separatorValidationErrors[$field->field_key] }}
                                                        </p>
                                                    @endif
                                                @endif

                                                {{-- Item weight unit selector (mapper configuration) --}}
                                                @if ($field->field_key === 'item_weight_in_pounds' && $currentSelection !== null)
                                                    <div class="flex items-center gap-2">
                                                        <label class="text-xs text-slate-500 shrink-0">Unit:</label>
                                                        <select wire:model.live="weightUnit"
                                                            class="w-28 px-2 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                                            @foreach (\App\Services\WeightUnitConverter::supportedUnits() as $unit)
                                                                <option value="{{ $unit }}">
                                                                    {{ $unit }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endif
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
                            stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M11.25 11.25h.008v.008h-.008v-.008ZM12 9.75v.008h.008V9.75H12Zm0 3.75v5.25m0-15a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z" />
                        </svg>

                        <div>
                            <strong class="block">Not in this file:</strong>

                            <span class="block mt-1">
                                {{ $this->unmappedRequiredFields()->join(', ') }}.
                            </span>

                            <span class="block mt-1 text-sm text-slate-600">
                                You can continue without these. They can be completed later by Editing the Catalog Item
                                before requesting review.
                            </span>
                        </div>
                    </div>
                @endif

                @error('mapping')
                    <p class="mt-4 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                {{-- @if (!empty($this->separatorValidationErrors))
                    @foreach ($this->separatorValidationErrors as $error)
                        <p class="mt-2 text-sm text-rose-600">{{ $error }}</p>
                    @endforeach
                @endif --}}

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
                                {{ $progress['unchanged_rows'] }}</dd>
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
                                                        $payload = is_string($failedRow->errors)
                                                            ? json_decode($failedRow->errors, true)
                                                            : $failedRow->errors;
                                                        $errorMessages = is_array($payload)
                                                            ? collect($payload['errors'] ?? [])
                                                                ->pluck('message')
                                                                ->implode('; ')
                                                            : (string) $failedRow->errors;
                                                    @endphp
                                                    {{ $errorMessages }}
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
                                                        $payload = is_string($failedRow->errors)
                                                            ? json_decode($failedRow->errors, true)
                                                            : $failedRow->errors;
                                                        $errorMessages = is_array($payload)
                                                            ? collect($payload['errors'] ?? [])
                                                                ->pluck('message')
                                                                ->implode('; ')
                                                            : (string) $failedRow->errors;
                                                    @endphp
                                                    {{ $errorMessages }}
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
                                d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6.75c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6.75a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621.504 1.125 1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z" />
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
                    Catalog import failed due to an unexpected error. Please review your file and try again.
                </p>
                <button wire:click="startOver" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-slate-900 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition disabled:opacity-50 disabled:cursor-not-allowed sm:w-auto">
                    Try again
                </button>
            </div>
        @endif

    </div>
</div>
