<x-layouts.vendor title="Dashboard">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:items-center md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">Welcome, {{ $client->name }}</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }}</p>
            </div>
            <a href="{{ route('vendor.catalog.index') }}" class="w-full md:w-auto btn btn-primary">
                Manage Catalog
            </a>
        </div>

        <div class="p-2 overflow-hidden bg-white rounded-lg shadow-lg">
            <div class="px-4 py-3 font-medium border-b border-gray-200">Recent Submissions</div>
            @if ($submissions->isEmpty())
                <p class="p-4 text-sm text-gray-500">Please create your catalog then submit items for upload to your
                    store.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200 whitespace-nowrap">
                    <thead class="bg-gray-50 ">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Catalog</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Products</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Date</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($submissions as $submission)
                            <tr class="text-sm hover:bg-gray-50 whitespace-nowrap">
                                <td class="px-6 py-4">
                                    Submission #{{ $submission->id }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ $submission->total_items }}
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $submission->requested_at?->format('M j, Y') }}
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full border {{ $submission->status->badgeClass() }}">
                                        {{ $submission->status->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.vendor>
