@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">

        {{-- Mobile --}}
        <div class="flex items-center justify-between gap-2 sm:hidden">

            @if ($paginator->onFirstPage())
                <span
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 bg-gray-100 border border-gray-200 rounded-lg cursor-not-allowed">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}"
                    rel="prev"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium transition-all duration-200 bg-white border rounded-lg text-sky-600 border-sky-300 hover:bg-sky-500 hover:text-white hover:scale-105 focus:outline-none focus:ring-2 focus:ring-sky-300">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}"
                    rel="next"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium transition-all duration-200 bg-white border rounded-lg text-sky-600 border-sky-300 hover:bg-sky-500 hover:text-white hover:scale-105 focus:outline-none focus:ring-2 focus:ring-sky-300">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 bg-gray-100 border border-gray-200 rounded-lg cursor-not-allowed">
                    {!! __('pagination.next') !!}
                </span>
            @endif

        </div>

        {{-- Desktop --}}
        <div class="hidden sm:flex sm:items-center sm:justify-between">

            {{-- Results --}}
            <div>
                <p class="text-sm text-gray-600">
                    Showing
                    @if ($paginator->firstItem())
                        <span class="font-semibold">{{ $paginator->firstItem() }}</span>
                        to
                        <span class="font-semibold">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    of
                    <span class="font-semibold">{{ $paginator->total() }}</span>
                    results
                </p>
            </div>

            {{-- Pagination --}}
            <div>
                <span class="inline-flex items-center gap-1">

                    {{-- Previous --}}
                    @if ($paginator->onFirstPage())
                        <span
                            class="inline-flex items-center justify-center w-10 h-10 text-gray-300 bg-gray-100 border border-gray-200 rounded-lg cursor-not-allowed">
                            &lsaquo;
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}"
                            rel="prev"
                            class="inline-flex items-center justify-center w-10 h-10 transition-all duration-200 bg-white border rounded-lg text-sky-600 border-sky-300 hover:bg-sky-500 hover:text-white hover:scale-105 focus:outline-none focus:ring-2 focus:ring-sky-300">
                            &lsaquo;
                        </a>
                    @endif

                    {{-- Page Numbers --}}
                    @foreach ($elements as $element)

                        @if (is_string($element))
                            <span
                                class="inline-flex items-center justify-center w-10 h-10 text-gray-500">
                                {{ $element }}
                            </span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)

                                @if ($page == $paginator->currentPage())
                                    <span
                                        class="inline-flex items-center justify-center w-10 h-10 font-semibold text-white border rounded-lg bg-sky-500 border-sky-500">
                                        {{ $page }}
                                    </span>
                                @else
                                    <a href="{{ $url }}"
                                        class="inline-flex items-center justify-center w-10 h-10 transition-all duration-200 bg-white border rounded-lg text-sky-600 border-sky-300 hover:bg-sky-500 hover:text-white hover:scale-105 focus:outline-none focus:ring-2 focus:ring-sky-300">
                                        {{ $page }}
                                    </a>
                                @endif

                            @endforeach
                        @endif

                    @endforeach

                    {{-- Next --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}"
                            rel="next"
                            class="inline-flex items-center justify-center w-10 h-10 transition-all duration-200 bg-white border rounded-lg text-sky-600 border-sky-300 hover:bg-sky-500 hover:text-white hover:scale-105 focus:outline-none focus:ring-2 focus:ring-sky-300">
                            &rsaquo;
                        </a>
                    @else
                        <span
                            class="inline-flex items-center justify-center w-10 h-10 text-gray-300 bg-gray-100 border border-gray-200 rounded-lg cursor-not-allowed">
                            &rsaquo;
                        </span>
                    @endif

                </span>
            </div>

        </div>

    </nav>
@endif
