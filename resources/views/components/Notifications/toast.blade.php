<div
    x-data="{
        toasts: [],
        init() {
            const seeded = @js(session('notify'));

            if (seeded && seeded.type && seeded.message) {
                this.showToast(seeded.type, seeded.message);
            }
        },
        showToast(type, message) {
            const id = Date.now();

            this.toasts.push({ id, type, message });

            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 5000);
        }
    }"
    x-on:notify.window="showToast($event.detail.type, $event.detail.message)"
    class="fixed z-[70] flex flex-col gap-2 top-4 right-4">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="true" x-transition
            :class="{
                'bg-emerald-600 border-emerald-700 text-white': toast.type === 'success',
                'bg-rose-600 border-rose-700 text-white': toast.type === 'error',
                'bg-amber-500 border-amber-600 text-white': toast.type === 'warning',
                'bg-sky-600 border-sky-700 text-white': toast.type === 'info',
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
            <svg x-show="toast.type === 'warning'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 9v3.75m-1.5 6.75h3M8.25 21h7.5a2.25 2.25 0 0 0 2.25-2.25V8.25L12.75 3H8.25v4.5H4.5v11.25A2.25 2.25 0 0 0 6.75 21h1.5Z" />
            </svg>
            <svg x-show="toast.type === 'info'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
            </svg>
            <span class="flex-1" x-text="toast.message"></span>
            <button @click="toasts = toasts.filter(t => t.id !== toast.id)"
                class="shrink-0 opacity-70 hover:opacity-100">&times;</button>
        </div>
    </template>
</div>
