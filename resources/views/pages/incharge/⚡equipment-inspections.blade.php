<?php

use App\Enums\EquipmentInspectionArea;
use App\Services\EquipmentInspectionChecklistDefinition;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Equipment Inspections')] class extends Component
{
    /**
     * @return list<EquipmentInspectionArea>
     */
    #[Computed]
    public function areas(): array
    {
        return app(EquipmentInspectionChecklistDefinition::class)->areas();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Equipment Inspections') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Hospital equipment & quality assurance checklists — fill once per area and shift.') }}
            </flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->areas as $area)
                <flux:card wire:key="area-{{ $area->value }}">
                    <div class="flex flex-col gap-4">
                        <div>
                            <flux:heading level="2">{{ $area->label() }}</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">{{ $area->description() }}</flux:text>
                        </div>
                        <div class="flex justify-end">
                            <flux:button
                                variant="primary"
                                :href="route('incharge.equipment-inspections.area', ['area' => $area->value])"
                                wire:navigate
                            >
                                {{ __('Open') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
</div>
