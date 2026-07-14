<x-layouts.app>
    <main class="container flex items-center justify-center w-full min-h-screen px-4 py-10 mx-auto sm:px-6 lg:px-8">
        <div class="max-w-md p-8 mx-auto bg-white border border-gray-100 shadow-sm rounded-2xl ring-1 ring-sky-950/5">
            <div class="mb-8 text-center">
                <a href="{{ route('home') }}">
                    <img src="{{ asset('/logo.png') }}" alt="VIT Vendor Portal" class="w-auto h-20 mx-auto">
                </a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Enter your code</h1>
                <p class="mt-2 text-sm text-gray-500">
                    We emailed a 6-digit code to <span class="font-medium text-gray-700">{{ $email }}</span>.
                    Enter it
                    below to continue.
                </p>
            </div>

            <form method="POST" action="{{ route('vendor.login.otp.verify') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">

                <div>
                    <label for="code">Login code</label>
                    <input type="text" name="code" id="code" required autofocus inputmode="numeric"
                        maxlength="6" />
                </div>

                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 shadow-sm btn-primary">
                    Verify &amp; Log In
                </button>
            </form>

            <form method="POST" action="{{ route('vendor.login.send') }}" class="mt-6 text-center">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}" />
                <button type="submit" class="text-sm font-medium transition text-sky-600 hover:text-sky-500">Resend
                    code</button>
            </form>
        </div>
    </main>
</x-layouts.app>
