<div x-data="{ toasts: [] }"
    x-on:notify.window="
        const id = Date.now();
        toasts.push({ id, type: $event.detail.type, message: $event.detail.message });
        setTimeout(() => toasts = toasts.filter(t => t.id !== id), 5000);
    "
    class="fixed z-50 flex flex-col gap-2 top-4 right-4">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="true" x-transition
            :class="{
                'bg-emerald-600 border-emerald-700 text-white': toast.type === 'success',
                'bg-rose-600 border-rose-700 text-white': toast.type === 'error',
            }"
            class="flex items-center gap-3 px-4 py-3 text-sm font-medium border rounded-lg shadow-xl max-w-sm">
            <svg x-show="toast.type === 'success'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            <svg x-show="toast.type === 'error'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <span class="flex-1" x-text="toast.message"></span>
            <button @click="toasts = toasts.filter(t => t.id !== toast.id)"
                class="shrink-0 opacity-70 hover:opacity-100">&times;</button>
        </div>
    </template>
</div>
