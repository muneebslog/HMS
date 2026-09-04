<x-layouts::app.sidebar :title="$title ?? null">
    <livewire:role-acting-banner />
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
