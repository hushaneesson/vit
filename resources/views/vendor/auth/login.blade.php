<x-layouts.app>
    <main class="container flex items-center justify-center w-full min-h-screen px-4 py-10 mx-auto sm:px-6 lg:px-8">
        <div class="max-w-md p-8 mx-auto bg-white border border-gray-100 shadow-sm rounded-2xl ring-1 ring-sky-950/5">
            <div class="mb-8 text-center">
                <img src="{{ asset('/logo.png') }}" alt="VIT Vendor Portal" class="w-auto h-20 mx-auto">
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Sign in</h1>
                <p class="mt-2 text-sm text-gray-500">Enter your email address to receive a one-time login code.</p>
            </div>

            <form method="POST" action="{{ route('vendor.login.send') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email">Email address</label>
                    <input type="email" name="email" id="email" required autofocus />
                </div>

                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-lg btn-primary px-4 py-2.5 shadow-sm">
                    Send Login Code
                </button>
            </form>
        </div>
    </main>
</x-layouts.app>
