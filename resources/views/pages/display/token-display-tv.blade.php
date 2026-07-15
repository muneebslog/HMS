@extends('layouts.display-tv')

@section('content')
    @php
        $patientName = fn ($token) => $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-';

        $arrivalBadge = function (\App\Models\QueueToken $token): array {
            if ($token->status === 'reserved') {
                return ['label' => __('Not Arrived'), 'color' => '#ef4444', 'background' => '#450a0a'];
            }

            return ['label' => __('Arrived'), 'color' => '#22c55e', 'background' => '#052e16'];
        };
    @endphp

    <style>
        .token-display-root {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100%;
        }

        .token-display-main {
            display: flex;
            flex: 1;
            position: relative;
            overflow: hidden;
        }

        .token-display-current {
            display: flex;
            flex: 1;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px;
            text-align: center;
        }

        .token-display-token {
            font-size: 180px;
            font-weight: 900;
            line-height: 1;
        }

        .token-display-patient {
            margin-top: 24px;
            font-size: 48px;
            font-weight: 600;
        }

        .token-display-sidebar {
            display: flex;
            flex-direction: column;
            width: 320px;
            padding: 24px;
            background-color: rgba(24, 24, 27, 0.5);
            border-left: 1px solid #27272a;
        }

        .token-display-controls {
            position: absolute;
            right: 24px;
            bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 12px;
        }

        .token-pin-overlay {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(9, 9, 11, 0.95);
        }

        .token-pin-box {
            width: 100%;
            max-width: 360px;
            padding: 32px;
            text-align: center;
            background-color: #18181b;
            border: 1px solid #27272a;
            border-radius: 16px;
        }

        .token-pin-input {
            width: 100%;
            padding: 16px;
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.25em;
            color: #ffffff;
            background-color: #27272a;
            border: 1px solid #3f3f46;
            border-radius: 12px;
        }

        .token-pin-error {
            margin-top: 12px;
            font-size: 14px;
            color: #ef4444;
        }

        .token-arrival-badge {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        @media (max-width: 1023px) {
            .token-display-main {
                flex-direction: column;
                overflow-y: auto;
            }

            .token-display-current {
                padding-bottom: 96px;
            }

            .token-display-sidebar {
                width: 100%;
                border-left: none;
                border-top: 1px solid #27272a;
            }

            .token-display-token {
                font-size: 96px;
            }

            .token-display-patient {
                font-size: 28px;
            }

            .token-display-controls {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                padding: 12px;
                background-color: rgba(9, 9, 11, 0.95);
                border-top: 1px solid #27272a;
                justify-content: center;
            }
        }

        @media (max-width: 639px) {
            .token-display-token {
                font-size: 64px;
            }

            .token-display-patient {
                font-size: 22px;
            }

            .token-display-controls {
                gap: 8px;
            }
        }
    </style>

    <div class="token-display-root">
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
                <div style="display: flex; align-items: center; gap: 12px;">
                    @if ($pinVerified)
                        <a
                            href="{{ route('display.tokens.tv.lock', ['queue' => $selectedQueue->id]) }}"
                            style="display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px;"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                <path fill-rule="evenodd" d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1ZM4.5 8a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5A.75.75 0 0 1 4.5 8Z" clip-rule="evenodd"/>
                            </svg>
                            {{ __('Lock') }}
                        </a>
                    @endif

                    <a
                        href="{{ route('display.tokens.tv') }}"
                        style="display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px;"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                            <path fill-rule="evenodd" d="M14 8a.75.75 0 0 1-.75.75H4.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L4.56 7.25h8.69A.75.75 0 0 1 14 8Z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Switch Queue') }}
                    </a>
                </div>
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
                                <input type="hidden" name="sidebar" value="{{ $pinVerified ? '1' : '0' }}">

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
            <div class="token-display-main">
                <div class="token-display-current">
                    @if ($currentToken)
                        <div style="color: #ffffff;">
                            <p style="margin: 0 0 16px 0; font-size: 20px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; color: #a1a1aa;">
                                {{ __('Now Serving') }}
                            </p>

                            <div class="token-display-token">
                                {{ $currentToken->token_number }}
                            </div>

                            <div class="token-display-patient">
                                {{ $patientName($currentToken) }}
                            </div>

                            @if ($selectedQueue->doctor)
                                <div style="margin-top: 12px; font-size: 24px; color: #a1a1aa;">
                                    {{ $selectedQueue->doctor->name }}
                                </div>
                            @endif
                        </div>
                    @else
                        <div>
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
                    <div class="token-display-sidebar">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
                            <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                                {{ __('Upcoming') }}
                            </h3>

                            @if ($pinVerified)
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
                            @endif
                        </div>

                        <div style="display: flex; flex: 1; flex-direction: column; overflow-y: auto; gap: 16px;">
                            @forelse ($upcomingTokens as $token)
                                @php
                                    $badge = $arrivalBadge($token);
                                @endphp

                                <div
                                    style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background-color: #18181b; border: 1px solid #27272a; border-radius: 12px;"
                                >
                                    <div>
                                        <div style="font-size: 24px; font-weight: 700; color: #ffffff;">
                                            {{ $token->token_number }}
                                        </div>
                                        <div style="font-size: 14px; color: #a1a1aa;">
                                            {{ $patientName($token) }}
                                        </div>
                                        <span
                                            class="token-arrival-badge"
                                            style="color: {{ $badge['color'] }}; background-color: {{ $badge['background'] }};"
                                        >
                                            {{ $badge['label'] }}
                                        </span>
                                    </div>
                                </div>
                            @empty
                                <p style="margin: 0; font-size: 16px; color: #71717a;">
                                    {{ __('No upcoming tokens.') }}
                                </p>
                            @endforelse
                        </div>
                    </div>
                @endif

                {{-- Controls --}}
                @if ($pinVerified)
                    <div class="token-display-controls">
                        @if (! $sidebarOpen)
                            <a
                                href="{{ route('display.tokens.tv.toggle-sidebar', ['queue' => $selectedQueue->id, 'sidebar' => '1']) }}"
                                style="display: inline-flex; align-items: center; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #3f3f46; border: none; border-radius: 8px;"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 0 1 1.06 0l4.24 4.24a.75.75 0 0 1 0 1.06l-4.24 4.24a.75.75 0 1 1-1.06-1.06L7.94 8 4.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    <path d="M12.5 8a.75.75 0 0 1-.75.75h-5a.75.75 0 0 1 0-1.5h5A.75.75 0 0 1 12.5 8Z"/>
                                </svg>
                                {{ __('Show Upcoming') }}
                            </a>
                        @endif

                        <form method="POST" action="{{ route('display.tokens.tv.back') }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <input type="hidden" name="sidebar" value="{{ $sidebarOpen ? '1' : '0' }}">
                            <button
                                type="submit"
                                @disabled(! $currentToken)
                                style="display: inline-flex; align-items: center; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px; opacity: {{ $currentToken ? '1' : '0.5' }};"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="margin-right: 8px;">
                                    <path fill-rule="evenodd" d="M14 8a.75.75 0 0 1-.75.75H4.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L4.56 7.25h8.69A.75.75 0 0 1 14 8Z" clip-rule="evenodd"/>
                                </svg>
                                {{ __('Back') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('display.tokens.tv.recall') }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <input type="hidden" name="sidebar" value="{{ $sidebarOpen ? '1' : '0' }}">
                            <button
                                type="submit"
                                @disabled(! $currentToken)
                                style="display: inline-flex; align-items: center; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px; opacity: {{ $currentToken ? '1' : '0.5' }};"
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
                                style="display: inline-flex; align-items: center; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #dc2626; border: none; border-radius: 8px; opacity: {{ $currentToken ? '1' : '0.5' }};"
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
                @else
                    {{-- PIN prompt overlay --}}
                    <div class="token-pin-overlay">
                        <div class="token-pin-box">
                            <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                                {{ __('Enter PIN') }}
                            </h2>

                            <p style="margin: 0 0 24px 0; font-size: 14px; color: #a1a1aa;">
                                {{ __('Enter the 4-digit PIN to unlock the controls.') }}
                            </p>

                            <form method="POST" action="{{ route('display.tokens.tv.verify-pin') }}">
                                @csrf
                                <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">

                                <input
                                    type="password"
                                    name="pin"
                                    inputmode="numeric"
                                    pattern="[0-9]{4}"
                                    maxlength="4"
                                    class="token-pin-input"
                                    placeholder="----"
                                    required
                                    autofocus
                                >

                                @error('pin')
                                    <p class="token-pin-error">{{ $message }}</p>
                                @enderror

                                <button
                                    type="submit"
                                    style="width: 100%; margin-top: 24px; padding: 14px; font-size: 16px; font-weight: 600; color: #ffffff; background-color: #2563eb; border: none; border-radius: 10px;"
                                >
                                    {{ __('Unlock') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection
