@extends('layouts.display-tv')

@section('content')
    @php
        $patientName = fn ($token) => $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-';
    @endphp

    <div style="display: flex; flex-direction: column; height: 100vh; width: 100%; overflow: hidden;">
        {{-- Top bar --}}
        <div style="display: flex; align-items: center; justify-content: space-between; height: 64px; padding: 0 24px; background-color: #18181b; border-bottom: 1px solid #27272a;">
            <div style="display: flex; align-items: center;">
                <h1 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">
                    {{ config('app.name', 'HMS') }}
                </h1>

                @if ($selectedQueue)
                    <span style="display: inline-flex; align-items: center; margin-left: 16px; padding: 6px 12px; font-size: 14px; font-weight: 500; color: #14532d; background-color: #86efac; border-radius: 6px;">
                        {{ $selectedQueue->service->name }}
                    </span>

                    @if ($selectedQueue->doctor)
                        <p style="margin: 0 0 0 16px; font-size: 16px; color: #a1a1aa;">
                            {{ $selectedQueue->doctor->name }}
                        </p>
                    @endif
                @endif
            </div>

            @if ($selectedQueue)
                <a
                    href="{{ route('display.tokens.tv') }}"
                    style="display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px;"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                        <path fill-rule="evenodd" d="M14 8a.75.75 0 0 1-.75.75H4.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L4.56 7.25h8.69A.75.75 0 0 1 14 8Z" clip-rule="evenodd"/>
                    </svg>
                    {{ __('Switch Queue') }}
                </a>
            @endif
        </div>

        {{-- Queue selector --}}
        @if (! $selectedQueue)
            <div style="display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center; padding: 32px;">
                <h2 style="margin: 0 0 32px 0; font-size: 32px; font-weight: 600; color: #ffffff;">
                    {{ __('Select a Queue') }}
                </h2>

                @if ($queues->isEmpty())
                    <p style="margin: 0; font-size: 20px; color: #a1a1aa;">
                        {{ __('No open queues available.') }}
                    </p>
                @else
                    <div style="display: flex; flex-wrap: wrap; justify-content: center; width: 100%; max-width: 1200px;">
                        @foreach ($queues as $queue)
                            <form method="POST" action="{{ route('display.tokens.tv.select') }}" style="display: inline;">
                                @csrf
                                <input type="hidden" name="queue" value="{{ $queue->id }}">
                                <input type="hidden" name="sidebar" value="{{ auth()->check() ? '1' : '0' }}">

                                <button
                                    type="submit"
                                    style="display: flex; flex-direction: column; align-items: flex-start; width: 320px; margin: 12px; padding: 24px; text-align: left; background-color: #18181b; border: 1px solid #3f3f46; border-radius: 16px; color: inherit;"
                                >
                                    <h3 style="margin: 0 0 8px 0; font-size: 24px; font-weight: 700; color: #ffffff;">
                                        {{ $queue->service->name }}
                                    </h3>

                                    <p style="margin: 0; font-size: 18px; color: #a1a1aa;">
                                        {{ $queue->doctor?->name ?? __('No doctor assigned') }}
                                    </p>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            {{-- Token display --}}
            <div style="display: flex; flex: 1; position: relative; overflow: hidden;">
                <div style="display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center; padding: 32px;">
                    @if ($currentToken)
                        <div style="text-align: center; color: #ffffff;">
                            <p style="margin: 0 0 16px 0; font-size: 20px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; color: #a1a1aa;">
                                {{ __('Now Serving') }}
                            </p>

                            <div style="font-size: 180px; font-weight: 900; line-height: 1;">
                                {{ $currentToken->token_number }}
                            </div>

                            <div style="margin-top: 24px; font-size: 48px; font-weight: 600;">
                                {{ $patientName($currentToken) }}
                            </div>

                            @if ($selectedQueue->doctor)
                                <div style="margin-top: 12px; font-size: 24px; color: #a1a1aa;">
                                    {{ $selectedQueue->doctor->name }}
                                </div>
                            @endif
                        </div>
                    @else
                        <div style="text-align: center;">
                            <p style="margin: 0; font-size: 48px; font-weight: 600; color: #d4d4d8;">
                                {{ __('No token being served') }}
                            </p>

                            <p style="margin: 16px 0 0 0; font-size: 24px; color: #71717a;">
                                {{ __('Use the controls to call the next token.') }}
                            </p>
                        </div>
                    @endif
                </div>

                {{-- Upcoming tokens sidebar --}}
                @if ($sidebarOpen)
                    <div style="display: flex; flex-direction: column; width: 320px; padding: 24px; background-color: rgba(24, 24, 27, 0.5); border-left: 1px solid #27272a;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
                            <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                                {{ __('Upcoming') }}
                            </h3>

                            @auth
                                <a
                                    href="{{ route('display.tokens.tv.toggle-sidebar', ['queue' => $selectedQueue->id, 'sidebar' => '0']) }}"
                                    style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; color: #a1a1aa; background-color: transparent; border: 1px solid #3f3f46; border-radius: 6px;"
                                    title="{{ __('Collapse sidebar') }}"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                        <path fill-rule="evenodd" d="M11.78 4.22a.75.75 0 0 1 0 1.06L8.06 8l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.24-4.24a.75.75 0 0 1 0-1.06l4.24-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
                                        <path d="M3.5 8a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5A.75.75 0 0 1 3.5 8Z"/>
                                    </svg>
                                </a>
                            @endauth
                        </div>

                        <div style="display: flex; flex: 1; flex-direction: column; overflow-y: auto;">
                            @forelse ($upcomingTokens as $token)
                                <div
                                    style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 16px; background-color: #18181b; border: 1px solid #27272a; border-radius: 12px;"
                                >
                                    <div>
                                        <div style="font-size: 24px; font-weight: 700; color: #ffffff;">
                                            {{ $token->token_number }}
                                        </div>
                                        <div style="font-size: 14px; color: #a1a1aa;">
                                            {{ $patientName($token) }}
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p style="margin: 0; font-size: 16px; color: #71717a;">
                                    {{ __('No waiting tokens.') }}
                                </p>
                            @endforelse
                        </div>
                    </div>
                @endif

                {{-- Controls --}}
                @auth
                    <div style="position: absolute; right: 24px; bottom: 24px;">
                        @if (! $sidebarOpen)
                            <a
                                href="{{ route('display.tokens.tv.toggle-sidebar', ['queue' => $selectedQueue->id, 'sidebar' => '1']) }}"
                                style="display: inline-flex; align-items: center; margin-right: 12px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #3f3f46; border: none; border-radius: 8px;"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 0 1 1.06 0l4.24 4.24a.75.75 0 0 1 0 1.06l-4.24 4.24a.75.75 0 1 1-1.06-1.06L7.94 8 4.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    <path d="M12.5 8a.75.75 0 0 1-.75.75h-5a.75.75 0 0 1 0-1.5h5A.75.75 0 0 1 12.5 8Z"/>
                                </svg>
                                {{ __('Show Upcoming') }}
                            </a>
                        @endif

                        <form method="POST" action="{{ route('display.tokens.tv.recall') }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <input type="hidden" name="sidebar" value="{{ $sidebarOpen ? '1' : '0' }}">
                            <button
                                type="submit"
                                @disabled(! $currentToken)
                                style="display: inline-flex; align-items: center; margin-right: 12px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px; opacity: {{ $currentToken ? '1' : '0.5' }};"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path d="M8.5 1.5a.5.5 0 0 0-1 0v3.879L5.479 3.358a.5.5 0 1 0-.707.707l2.828 2.828a.5.5 0 0 0 .707 0l2.828-2.828a.5.5 0 1 0-.707-.707L8.5 5.379V1.5Z"/>
                                    <path d="M12.5 9a.5.5 0 0 1-.5.5H8.5v2.5a.5.5 0 0 1-1 0V9.5H5a.5.5 0 0 1 0-1h8a.5.5 0 0 1 .5.5Z"/>
                                </svg>
                                {{ __('Recall') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('display.tokens.tv.skip') }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <input type="hidden" name="sidebar" value="{{ $sidebarOpen ? '1' : '0' }}">
                            <button
                                type="submit"
                                @disabled(! $currentToken)
                                style="display: inline-flex; align-items: center; margin-right: 12px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #dc2626; border: none; border-radius: 8px; opacity: {{ $currentToken ? '1' : '0.5' }};"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd"/>
                                </svg>
                                {{ __('Skip') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('display.tokens.tv.next') }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <input type="hidden" name="sidebar" value="{{ $sidebarOpen ? '1' : '0' }}">
                            <button
                                type="submit"
                                style="display: inline-flex; align-items: center; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px;"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd"/>
                                </svg>
                                {{ __('Next') }}
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        @endif
    </div>
@endsection
