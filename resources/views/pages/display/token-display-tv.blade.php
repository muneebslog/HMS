@extends('layouts.display-tv')

@section('content')
    <style>
        .token-display-root {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100%;
        }

        .token-board {
            display: flex;
            flex: 1;
            flex-direction: column;
            overflow: hidden;
        }

        .token-board-section {
            display: flex;
            flex: 1;
            flex-direction: column;
            min-height: 0;
            padding: 24px;
        }

        .token-board-section-waiting {
            border-bottom: 1px solid #27272a;
        }

        .token-board-heading {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            color: #ffffff;
        }

        .token-board-subheading {
            margin: 8px 0 0 0;
            font-size: 16px;
            color: #a1a1aa;
        }

        .token-board-urdu {
            margin: 8px 0 0 0;
            font-size: 22px;
            color: #d4d4d8;
        }

        .token-board-body {
            display: flex;
            flex: 1;
            min-height: 0;
            margin-top: 20px;
            gap: 16px;
        }

        .token-board-side {
            display: flex;
            flex-direction: column;
            width: 110px;
            gap: 12px;
            overflow-y: auto;
        }

        .token-board-side-wide {
            width: 180px;
        }

        .token-board-side-left {
            border-right: 1px solid #27272a;
            padding-right: 16px;
        }

        .token-board-side-right {
            border-left: 1px solid #27272a;
            padding-left: 16px;
        }

        .token-board-main {
            display: flex;
            flex: 1;
            flex-wrap: wrap;
            align-content: flex-start;
            gap: 16px;
            overflow-y: auto;
        }

        .token-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 96px;
            height: 96px;
            padding: 0;
            font-size: 40px;
            font-weight: 900;
            color: #ffffff;
            background-color: #27272a;
            border: 2px solid #52525b;
            border-radius: 16px;
        }

        .token-chip-serving {
            width: 112px;
            height: 112px;
            font-size: 48px;
            color: #6ee7b7;
            background-color: #052e16;
            border-color: #10b981;
        }

        .token-chip-file {
            width: 100%;
            height: 72px;
            font-size: 28px;
            color: #fde68a;
            background-color: #451a03;
            border-color: #f59e0b;
        }

        .token-side-label {
            margin: 0 0 4px 0;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #a1a1aa;
        }

        .token-empty {
            margin: 0;
            font-size: 20px;
            color: #71717a;
        }

        .single-token-display {
            display: flex;
            flex: 1;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px;
            text-align: center;
        }

        .single-token-number {
            margin: 32px 0 0;
            font-size: min(32vw, 360px);
            line-height: 0.9;
            font-weight: 900;
            color: #6ee7b7;
        }

        .single-token-name {
            max-width: 1100px;
            margin: 32px 0 0;
            font-size: min(7vw, 88px);
            line-height: 1.1;
            font-weight: 700;
            color: #ffffff;
        }

        .single-token-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            margin-top: 48px;
        }

        .token-control-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 220px;
            padding: 20px 40px;
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
            background-color: #27272a;
            border: 2px solid #52525b;
            border-radius: 16px;
        }

        .token-control-button-primary {
            color: #052e16;
            background-color: #34d399;
            border-color: #10b981;
        }

        @media (max-width: 1023px) {
            .token-board-heading {
                font-size: 24px;
            }

            .token-chip {
                width: 72px;
                height: 72px;
                font-size: 28px;
            }

            .token-chip-serving {
                width: 88px;
                height: 88px;
                font-size: 36px;
            }

            .token-board-side {
                width: 80px;
            }

            .token-board-side-wide {
                width: 140px;
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
                    <a
                        href="{{ route('display.tokens.tv') }}"
                        style="display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px;"
                    >
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
            @if ($usesSingleTokenLayout)
                <main class="single-token-display">
                    <h2 class="token-board-heading">{{ __('Now Serving') }}</h2>
                    <p class="token-board-urdu" dir="rtl">اب باری ہے</p>

                    @if ($currentToken)
                        <p class="single-token-number">{{ $currentToken->token_number }}</p>
                        <p class="single-token-name">{{ $currentToken->patient?->name ?? __('Unknown patient') }}</p>
                    @else
                        <p class="token-empty" style="margin-top: 48px; font-size: 48px;">{{ __('No token being served') }}</p>
                    @endif

                    <div class="single-token-controls">
                        <form method="POST" action="{{ route('display.tokens.tv.back') }}">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <button type="submit" class="token-control-button">
                                {{ __('Previous Token') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('display.tokens.tv.next') }}">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                            <button type="submit" class="token-control-button token-control-button-primary">
                                {{ __('Next Token') }}
                            </button>
                        </form>
                    </div>
                </main>
            @else
                <div class="token-board">
                <section class="token-board-section token-board-section-waiting">
                    <h2 class="token-board-heading">{{ __('Patients waiting') }}</h2>
                    <p class="token-board-subheading">{{ __('(Arrived)') }}</p>

                    <div class="token-board-body">
                        <aside class="token-board-side token-board-side-left">
                            @forelse ($fileCheckWaitingTokens as $token)
                                <form method="POST" action="{{ route('display.tokens.tv.start-serving') }}">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                                    <input type="hidden" name="token" value="{{ $token->id }}">
                                    <button type="submit" class="token-chip token-chip-file">
                                        {{ $token->token_number }}
                                    </button>
                                </form>
                            @empty
                                <p class="token-empty">—</p>
                            @endforelse
                        </aside>

                        <div class="token-board-main">
                            @forelse ($waitingTokens as $token)
                                <form method="POST" action="{{ route('display.tokens.tv.start-serving') }}">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                                    <input type="hidden" name="token" value="{{ $token->id }}">
                                    <button type="submit" class="token-chip">
                                        {{ $token->token_number }}
                                    </button>
                                </form>
                            @empty
                                <p class="token-empty">{{ __('No patients waiting.') }}</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="token-board-section">
                    <h2 class="token-board-heading">{{ __('Now Serving') }}</h2>
                    <p class="token-board-urdu" dir="rtl">اب باری ہے</p>

                    <div class="token-board-body">
                        <div class="token-board-main">
                            @forelse ($servingTokens as $token)
                                <form method="POST" action="{{ route('display.tokens.tv.mark-served') }}">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                                    <input type="hidden" name="token" value="{{ $token->id }}">
                                    <button type="submit" class="token-chip token-chip-serving">
                                        {{ $token->token_number }}
                                    </button>
                                </form>
                            @empty
                                <p class="token-empty">{{ __('No token being served') }}</p>
                            @endforelse
                        </div>

                        <aside class="token-board-side token-board-side-wide token-board-side-right">
                            <p class="token-side-label">{{ __('File check for patients') }}</p>

                            @forelse ($fileCheckServingTokens as $token)
                                <form method="POST" action="{{ route('display.tokens.tv.mark-served') }}">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $selectedQueue->id }}">
                                    <input type="hidden" name="token" value="{{ $token->id }}">
                                    <button type="submit" class="token-chip token-chip-file">
                                        {{ $token->token_number }}
                                    </button>
                                </form>
                            @empty
                                <p class="token-empty">—</p>
                            @endforelse
                        </aside>
                    </div>
                </section>
                </div>
            @endif

        @endif
    </div>
@endsection
