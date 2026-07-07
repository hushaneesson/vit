<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'VIT Vendor Portal' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen antialiased bg-gradient-to-br from-slate-100 via-blue-50 to-sky-50 text-gray-950">
    <main class="flex items-center justify-center w-full min-h-screen px-4 py-10 mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="w-full max-w-md">
            @auth('client')
                <div class="flex justify-end mb-6">
                    <form method="POST" action="{{ route('vendor.logout') }}">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-gray-600 transition hover:text-gray-900">Log
                            out</button>
                    </form>
                </div>
            @endauth

            @if (session('status'))
                <div class="px-4 py-3 mb-5 text-sm border shadow-sm rounded-xl border-sky-200 bg-sky-50 text-sky-900">
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
        </div>
    </main>

    @livewireScripts
</body>

</html>
