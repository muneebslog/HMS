@props([
    'lmpPreview' => null,
])

{{-- Gyne OPD history fields. Rendered inside a Livewire component using InteractsWithGyneHistory. --}}
<div class="flex flex-col gap-5">
    <section class="rounded-xl border border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <x-gyne-stepper model="gravida" :label="__('Gravida')" :hint="__('Total pregnancies')" />
            <x-gyne-stepper model="para" :label="__('Deliveries')" :hint="__('Para')" />
            <x-gyne-stepper model="livingChildren" :label="__('Babies alive')" />
            <x-gyne-stepper model="miscarriages" :label="__('Miscarriages')" />
        </div>
    </section>

    <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input type="month" wire:model="marriedSince" :label="__('Married since')" :max="now()->format('Y-m')" />
        <flux:input type="month" wire:model="lastPregnancyAt" :label="__('Last pregnancy')" :max="now()->format('Y-m')" />
        <div>
            <flux:input type="date" wire:model.live="lmp" :label="__('Last period date (LMP)')" :max="now()->toDateString()" />
            @if ($lmpPreview)
                <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    {{ $lmpPreview }}
                </p>
            @endif
        </div>
    </section>

    <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:checkbox.group wire:model="problems" variant="pills" :label="__('Any problems')">
            @foreach (\App\Enums\GyneProblem::cases() as $problem)
                <flux:checkbox :value="$problem->value" :label="$problem->label()" />
            @endforeach
        </flux:checkbox.group>
        <flux:input wire:model="problemsOther" :placeholder="__('Other problem…')" />
    </section>

    <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:checkbox.group wire:model="operations" variant="pills" :label="__('Previous operations')">
            @foreach (\App\Enums\GyneOperation::cases() as $operation)
                <flux:checkbox :value="$operation->value" :label="$operation->label()" />
            @endforeach
        </flux:checkbox.group>
        <flux:input wire:model="operationsOther" :placeholder="__('Other operation…')" />
    </section>
</div>
