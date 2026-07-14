<x-layouts.vendor title="Enter Login Code">
    <div class="max-w-md p-8 mx-auto bg-white border border-gray-100 shadow-sm rounded-2xl ring-1 ring-sky-950/5">
        <div class="mb-8 text-center">
            <a href="{{ route('home') }}">
                <img src="{{ asset('/logo.png') }}" alt="VIT Vendor Portal" class="w-auto h-20 mx-auto">
            </a>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Enter your code</h1>
            <p class="mt-2 text-sm text-gray-500">
                We emailed a 6-digit code to <span class="font-medium text-gray-700">{{ $email }}</span>. Enter it
                below to continue.
            </p>
        </div>

        <form method="POST" action="{{ route('vendor.login.otp.verify') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">

            <div>
                <label for="code" class="mb-1.5 block text-sm font-medium text-gray-700">Login code</label>
                <input type="text" name="code" id="code" required autofocus inputmode="numeric"
                    maxlength="6"
                    class="block w-full appearance-none rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-center text-xl tracking-[0.4em] text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 font-mono">
            </div>

            <button type="submit"
                class="inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 shadow-sm btn-primary">
                Verify &amp; Log In
            </button>
        </form>

        <form method="POST" action="{{ route('vendor.login.send') }}" class="mt-6 text-center">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <button type="submit" class="text-sm font-medium transition text-sky-600 hover:text-sky-500">Resend
                code</button>
        </form>
    </div>
</x-layouts.vendor>
