@props([
    'codeProperty' => 'healthAideCode',
])

<div class="grid gap-4 sm:grid-cols-2">
    <flux:field>
        <flux:label>{{ __('Health Aide Code') }}</flux:label>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="{{ $codeProperty }}"
                    type="password"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                />
            </div>
            <flux:button type="button" variant="outline" wire:click="verifyHealthAideCode" class="shrink-0">
                {{ __('Verify') }}
            </flux:button>
        </div>
        <flux:description>{{ __('Enter the code, verify, then continue. Time is recorded automatically on save.') }}</flux:description>
        <flux:error name="{{ $codeProperty }}" />
    </flux:field>

    {{ $slot }}
</div>

@if ($this->hasVerifiedHealthAide())
    <flux:callout variant="success" icon="check-circle" class="mt-4">
        <flux:callout.heading>{{ __('Health aide verified') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Continuing as :name', ['name' => $this->verifiedHealthAideName]) }}
        </flux:callout.text>
    </flux:callout>
@endif
