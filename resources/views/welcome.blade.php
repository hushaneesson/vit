<x-layouts.app>
    <div class="flex justify-center h-screen p-6 mx-auto max-w-7xl">
        <!-- ===== LANDING SCREEN ===== -->
        <div class="flex flex-col items-center justify-center p-8 text-center">
            <img src="/logo.png" class="mb-6 h-28" />

            <h1 class="mb-4 text-3xl font-bold">
                Welcome to VIT Vendor Portal
            </h1>

            <p class="max-w-xl mb-8 text-gray-600">
                Complete onboarding and catalog
                creation.
            </p>

            <div class="flex flex-col items-center gap-3">
                <a href="/vendor/login"
                    class="flex items-center justify-center gap-2 px-6 py-3 shadow-lg w-72 rounded-xl shadow-cyan-200/50 btn-primary">
                    <i class="w-4 h-4 fas fa-right-to-bracket"></i>
                    Vendor Login
                </a>

                <div class="flex items-center gap-3 my-2 w-72">
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-xs font-medium text-slate-400">or</span>
                    <div class="flex-1 h-px bg-slate-200"></div>
                </div>

                <a href="/admin/login"
                    class="flex items-center justify-center gap-2 px-6 py-3 font-semibold transition-all duration-200 bg-white border w-72 text-slate-600 border-slate-200 hover:bg-slate-50 hover:border-slate-300 rounded-xl">
                    <i class="w-4 h-4 fas fa-right-to-bracket"></i>
                    Admin Login
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
