{{-- Outsourced samples for one rider stage, grouped by case. Expects $cases (lab_invoice_id => items) and $stage ('not_called' | 'waiting'). --}}
<div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
    @forelse ($cases as $labInvoiceId => $items)
        @php($case = $items->first()->labInvoice)
        <div wire:key="rider-{{ $stage }}-{{ $labInvoiceId }}" class="flex flex-col gap-3 py-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $case->patient?->name ?? __('Unknown') }}</span>
                    <span class="font-mono text-xs text-zinc-500">{{ $case->invoice_number }}</span>
                    <span class="text-xs text-zinc-500">{{ $case->created_at->format('d M, g:i A') }}</span>
                </div>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($items as $item)
                        <li wire:key="rider-item-{{ $item->id }}" class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ trim($item->test_name) }}</span>
                            <span class="text-zinc-500">· {{ $item->sample ?: __('sample not set') }}</span>
                            @if ($stage === 'waiting')
                                <span class="text-xs text-zinc-500">{{ __('called :time by :name', ['time' => $item->asked_at?->format('g:i A'), 'name' => $item->askedByUser?->name ?? __('unknown')]) }}</span>
                                <flux:button size="xs" variant="ghost" icon="hand-raised" wire:click="handOver(null, {{ $item->id }})">{{ __('Handed over') }}</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex shrink-0 gap-2">
                @if ($stage === 'not_called')
                    <flux:button size="sm" icon="phone" wire:click="markRiderCalled({{ $labInvoiceId }})">{{ __('Rider called') }}</flux:button>
                @endif
                <flux:button size="sm" :variant="$stage === 'waiting' ? 'primary' : 'filled'" icon="hand-raised" wire:click="handOver({{ $labInvoiceId }})">
                    {{ $items->count() > 1 ? __('Hand over all :count', ['count' => $items->count()]) : __('Hand over') }}
                </flux:button>
            </div>
        </div>
    @empty
        <div class="py-6 text-center text-sm text-zinc-500">
            {{ $stage === 'not_called' ? __('No outsourced samples waiting for a rider call.') : __('No samples waiting for the rider.') }}
        </div>
    @endforelse
</div>
