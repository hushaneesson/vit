<x-layouts.vendor title="Dashboard">
    <div class="space-y-6">
        <div class="flex flex-col items-center gap-3 md:justify-between md:flex-row">
            <div>
                <h1 class="text-xl font-semibold">Welcome, {{ $client->name }}</h1>
                <p class="text-sm text-gray-500">{{ $vendor->name }} &middot; {{ $catalogItemCount }} catalog item(s) on
                    file</p>
            </div>
            <a href="{{ route('vendor.catalog.index') }}" class="w-full md:w-auto btn btn-primary">
                Manage Catalog
            </a>
        </div>


        <div class="overflow-hidden bg-white rounded-lg shadow">
            <div class="px-4 py-3 font-medium border-b border-gray-200">Recent Submissions</div>
            @if ($submissions->isEmpty())
                <p class="p-4 text-sm text-gray-500">No submissions yet.</p>
            @else
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="text-left bg-gray-50">
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
                                <td class="px-4 py-2">Submission #{{ $submission->id }}</td>
                                <td class="px-4 py-2">{{ $submission->total_items }}</td>
                                <td class="px-4 py-2">
                                    {{ ucfirst(str_replace('_', ' ', $submission->status?->value ?? $submission->status)) }}
                                </td>
                                <td class="px-4 py-2">{{ $submission->requested_at?->format('M j, Y') }}</td>
                                <td class="px-4 py-2">
                                    @if (
                                        $submission->status === \App\Enums\CatalogSubmissionStatus::ReadyForUpload ||
                                            $submission->status === \App\Enums\CatalogSubmissionStatus::Uploaded)
                                        <span class="text-sm text-gray-400">Delivered</span>
                                    @elseif ($submission->status === \App\Enums\CatalogSubmissionStatus::Approved)
                                        <span class="text-sm text-emerald-600">Approved</span>
                                    @elseif ($submission->status === \App\Enums\CatalogSubmissionStatus::Rejected)
                                        <span class="text-sm text-red-600">Rejected</span>
                                    @else
                                        <span class="text-sm text-gray-400">Pending</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.vendor>
