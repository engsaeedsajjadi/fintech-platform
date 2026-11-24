<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('dashboard.hub_title') }}
        </h2>
    </x-slot>

    <div @if(app()->getLocale() === 'fa') dir="rtl" lang="fa" @endif class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.hub_subtitle') }}</p>

            @if(session('status'))
                <div class="bg-green-50 dark:bg-green-900 text-green-800 dark:text-green-100 rounded-md p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="bg-red-50 dark:bg-red-900 text-red-800 dark:text-red-100 rounded-md p-4 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($sections as $section)
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $section['title'] }}</h3>
                                <span class="text-xs px-2 py-1 rounded-full
                                    @if($section['status'] === 'live') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100
                                    @elseif($section['status'] === 'demo') bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100
                                    @elseif($section['status'] === 'disabled') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100
                                    @else bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100 @endif">
                                    {{ __('dashboard.status_'.$section['status']) }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $section['detail'] }}</p>
                            @if(!empty($section['url']))
                                <a href="{{ $section['url'] }}" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                    {{ __('dashboard.open') }} →
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
