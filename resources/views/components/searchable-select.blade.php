@props([
    'options' => [],
    'placeholder' => null,
    'allowCustom' => false,
])

@php
    $placeholder ??= __('Search...');
    $normalizedOptions = collect($options)
        ->map(fn ($option) => [
            'value' => is_array($option) ? ($option['value'] ?? null) : $option->value,
            'label' => is_array($option) ? ($option['label'] ?? '') : $option->label,
            'keywords' => is_array($option) ? ($option['keywords'] ?? '') : ($option->keywords ?? ''),
        ])
        ->values()
        ->all();
@endphp

<div
    x-data="{
        open: false,
        search: '',
        highlight: 0,
        value: null,
        allowCustom: {{ \Illuminate\Support\Js::from((bool) $allowCustom) }},
        options: {{ \Illuminate\Support\Js::from($normalizedOptions) }},
        placeholder: {{ \Illuminate\Support\Js::from($placeholder) }},
        customPrefix: 'custom:',
        get filtered() {
            const query = this.search.trim().toLowerCase();

            if (! query) {
                return this.options;
            }

            return this.options.filter((option) => {
                const haystack = [option.label, option.keywords ?? ''].join(' ').toLowerCase();

                return haystack.includes(query);
            });
        },
        get canUseCustom() {
            const query = this.search.trim();

            if (! this.allowCustom || query === '') {
                return false;
            }

            return ! this.options.some((option) => String(option.label).toLowerCase() === query.toLowerCase());
        },
        get selectedLabel() {
            const raw = this.value;

            if (raw === null || raw === '') {
                return '';
            }

            const match = this.options.find((option) => String(option.value) === String(raw));

            if (match) {
                return match.label;
            }

            const text = String(raw);

            if (text.startsWith('custom-injection:')) {
                return text.slice('custom-injection:'.length);
            }

            return text.startsWith(this.customPrefix) ? text.slice(this.customPrefix.length) : text;
        },
        openList() {
            this.open = true;
            this.search = '';
            this.highlight = 0;
            this.$nextTick(() => this.$refs.search?.focus());
        },
        closeList() {
            this.open = false;
            this.search = '';
            this.highlight = 0;
        },
        select(option) {
            this.value = option.value;
            this.closeList();
        },
        selectCustom() {
            const query = this.search.trim();

            if (! this.allowCustom || query === '') {
                return;
            }

            this.value = this.customPrefix + query;
            this.closeList();
        },
        selectHighlighted() {
            const items = this.filtered;

            if (items.length > 0) {
                const index = Math.min(Math.max(this.highlight, 0), items.length - 1);
                this.select(items[index]);

                return;
            }

            this.selectCustom();
        },
        moveHighlight(delta) {
            const length = this.filtered.length;

            if (length === 0) {
                return;
            }

            this.highlight = (this.highlight + delta + length) % length;
            this.$nextTick(() => {
                this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' });
            });
        },
    }"
    x-modelable="value"
    {{ $attributes->wire('model') }}
    {{ $attributes
        ->except(['options', 'placeholder', 'allowCustom'])
        ->whereDoesntStartWith('wire:model')
        ->class('relative') }}
    @click.outside="closeList()"
    @keydown.escape.window="if (open) closeList()"
    @focusin.window="if (open && ! $el.contains($event.target)) closeList()"
>
    <button
        type="button"
        @click="open ? closeList() : openList()"
        class="flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-start text-sm shadow-xs dark:border-white/10 dark:bg-white/10"
    >
        <span
            class="min-w-0 flex-1 truncate"
            :class="selectedLabel ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-500'"
            x-text="selectedLabel || placeholder"
        ></span>
        <flux:icon name="chevron-down" class="size-4 shrink-0 text-zinc-400" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
    >
        <div class="border-b border-zinc-200 p-2 dark:border-zinc-700">
            <input
                x-ref="search"
                type="text"
                x-model="search"
                @keydown.enter.prevent="selectHighlighted()"
                @keydown.arrow-down.prevent="if (! $event.altKey) moveHighlight(1)"
                @keydown.arrow-up.prevent="if (! $event.altKey) moveHighlight(-1)"
                @input="highlight = 0"
                class="h-9 w-full rounded-md border border-zinc-200 bg-white px-3 text-sm text-zinc-700 outline-none focus:ring-2 focus:ring-accent dark:border-white/10 dark:bg-white/10 dark:text-zinc-300"
                :placeholder="placeholder"
                autocomplete="off"
            >
        </div>

        <ul x-ref="list" class="max-h-60 overflow-y-auto py-1" role="listbox">
            <template x-for="(option, index) in filtered" :key="option.value">
                <li role="option">
                    <button
                        type="button"
                        class="flex w-full px-3 py-2 text-start text-sm text-zinc-700 dark:text-zinc-200"
                        :class="index === highlight ? 'bg-zinc-100 dark:bg-white/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5'"
                        :data-active="index === highlight ? true : null"
                        @mouseenter="highlight = index"
                        @click="select(option)"
                        x-text="option.label"
                    ></button>
                </li>
            </template>

            <li x-show="canUseCustom">
                <button
                    type="button"
                    class="flex w-full px-3 py-2 text-start text-sm font-medium text-zinc-800 dark:text-zinc-100 hover:bg-zinc-50 dark:hover:bg-white/5"
                    @click="selectCustom()"
                >
                    <span x-text="{{ \Illuminate\Support\Js::from(__('Write')) }} + ' “' + search.trim() + '”'"></span>
                </button>
            </li>

            <li x-show="filtered.length === 0 && ! canUseCustom" class="px-3 py-2 text-sm text-zinc-500">
                {{ __('No results found') }}
            </li>
        </ul>
    </div>
</div>
