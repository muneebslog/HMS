@props([
    'eddPreview' => null,
    'lmpGestation' => null,
])

{{-- Gyne obstetric ultrasound fields. Rendered inside a Livewire component using InteractsWithGyneUltrasound. --}}
<div class="flex flex-col gap-5">
    <section class="rounded-xl border border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
        <x-gyne-stepper model="fetusCount" :label="__('Number of babies')" :hint="__('1 = single, 2 = twins')" />
    </section>

    <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <flux:label>{{ __('Gestational age by scan') }}</flux:label>
            <div class="mt-2 grid grid-cols-2 gap-3">
                <flux:input type="number" inputmode="numeric" min="0" max="42" wire:model.live.debounce.400ms="gaWeeks" :placeholder="__('Weeks')" suffix="wk" />
                <flux:input type="number" inputmode="numeric" min="0" max="6" wire:model.live.debounce.400ms="gaDays" :placeholder="__('Days')" suffix="d" />
            </div>
            <flux:error name="gaWeeks" />
            <flux:error name="gaDays" />
            @if ($eddPreview)
                <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    {{ $eddPreview }}
                </p>
            @endif
            @if ($lmpGestation)
                <p class="mt-1 text-xs text-zinc-500">{{ __('By LMP today: :age', ['age' => $lmpGestation]) }}</p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3">
            <flux:input type="number" inputmode="numeric" min="40" max="250" wire:model="fetalHeartRate" :label="__('Heart rate (FHR)')" suffix="bpm" />
            <flux:input type="number" inputmode="numeric" min="10" max="6000" wire:model="efwGrams" :label="__('Weight (EFW)')" suffix="g" />
        </div>
    </section>

    <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:radio.group wire:model="presentation" variant="pills" :label="__('Presentation')">
            @foreach (\App\Enums\FetalPresentation::cases() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:radio.group wire:model="placenta" variant="pills" :label="__('Placenta')">
            @foreach (\App\Enums\PlacentaPosition::cases() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:radio.group wire:model="liquor" variant="pills" :label="__('Liquor')">
            @foreach (\App\Enums\LiquorVolume::cases() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:input type="number" inputmode="decimal" step="0.1" min="0" max="50" wire:model="afi" :label="__('AFI')" suffix="cm" />
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:textarea wire:model="impression" rows="3" :label="__('Impression / other findings')" />
    </section>
</div>
