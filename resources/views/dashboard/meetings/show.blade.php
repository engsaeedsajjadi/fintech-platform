<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $meeting->title }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-2">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('meeting.host') }}: {{ $meeting->host->name ?? '' }} ·
                        {{ __('meeting.type') }}: {{ $meeting->type->value }} ·
                        {{ __('meeting.status') }}: {{ $meeting->status->value }} ·
                        {{ __('meeting.provider') }}: {{ $meeting->provider }}
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('meeting.starts_at') }}: {{ \App\Domain\Meeting\Support\LocalizedDateHelper::format($meeting->starts_at) }}
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('meeting.ends_at') }}: {{ \App\Domain\Meeting\Support\LocalizedDateHelper::format($meeting->ends_at) }}
                    </p>
                    @if($meeting->description)
                        <p class="text-gray-700 dark:text-gray-200">{{ $meeting->description }}</p>
                    @endif
                    @if($meeting->provider === 'sandbox')
                        <p class="text-sm text-amber-600 dark:text-amber-400">{{ __('meeting.join_sandbox_note') }}</p>
                    @endif
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('meeting.participants') }}:
                        {{ $meeting->participants->map(fn ($p) => $p->user->name ?? $p->user_uuid)->join(', ') ?: '—' }}
                    </p>

                    @if($meeting->status->isActive())
                        @php($myInvitation = $meeting->participants->firstWhere('user_uuid', auth()->user()->uuid))
                        @if($myInvitation)
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ __('meeting.invitation_status') }}: {{ $myInvitation->status->value }}
                            </p>
                            @if($myInvitation->status->value === 'invited')
                                <div class="flex gap-2 pt-2">
                                    <form method="POST" action="{{ route('dashboard.meetings.respond', $meeting) }}">
                                        @csrf
                                        <input type="hidden" name="status" value="accepted">
                                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('meeting.accept') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('dashboard.meetings.respond', $meeting) }}">
                                        @csrf
                                        <input type="hidden" name="status" value="declined">
                                        <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-100">{{ __('meeting.decline') }}</button>
                                    </form>
                                </div>
                            @endif
                        @endif
                    @endif

                    @if($meeting->status->isActive())
                        <form method="POST" action="{{ route('dashboard.meetings.join', $meeting) }}" class="pt-2">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                                {{ __('meeting.join') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            @if($meeting->status->isActive())
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('meeting.reschedule') }}</h3>
                        <form method="POST" action="{{ route('dashboard.meetings.reschedule', $meeting) }}" onsubmit="return confirm('{{ __('meeting.reschedule_confirm') }}')" class="flex flex-wrap gap-4 items-end">
                            @csrf
                            <label class="text-sm text-gray-600 dark:text-gray-300">
                                {{ __('meeting.starts_at') }}
                                <input type="datetime-local" name="starts_at" required class="block mt-1 rounded-md border-gray-300 dark:bg-gray-700 dark:text-gray-100">
                            </label>
                            <label class="text-sm text-gray-600 dark:text-gray-300">
                                {{ __('meeting.ends_at') }}
                                <input type="datetime-local" name="ends_at" required class="block mt-1 rounded-md border-gray-300 dark:bg-gray-700 dark:text-gray-100">
                            </label>
                            <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-100">
                                {{ __('meeting.reschedule') }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('meeting.cancel') }}</h3>
                        <form method="POST" action="{{ route('dashboard.meetings.cancel', $meeting) }}" onsubmit="return confirm('{{ __('meeting.cancel_confirm') }}')">
                            @csrf
                            <input type="text" name="reason" placeholder="{{ __('meeting.reason') }}" maxlength="500" class="rounded-md border-gray-300 dark:bg-gray-700 dark:text-gray-100 w-full max-w-md">
                            <button type="submit" class="mt-2 px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                                {{ __('meeting.cancel') }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('meeting.messages') }}</h3>

                    @if($messages->isEmpty())
                        <p class="text-gray-500 dark:text-gray-400">—</p>
                    @else
                        <ul class="space-y-3 mb-4">
                            @foreach($messages as $message)
                                <li class="text-sm">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $message->user->name ?? '' }}</span>
                                    <span class="text-gray-400 text-xs">{{ $message->created_at->format('Y-m-d H:i') }}</span>
                                    <p class="text-gray-700 dark:text-gray-200">{{ $message->body }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if($meeting->status->isActive())
                        <form method="POST" action="{{ route('dashboard.meetings.messages.store', $meeting) }}" class="flex gap-2">
                            @csrf
                            <input type="text" name="body" required maxlength="2000" placeholder="{{ __('meeting.message_placeholder') }}" class="flex-1 rounded-md border-gray-300 dark:bg-gray-700 dark:text-gray-100">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">{{ __('meeting.send') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
