@extends('layouts.display-tv')

@section('content')
    @php
        $statusColors = [
            'reserved' => ['bg' => '#3f3f46', 'text' => '#d8b4fe'],
            'waiting' => ['bg' => '#3f3f46', 'text' => '#fcd34d'],
            'serving' => ['bg' => '#1e3a8a', 'text' => '#93c5fd'],
            'served' => ['bg' => '#14532d', 'text' => '#86efac'],
            'skipped' => ['bg' => '#3f3f46', 'text' => '#a1a1aa'],
        ];

        $patientName = fn ($token) => $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-';
    @endphp

    <style>
        .queue-tv-root {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100%;
        }

        .queue-tv-content {
            flex: 1;
            padding: 32px;
        }

        .queue-tv-card {
            padding: 24px;
            margin-bottom: 24px;
            background-color: #18181b;
            border: 1px solid #27272a;
            border-radius: 16px;
        }

        .queue-tv-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .queue-tv-queue {
            display: flex;
            flex-direction: column;
            width: 280px;
            padding: 20px;
            text-align: left;
            color: inherit;
            text-decoration: none;
            background-color: #27272a;
            border: 1px solid #3f3f46;
            border-radius: 12px;
        }

        .queue-tv-queue:hover {
            background-color: #3f3f46;
        }

        .queue-tv-queue.active {
            border-color: #2563eb;
        }

        .queue-tv-table {
            width: 100%;
            border-collapse: collapse;
        }

        .queue-tv-table th,
        .queue-tv-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #27272a;
        }

        .queue-tv-table th {
            font-size: 14px;
            font-weight: 600;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .queue-tv-table td {
            font-size: 18px;
            color: #ffffff;
        }

        .queue-tv-badge {
            display: inline-block;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
        }

        @media (max-width: 639px) {
            .queue-tv-content {
                padding: 16px;
            }

            .queue-tv-queue {
                width: 100%;
            }

            .queue-tv-table th,
            .queue-tv-table td {
                padding: 12px;
                font-size: 16px;
            }
        }
    </style>

    <div class="queue-tv-root">
        {{-- Top bar --}}
        <div style="display: flex; align-items: center; justify-content: space-between; height: 64px; padding: 0 24px; background-color: #18181b; border-bottom: 1px solid #27272a;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <h1 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">
                    {{ config('app.name', 'HMS') }}
                </h1>

                <span style="font-size: 16px; color: #a1a1aa;">
                    {{ __('Queue') }}
                </span>
            </div>

            <a
                href="{{ route('reception.queue') }}"
                style="display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px; text-decoration: none;"
            >
                {{ __('Modern View') }}
            </a>
        </div>

        <div class="queue-tv-content">
            @if ($currentShift === null)
                <div class="queue-tv-card">
                    <h2 style="margin: 0 0 8px 0; font-size: 24px; font-weight: 600; color: #ffffff;">
                        {{ __('No Open Shift') }}
                    </h2>

                    <p style="margin: 0; font-size: 18px; color: #a1a1aa;">
                        {{ __('Open a shift to view available queues.') }}
                    </p>
                </div>
            @else
                <div class="queue-tv-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h2 style="margin: 0 0 4px 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                                {{ __('Current Shift') }}
                            </h2>

                            <p style="margin: 0; font-size: 16px; color: #a1a1aa;">
                                {{ __('Opened at') }}: {{ $currentShift->opened_at->format('Y-m-d H:i') }}
                            </p>
                        </div>

                        <span style="display: inline-block; padding: 6px 12px; font-size: 14px; font-weight: 500; color: #14532d; background-color: #86efac; border-radius: 6px;">
                            {{ __('Open') }}
                        </span>
                    </div>
                </div>

                <div class="queue-tv-card">
                    <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                        {{ __('Available Queues') }}
                        <span style="margin-left: 8px; font-size: 14px; font-weight: 500; color: #a1a1aa;">
                            ({{ $queues->count() }})
                        </span>
                    </h2>

                    @if ($queues->isEmpty())
                        <p style="margin: 0; font-size: 18px; color: #a1a1aa;">
                            {{ __('No available queues found.') }}
                        </p>
                    @else
                        <div class="queue-tv-grid">
                            @foreach ($queues as $queue)
                                <a
                                    href="{{ route('reception.queue.tv', ['queue' => $queue->id]) }}"
                                    class="queue-tv-queue {{ $selectedQueue && $selectedQueue->id === $queue->id ? 'active' : '' }}"
                                >
                                    <span style="font-size: 18px; font-weight: 700; color: #ffffff;">
                                        {{ $queue->service->name }}
                                    </span>

                                    <span style="font-size: 14px; color: #a1a1aa;">
                                        {{ $queue->doctor?->name ?? __('No doctor assigned') }}
                                    </span>

                                    <span style="margin-top: 8px; font-size: 14px; color: #d4d4d8;">
                                        {{ __('Tokens') }}: {{ $queue->tokens_count }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($selectedQueue)
                    <div class="queue-tv-card">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                            <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                                {{ __('Tokens for :service', ['service' => $selectedQueue->service->name]) }}
                            </h2>

                            @if ($selectedQueue->doctor)
                                <p style="margin: 0; font-size: 16px; color: #a1a1aa;">
                                    {{ __('Doctor') }}: {{ $selectedQueue->doctor->name }}
                                </p>
                            @endif
                        </div>

                        @if ($tokens->isEmpty())
                            <p style="margin: 0; font-size: 18px; color: #a1a1aa;">
                                {{ __('No tokens found for this queue.') }}
                            </p>
                        @else
                            <div style="overflow-x: auto;">
                                <table class="queue-tv-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Token #') }}</th>
                                            <th>{{ __('Patient') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th>{{ __('Created At') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tokens as $token)
                                            @php
                                                $colors = $statusColors[$token->status] ?? ['bg' => '#3f3f46', 'text' => '#ffffff'];
                                            @endphp
                                            <tr>
                                                <td style="font-weight: 700;">{{ $token->token_number }}</td>
                                                <td>{{ $patientName($token) }}</td>
                                                <td>
                                                    <span class="queue-tv-badge" style="background-color: {{ $colors['bg'] }}; color: {{ $colors['text'] }};">
                                                        {{ ucfirst($token->status) }}
                                                    </span>
                                                </td>
                                                <td style="color: #a1a1aa;">{{ $token->created_at->format('Y-m-d H:i') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
@endsection
