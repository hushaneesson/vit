<x-layouts.vendor title="Dashboard">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Welcome, {{ $client->name }}</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }} &middot; {{ $catalogItemCount }} catalog item(s) on file</p>
            </div>
            <a href="{{ route('vendor.catalog.index') }}"
                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Manage Catalog
            </a>
        </div>


        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Recent Submissions</div>
            @if ($submissions->isEmpty())
                <p class="p-4 text-sm text-gray-500">No submissions yet.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th class="px-4 py-2">Catalog</th>
                            <th class="px-4 py-2">Products</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($submissions as $submission)
                            <tr>
                                <td class="px-4 py-2">{{ $submission->catalog_name }}</td>
                                <td class="px-4 py-2">{{ $submission->product_count }}</td>
                                <td class="px-4 py-2">{{ ucfirst(str_replace('_', ' ', $submission->status)) }}</td>
                                <td class="px-4 py-2">{{ $submission->submission_date?->format('M j, Y') }}</td>
                                <td class="px-4 py-2">
                                    <a href="{{ route('vendor.submissions.download', $submission) }}" class="text-indigo-600 hover:text-indigo-800">Download</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.vendor>
