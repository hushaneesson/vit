<x-layouts.app>
    <div x-data="{ open: false }" class="min-h-screen">
        <!-- Navigation -->
        <nav class="sticky top-0 z-50 p-3 border-b shadow-sm bg-white/80 backdrop-blur-md border-slate-200 ">
            <div class="container px-4 mx-auto sm:px-6 lg:px-8">

                <div class="flex items-center justify-between h-16">

                    <!-- Logo -->
                    <a href="/vendor/dashboard">
                        <img src="/logo.png" class="h-20" />
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="items-center hidden space-x-8 lg:flex">

                        <a href="{{ route('vendor.dashboard') }}"
                            class="font-medium text-gray-500 transition hover:text-sky-600">
                            Dashboard
                        </a>

                        <a href="{{ route('vendor.catalog.index') }}"
                            class="font-medium text-gray-500 transition hover:text-sky-600">
                            Catalog
                        </a>

                        <a href="{{ route('vendor.appointments.index') }}"
                            class="font-medium text-gray-500 transition hover:text-sky-600">
                            Appointments
                        </a>
                    </div>

                    <!-- Desktop User -->
                    <div class="items-center hidden space-x-4 lg:flex">

                        <span class="text-sm text-gray-600">
                            {{ auth('client')->user()->name }}
                        </span>

                        <form method="POST" action="{{ route('vendor.logout') }}">
                            @csrf
                            <button type="submit"
                                class="py-2.5 text-sm font-medium btn bg-slate-100 hover:bg-slate-200 hover:font-bold text-slate-500">
                                <i class="fas fa-arrow-right-from-bracket"></i> Log out
                            </button>
                        </form>
                    </div>

                    <!-- Mobile Hamburger -->
                    <button @click="open = !open" class="p-2 transition rounded-lg hover:bg-gray-100 lg:hidden">

                        <!-- Hamburger -->
                        <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16" />
                        </svg>

                        <!-- Close -->
                        <svg x-show="open" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>

                    </button>

                </div>

            </div>

            <!-- Mobile Navigation -->
            <div x-show="open" x-transition x-cloak @click.outside="open = false" class="bg-white border-t lg:hidden">

                <div class="px-4 py-4 space-y-1">

                    <a href="{{ route('vendor.dashboard') }}" class="block px-3 py-2 rounded-lg hover:bg-gray-100">
                        Dashboard
                    </a>

                    <a href="{{ route('vendor.catalog.index') }}" class="block px-3 py-2 rounded-lg hover:bg-gray-100">
                        Catalog
                    </a>

                    <a href="{{ route('vendor.appointments.index') }}"
                        class="block px-3 py-2 rounded-lg hover:bg-gray-100">
                        Appointments
                    </a>

                    <hr class="my-3">

                    <div class="mb-2 text-sm text-gray-600">
                        {{ auth('client')->user()->name }}
                    </div>

                    <form class="pt-2" method="POST" action="{{ route('vendor.logout') }}">
                        @csrf

                        <button type="submit"
                            class="py-2.5 w-full font-medium btn bg-slate-100 hover:bg-slate-200 hover:font-bold text-slate-500">
                            <i class="fas fa-arrow-right-from-bracket"></i> Log out
                        </button>
                    </form>
                </div>

            </div>

        </nav>


        <!-- Main Content -->
        <main class="container px-4 py-8 mx-auto sm:px-6 lg:px-8">

            @if (session('status'))
                <div
                    class="px-4 py-3 mb-5 text-sm border shadow-sm rounded-xl border-emerald-200 bg-emerald-50 text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="px-4 py-3 mb-5 text-sm text-red-800 border border-red-200 shadow-sm rounded-xl bg-red-50">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </main>

    </div>
</x-layouts.app>
