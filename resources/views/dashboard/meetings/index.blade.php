<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('meeting.title') }}
        </h2>
    </x-slot>

    <div @if(app()->getLocale() === 'fa') dir="rtl" lang="fa" @endif class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div id="meetings-loading" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-500">
                {{ __('meeting.loading') }}
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('meeting.upcoming') }}</h3>

                    @if($upcoming->isEmpty())
                        <p class="text-gray-500 dark:text-gray-400">{{ __('meeting.no_upcoming') }}</p>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($upcoming as $meeting)
                                <li class="py-4 flex items-center justify-between gap-4">
                                    <div>
                                        <a href="{{ route('dashboard.meetings.show', $meeting) }}" class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                            {{ $meeting->title }}
                                        </a>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ \App\Domain\Meeting\Support\JalaliDateHelper::format($meeting->starts_at) }}
                                            <span class="text-gray-400">({{ $meeting->starts_at->format('Y-m-d H:i') }})</span>
                                            · {{ __('meeting.type') }}: {{ $meeting->type->value }}
                                            · {{ __('meeting.status') }}: {{ $meeting->status->value }}
                                        </p>
                                    </div>
                                    <span class="text-sm text-gray-500">{{ $meeting->host->name ?? '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('meeting.past') }}</h3>

                    @if($past->isEmpty())
                        <p class="text-gray-500 dark:text-gray-400">{{ __('meeting.no_past') }}</p>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($past as $meeting)
                                <li class="py-4">
                                    <a href="{{ route('dashboard.meetings.show', $meeting) }}" class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                        {{ $meeting->title }}
                                    </a>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ \App\Domain\Meeting\Support\JalaliDateHelper::format($meeting->starts_at) }}
                                        <span class="text-gray-400">({{ $meeting->starts_at->format('Y-m-d H:i') }})</span>
                                        · {{ $meeting->status->value }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('meetings-loading');
            if (el) el.style.display = 'none';
        });
    </script>
</x-app-layout>
