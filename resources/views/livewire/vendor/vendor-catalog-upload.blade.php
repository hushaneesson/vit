<div>
    <div class="max-w-4xl px-4 py-10 mx-auto catalog-upload-mapper">

        {{-- STEP RAIL --}}
        @php
            $steps = ['upload' => 'Upload', 'mapping' => 'Map Columns', 'processing' => 'Process', 'summary' => 'Done'];
            $stepKeys = array_keys($steps);
            $currentIndex = array_search($step, $stepKeys) !== false ? array_search($step, $stepKeys) : 0;
        @endphp

        @unless ($step === 'error')
            <ol class="flex items-center mb-10">
                @foreach ($steps as $key => $label)
                    @php $isDone = array_search($key, $stepKeys) < $currentIndex; $isCurrent = $key === $step; @endphp
                    <li class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1.5 shrink-0">
                            <span @class([
                                'flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold border-2 shrink-0',
                                'bg-slate-900 border-slate-900 text-white' => $isDone,
                                'bg-white border-slate-900 text-slate-900' => $isCurrent,
                                'bg-white border-slate-300 text-slate-400' => !$isDone && !$isCurrent,
                            ])>
                                @if ($isDone)
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <span @class([
                                'text-xs font-medium whitespace-nowrap',
                                'text-slate-900' => $isCurrent || $isDone,
                                'text-slate-400' => !$isCurrent && !$isDone,
                            ])>{{ $label }}</span>
                        </div>
                        @if (!$loop->last)
                            <div @class(['flex-1 h-0.5 mx-3 -mt-5', 'bg-slate-900' => $isDone, 'bg-slate-200' => !$isDone])></div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endunless

        {{-- STEP 1: Upload --}}
        @if ($step === 'upload')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <h2 class="text-xl font-semibold text-slate-900">Upload your product catalog</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Send us your file as-is &mdash; on the next screen you'll match its columns to VIT's fields.
                </p>

                <div class="mt-6">
                    <label for="catalog-name" class="block text-sm font-medium text-slate-700">Catalog Name</label>
                    <input
                        id="catalog-name"
                        type="text"
                        wire:model="catalogName"
                        placeholder="e.g. Q3 2026 Product Catalog"
                        class="block w-full px-3 py-2 mt-1 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                    />
                    @error('catalogName')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <label
                    for="catalog-file"
                    class="relative flex flex-col items-center justify-center gap-2 px-6 py-12 mt-4 text-center transition border-2 border-dashed rounded-lg cursor-pointer border-slate-300 hover:border-slate-400 hover:bg-slate-50"
                >
                    <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5" />
                    </svg>

                    @if ($file)
                        <span class="text-sm font-medium text-slate-900">{{ $file->getClientOriginalName() }}</span>
                        <span class="text-xs text-slate-500">Click to choose a different file</span>
                    @else
                        <span class="text-sm font-medium text-slate-900">Click to browse, or drag a file here</span>
                        <span class="text-xs text-slate-500">CSV, XLS, or XLSX &middot; up to 50MB</span>
                    @endif

                    <input id="catalog-file" type="file" wire:model="file" accept=".csv,.xls,.xlsx" class="sr-only" />
                </label>

                <div wire:loading wire:target="file" class="flex items-center gap-2 mt-3 text-sm text-slate-500">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Reading file&hellip;
                </div>

                @error('file')
                    <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <button
                    wire:click="uploadFile"
                    wire:loading.attr="disabled"
                    wire:target="uploadFile"
                    @disabled(!$file || !$catalogName)
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-white bg-slate-900 rounded-lg disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-800 transition"
                >
                    <span wire:loading.remove wire:target="uploadFile">Continue to mapping</span>
                    <span wire:loading wire:target="uploadFile">Processing file&hellip;</span>
                </button>
            </div>
        @endif

        {{-- STEP 2: Column mapping --}}
        @if ($step === 'mapping')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <h2 class="text-xl font-semibold text-slate-900">Match your columns</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Tell us which column in your file corresponds to each VIT field. Fields marked
                    <span class="font-semibold text-rose-600">*</span> are required.
                </p>

                <div class="mt-6 overflow-hidden border rounded-lg border-slate-200">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-xs font-semibold tracking-wide uppercase bg-slate-50 text-slate-500">
                                <th class="p-3 text-left border-b border-slate-200">Your Column</th>
                                <th class="p-3 text-left border-b border-slate-200">Sample Value</th>
                                <th class="p-3 text-left border-b border-slate-200 w-72">Maps To</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($columns as $index => $columnName)
                                <tr class="hover:bg-slate-50/60">
                                    <td class="p-3 font-medium text-slate-900">{{ $columnName }}</td>
                                    <td class="p-3 font-mono text-xs text-slate-500">
                                        {{ $sampleRows[0][$index] ?? '—' }}
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <select
                                                wire:model.live="mapping.{{ $index }}"
                                                class="w-full px-2.5 py-1.5 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                                            >
                                                <option value="">Do not import</option>
                                                @foreach ($this->catalogFields as $field)
                                                    <option value="{{ $field->field_key }}">
                                                        {{ $field->web_app_label }}
                                                        @if ($field->requirement_type === 'required') * @endif
                                                    </option>
                                                @endforeach
                                            </select>

                                            @if (!empty($suggestedIndexes[$index]))
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-50 text-indigo-700 shrink-0"
                                                    title="Auto-suggested based on your column name - please confirm it's correct"
                                                >
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 0 0 .95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 0 0-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.538 1.118l-3.367-2.447a1 1 0 0 0-1.176 0l-3.367 2.447c-.783.57-1.838-.196-1.538-1.118l1.287-3.957a1 1 0 0 0-.363-1.118L2.063 9.385c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 0 0 .95-.69l1.286-3.958Z" />
                                                    </svg>
                                                    Suggested
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
                    <div class="flex items-start gap-2 p-3 mt-4 text-sm rounded-lg bg-amber-50 text-amber-800">
                        <svg class="w-5 h-5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span>Still needed: {{ $this->unmappedRequiredFields->join(', ') }}</span>
                    </div>
                @endif

                <div class="pt-5 mt-6 border-t border-slate-100">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model.live="saveAsTemplate" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                        Save this mapping for future uploads
                    </label>

                    @if ($saveAsTemplate)
                        <input
                            type="text"
                            wire:model="templateName"
                            placeholder="Name this mapping (e.g. &quot;Our standard export&quot;)"
                            class="w-full max-w-sm px-3 py-2 mt-3 text-sm border rounded-lg border-slate-300 focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                        />
                    @endif
                </div>

                @error('mapping')
                    <p class="mt-4 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <button
                    wire:click="confirmMapping"
                    wire:loading.attr="disabled"
                    @disabled($this->unmappedRequiredFields->isNotEmpty())
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-white bg-slate-900 rounded-lg disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-800 transition"
                >
                    Confirm &amp; Upload
                </button>
            </div>
        @endif

        {{-- STEP 3: Processing --}}
        @if ($step === 'processing')
            <div wire:poll.2s="refreshStatus" class="p-10 text-center bg-white border rounded-xl border-slate-200">
                <div class="flex items-center justify-center mx-auto rounded-full w-14 h-14 bg-slate-100">
                    <svg class="w-6 h-6 animate-spin text-slate-900" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-slate-900">Processing your file&hellip;</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-500">
                    This may take a few minutes for larger files. You can leave this page &mdash; we'll keep working
                    in the background.
                </p>

                <div class="w-full h-1.5 mt-6 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-slate-900 animate-pulse" style="width: 60%"></div>
                </div>
            </div>
        @endif

        {{-- STEP 4: Summary --}}
        @if ($step === 'summary')
            <div class="p-8 bg-white border rounded-xl border-slate-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-emerald-100">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-center text-slate-900">Upload complete</h2>

                <dl class="grid grid-cols-3 gap-3 mt-6">
                    <div class="p-4 text-center border rounded-lg border-slate-200 bg-slate-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-slate-500">Total Rows</dt>
                        <dd class="mt-1 text-2xl font-semibold text-slate-900">{{ $progress['total_rows'] }}</dd>
                    </div>
                    <div class="p-4 text-center border rounded-lg border-emerald-200 bg-emerald-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-emerald-700">Succeeded</dt>
                        <dd class="mt-1 text-2xl font-semibold text-emerald-700">{{ $progress['success_rows'] }}</dd>
                    </div>
                    <div class="p-4 text-center border rounded-lg border-rose-200 bg-rose-50">
                        <dt class="text-xs font-medium tracking-wide uppercase text-rose-700">Errors</dt>
                        <dd class="mt-1 text-2xl font-semibold text-rose-700">{{ $progress['error_rows'] }}</dd>
                    </div>
                </dl>

                @if ($progress['error_rows'] > 0 && $this->failedRows->isNotEmpty())
                    <div class="mt-6 overflow-hidden border rounded-lg border-rose-200">
                        <div class="px-4 py-2.5 text-xs font-semibold tracking-wide uppercase bg-rose-50 text-rose-700">
                            Failed rows
                        </div>
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="text-xs font-semibold tracking-wide uppercase bg-slate-50 text-slate-500">
                                    <th class="p-2.5 pl-4 text-left border-b border-slate-200">Row</th>
                                    <th class="p-2.5 text-left border-b border-slate-200">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-rose-100">
                                @foreach ($this->failedRows as $failedRow)
                                    <tr class="hover:bg-rose-50/40">
                                        <td class="p-2.5 pl-4 font-mono text-xs text-slate-900">{{ $failedRow->row_number }}</td>
                                        <td class="p-2.5 text-xs text-rose-700">
                                            {{ is_array($failedRow->errors) ? implode('; ', $failedRow->errors) : $failedRow->errors }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <button
                    wire:click="startOver"
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-slate-900 bg-white border rounded-lg border-slate-300 hover:bg-slate-50 transition"
                >
                    Upload another file
                </button>
            </div>
        @endif

        {{-- ERROR --}}
        @if ($step === 'error')
            <div class="p-8 text-center bg-white border rounded-xl border-rose-200">
                <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full bg-rose-100">
                    <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-rose-600">Something went wrong</h2>
                <p class="max-w-sm mx-auto mt-1 text-sm text-slate-600">
                    {{ $progress['failure_reason'] ?? 'Please try again or contact support.' }}
                </p>
                <button
                    wire:click="startOver"
                    class="inline-flex items-center gap-2 px-5 py-2.5 mt-6 text-sm font-semibold text-slate-900 bg-white border rounded-lg border-slate-300 hover:bg-slate-50 transition"
                >
                    Try again
                </button>
            </div>
        @endif

    </div>
</div>
