<x-layouts.vendor title="Log In">
    <div class="max-w-md p-8 mx-auto bg-white border border-gray-100 shadow-sm rounded-2xl ring-1 ring-sky-950/5">
        <div class="mb-8 text-center">
            <img src="{{ asset('/logo.png') }}" alt="VIT Vendor Portal" class="w-auto h-20 mx-auto">
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Sign in</h1>
            <p class="mt-2 text-sm text-gray-500">Enter your email address to receive a one-time login code.</p>
        </div>

        <form method="POST" action="{{ route('vendor.login.send') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">Email address</label>
                <input type="email" name="email" id="email" required autofocus
                    class="block w-full appearance-none rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-sky-500 focus:ring-1 focus:ring-sky-500">
            </div>

            <button type="submit"
                class="inline-flex w-full items-center justify-center rounded-lg btn-primary px-4 py-2.5 shadow-sm">
                Send Login Code
            </button>
        </form>
    </div>
</x-layouts.vendor>
