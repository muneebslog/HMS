<div class="space-y-4">
    <div x-data="{ phone: '' }" x-effect="if ($wire.hasNoPhone) phone = ''">
        <flux:field>
            <flux:label>{{ __('Phone number') }}</flux:label>
            <flux:input
                type="tel"
                inputmode="numeric"
                maxlength="11"
                pattern="[0-9]{11}"
                placeholder="03XXXXXXXXX"
                autofocus
                x-model="phone"
                x-init="phone = $wire.patientPhone"
                x-on:input="phone = phone.replace(/\D/g, ''); $wire.set('patientPhone', phone)"
                x-bind:required="! $wire.hasNoPhone"
                x-bind:disabled="$wire.hasNoPhone"
                x-bind:class="phone.length === 11 ? 'ring-2 ring-green-500 dark:ring-green-400 border-green-500 dark:border-green-400' : ''"
            />
            <flux:error name="patientPhone" />
        </flux:field>
        @if (\App\Models\AppSetting::allowsHaveNoNumber())
            <br />
            <flux:checkbox wire:model.live="hasNoPhone" label="{{ __('Have no number') }}" class="mt-3" />
        @endif
    </div>

    @if (count($matchedPatients) > 0)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 px-3 py-2 text-sm font-medium dark:border-zinc-700">
                {{ __('Matching patients') }}
            </div>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($matchedPatients as $match)
                    <li
                        wire:key="matched-patient-{{ $match['id'] }}"
                        class="flex items-center justify-between gap-3 px-3 py-2 {{ $selectedPatientId === $match['id'] ? 'bg-green-50 dark:bg-green-950/30' : '' }}"
                    >
                        <div class="min-w-0 text-sm">
                            <div class="font-medium">{{ $match['name'] }}</div>
                            <div class="text-zinc-500">
                                {{ $match['mrn'] ?? __('No MRN') }}
                                @if ($match['age'] !== null)
                                    · {{ $match['age'] }}
                                @endif
                                @if (filled($match['gender']))
                                    · {{ __(ucfirst($match['gender'])) }}
                                @endif
                            </div>
                        </div>
                        <flux:button
                            type="button"
                            size="sm"
                            variant="{{ $selectedPatientId === $match['id'] ? 'primary' : 'outline' }}"
                            wire:click="selectMatchedPatient({{ $match['id'] }})"
                        >
                            {{ $selectedPatientId === $match['id'] ? __('Selected') : __('Select') }}
                        </flux:button>
                    </li>
                @endforeach
            </ul>
            @if (filled($patientPhone) && ! $hasNoPhone)
                <div class="border-t border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <flux:button type="button" size="sm" variant="ghost" wire:click="addNewFamilyMember">
                        {{ __('Add new family member') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @elseif (strlen($patientPhone) === 11 && ! $hasNoPhone)
        <flux:text class="text-sm text-zinc-500">
            {{ __('No patients found for this number. A new patient will be created.') }}
        </flux:text>
    @endif

    @if ($selectedPatientId)
        <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">
            <span class="min-w-0 truncate">{{ __('Using existing patient record') }}@if (filled($patientName)): {{ $patientName }}@endif</span>
            <div class="flex shrink-0 items-center gap-2">
                <flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="openEditPatientModal">
                    {{ __('Edit') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="ghost" wire:click="clearSelectedPatient">
                    {{ __('Clear') }}
                </flux:button>
            </div>
        </div>
    @endif

    <flux:error name="selectedPatientId" />

    <flux:modal name="intake-edit-patient" wire:model="showEditPatientModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit Patient') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Update the patient name and phone number.') }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="editPatientName" type="text" required autofocus />
                <flux:error name="editPatientName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Phone') }}</flux:label>
                <flux:input
                    wire:model="editPatientPhone"
                    type="text"
                    inputmode="numeric"
                    maxlength="11"
                    placeholder="03001234567"
                />
                <flux:error name="editPatientPhone" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeEditPatientModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="saveSelectedPatientDetails">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
