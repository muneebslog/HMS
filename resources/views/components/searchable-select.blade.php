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
    $modelName = (string) $attributes->wire('model')->value();
    $listId = 'searchable-select-'.substr(md5($modelName !== '' ? $modelName : json_encode($normalizedOptions)), 0, 8);
@endphp

<div
    x-data="{
        open: false,
        search: '',
        typed: false,
        highlight: 0,
        value: null,
        allowCustom: {{ \Illuminate\Support\Js::from((bool) $allowCustom) }},
        options: {{ \Illuminate\Support\Js::from($normalizedOptions) }},
        placeholder: {{ \Illuminate\Support\Js::from($placeholder) }},
        customPrefix: 'custom:',
        get query() {
            return this.typed ? this.search.trim() : '';
        },
        get filtered() {
            const query = this.query.toLowerCase();

            if (! query) {
                return this.options;
            }

            return this.options.filter((option) => {
                const haystack = [option.label, option.keywords ?? ''].join(' ').toLowerCase();

                return haystack.includes(query);
            });
        },
        get canUseCustom() {
            const query = this.query;

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
        get displayText() {
            return this.typed ? this.search : this.selectedLabel;
        },
        openList() {
            this.open = true;
            this.highlight = Math.max(this.filtered.findIndex((option) => String(option.value) === String(this.value)), 0);
            this.$nextTick(() => {
                this.$refs.input?.focus();
                this.scrollToHighlight();
            });
        },
        closeList() {
            this.open = false;
            this.search = '';
            this.typed = false;
            this.highlight = 0;
        },
        onType(text) {
            this.search = text;
            this.typed = true;
            this.highlight = 0;
            this.open = true;
        },
        select(option) {
            this.value = option.value;
            this.closeList();
        },
        selectCustom() {
            const query = this.query;

            if (! this.allowCustom || query === '') {
                return;
            }

            this.value = this.customPrefix + query;
            this.closeList();
        },
        commit() {
            if (! this.open) {
                return;
            }

            if (this.typed && this.query === '') {
                this.value = null;
                this.closeList();

                return;
            }

            const items = this.filtered;

            if (items.length > 0) {
                this.select(items[Math.min(Math.max(this.highlight, 0), items.length - 1)]);

                return;
            }

            if (this.canUseCustom) {
                this.selectCustom();

                return;
            }

            this.closeList();
        },
        moveHighlight(delta) {
            const length = this.filtered.length;

            if (length === 0) {
                return;
            }

            this.highlight = (this.highlight + delta + length) % length;
            this.$nextTick(() => this.scrollToHighlight());
        },
        scrollToHighlight() {
            this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' });
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
    <input
        x-ref="input"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="{{ $listId }}"
        :aria-expanded="open"
        autocomplete="off"
        :placeholder="placeholder"
        :value="displayText"
        @input="onType($event.target.value)"
        @click="if (! open) openList()"
        @keydown.arrow-down.prevent="if (! $event.altKey) { open ? moveHighlight(1) : openList() }"
        @keydown.arrow-up.prevent="if (! $event.altKey && open) moveHighlight(-1)"
        @keydown.enter="if (open) { $event.preventDefault(); commit() }"
        @keydown.tab="if (open) { typed ? commit() : closeList() }"
        @keydown.escape="if (open) { $event.stopPropagation(); closeList() }"
        @blur="closeList()"
        class="h-10 w-full rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white ps-3 pe-9 text-start text-sm text-zinc-700 shadow-xs outline-none placeholder:text-zinc-400 focus:ring-2 focus:ring-accent dark:border-white/10 dark:bg-white/10 dark:text-zinc-300 dark:placeholder:text-zinc-500"
    >

    <button
        type="button"
        tabindex="-1"
        aria-hidden="true"
        @mousedown.prevent
        @click="open ? closeList() : openList()"
        class="absolute inset-y-0 end-0 flex items-center px-3"
    >
        <flux:icon name="chevron-down" class="size-4 shrink-0 text-zinc-400" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
    >
        <ul x-ref="list" id="{{ $listId }}" class="max-h-60 overflow-y-auto py-1" role="listbox">
            <template x-for="(option, index) in filtered" :key="option.value">
                <li role="option" :aria-selected="index === highlight">
                    <button
                        type="button"
                        tabindex="-1"
                        class="flex w-full px-3 py-2 text-start text-sm text-zinc-700 dark:text-zinc-200"
                        :class="index === highlight ? 'bg-zinc-100 dark:bg-white/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5'"
                        :data-active="index === highlight ? true : null"
                        @mouseenter="highlight = index"
                        @mousedown.prevent
                        @click="select(option)"
                        x-text="option.label"
                    ></button>
                </li>
            </template>

            <li x-show="canUseCustom">
                <button
                    type="button"
                    tabindex="-1"
                    class="flex w-full px-3 py-2 text-start text-sm font-medium text-zinc-800 dark:text-zinc-100 hover:bg-zinc-50 dark:hover:bg-white/5"
                    @mousedown.prevent
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
