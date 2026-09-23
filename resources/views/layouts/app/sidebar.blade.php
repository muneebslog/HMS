<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            $pageAccess = app(\App\Services\PageAccessService::class);
            $user = auth()->user();

            $receptionRoutes = [
                'reception.walkin', 'reception.reservation', 'reception.lab-entry', 'reception.lab-samples',
                'reception.vitals', 'reception.procedures',
                'reception.token-flow', 'payout.daily',
            ];
            $managementRoutes = [
                'reception.mr-lookup',
                'reception.invoices', 'payout.doctor', 'management.shift-history', 'management.approvals',
                'admin.drive', 'admin.pdf-print', 'admin.notifications', 'lab.tests', 'lab.cases', 'lab.samples', 'lab.dashboard',
            ];
            $financeRoutes = [
                'admin.finance',
            ];
            $extrasRoutes = [
                'management.crud', 'admin.users', 'admin.employees', 'admin.health-aides',
                'admin.policy-journal', 'admin.notifications', 'admin.reports',
                'admin.sms-logs', 'admin.merge-duplicates', 'admin.sql-runner', 'admin.kanban',
                'admin.monthly-report', 'admin.finance', 'admin.procedure-finances', 'admin.service-stats',
                'admin.medication-deliveries', 'reception.queue', 'lab-api-and-info',
            ];
            $systemRoutes = [
                'display.tokens', 'display.er', 'display.drips', 'display.er_drips', 'display.shift_orders', 'reception.shift',
            ];
            $stationRoutes = [
                'display.er', 'display.drips', 'display.er_drips',
            ];
            $showExtras = $user->isAdmin()
                || $user->isActuallyAdmin()
                || $pageAccess->canAccessAny($user, $extrasRoutes);
        @endphp
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse  />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group class="grid">
                    <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                        <span class="size-2 rounded-full bg-blue-500"></span>
                        {{ __('Platform') }}
                    </div>

                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @unless ($user->isAdmin())
                        @pageAccess('doctor.portal')
                            <flux:sidebar.item icon="user-circle" :href="route('doctor.portal')" :current="request()->routeIs('doctor.portal')" wire:navigate>
                                {{ __('Doctor Portal') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('doctor.medication')
                            <flux:sidebar.item icon="beaker" :href="route('doctor.medication')" :current="request()->routeIs('doctor.medication')" wire:navigate>
                                {{ __('Medication') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('doctor.procedures')
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('doctor.procedures')" :current="request()->routeIs('doctor.procedures')" wire:navigate>
                                {{ __('My Procedures') }}
                            </flux:sidebar.item>
                        @endpageAccess
                    @endunless
                </flux:sidebar.group>

                @if ($pageAccess->canAccessAny($user, $receptionRoutes))
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-teal-600 dark:text-teal-400">
                            <span class="size-2 rounded-full bg-teal-500"></span>
                            {{ __('Reception') }}
                        </div>

                        @pageAccess('reception.walkin')
                            <flux:sidebar.item icon="user-plus" :href="route('reception.walkin')" :current="request()->routeIs('reception.walkin')" wire:navigate>
                                {{ __('Walk-in') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.reservation')
                            <flux:sidebar.item icon="calendar" :href="route('reception.reservation')" :current="request()->routeIs('reception.reservation')" wire:navigate>
                                {{ __('Reservations') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.lab-entry')
                            <flux:sidebar.item icon="beaker" :href="route('reception.lab-entry')" :current="request()->routeIs('reception.lab-entry')" wire:navigate>
                                {{ __('Lab Entry') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.lab-samples')
                            @php($labSamplesCount = \App\Models\LabInvoiceItem::query()->awaitingRider()->count() + \App\Models\LabSampleRetake::query()->open()->count())
                            <flux:sidebar.item icon="truck" :href="route('reception.lab-samples')" :current="request()->routeIs('reception.lab-samples')" :badge="$labSamplesCount ?: null" badge:color="red" wire:navigate>
                                {{ __('Lab Samples') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.vitals')
                            <flux:sidebar.item icon="heart" :href="route('reception.vitals')" :current="request()->routeIs('reception.vitals')" wire:navigate>
                                {{ __('Vitals') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.procedures')
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('reception.procedures')" :current="request()->routeIs('reception.procedures')" wire:navigate>
                                {{ __('Procedures') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.token-flow')
                            <flux:sidebar.item icon="list-bullet" :href="route('reception.token-flow')" :current="request()->routeIs('reception.token-flow')" wire:navigate>
                                {{ __('Token Flow') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('payout.daily')
                            <flux:sidebar.item icon="banknotes" :href="route('payout.daily')" :current="request()->routeIs('payout.daily')" wire:navigate>
                                {{ __('Daily Payout') }}
                            </flux:sidebar.item>
                        @endpageAccess
                    </flux:sidebar.group>
                @endif

                @if ($pageAccess->canAccessAny($user, $managementRoutes))
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                            <span class="size-2 rounded-full bg-amber-500"></span>
                            {{ __('Management') }}
                        </div>

                        @pageAccess('reception.mr-lookup')
                            <flux:sidebar.item icon="magnifying-glass" :href="route('reception.mr-lookup')" :current="request()->routeIs('reception.mr-lookup')" wire:navigate>
                                {{ __('MR Lookup') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.invoices')
                            <flux:sidebar.item icon="document-text" :href="route('reception.invoices')" :current="request()->routeIs('reception.invoices')" wire:navigate>
                                {{ __('Invoices') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('payout.doctor')
                            <flux:sidebar.item icon="banknotes" :href="route('payout.doctor')" :current="request()->routeIs('payout.doctor')" wire:navigate>
                                {{ __('Doctor Payout') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('management.shift-history')
                            <flux:sidebar.item icon="archive-box" :href="route('management.shift-history')" :current="request()->routeIs('management.shift-history')" wire:navigate>
                                {{ __('Shift History') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('management.approvals')
                            <flux:sidebar.item icon="clipboard-document-check" :href="route('management.approvals')" :current="request()->routeIs('management.approvals')" wire:navigate>
                                {{ __('Approvals') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.drive')
                            <flux:sidebar.item icon="cloud" :href="route('admin.drive')" :current="request()->routeIs('admin.drive*')" wire:navigate>
                                {{ __('HMS Drive') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.pdf-print')
                            <flux:sidebar.item icon="printer" :href="route('admin.pdf-print')" :current="request()->routeIs('admin.pdf-print')" wire:navigate>
                                {{ __('PDF Print') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('lab.cases')
                            <flux:sidebar.item icon="clipboard-document-check" :href="route('lab.cases')" :current="request()->routeIs('lab.cases', 'lab.cases.*')" wire:navigate>
                                {{ __('Lab Cases') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('lab.dashboard')
                            <flux:sidebar.item icon="chart-bar" :href="route('lab.dashboard')" :current="request()->routeIs('lab.dashboard')" wire:navigate>
                                {{ __('Lab Dashboard') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('lab.samples')
                            @php($samplesToReceiveCount = \App\Models\LabInvoiceItem::query()->awaitingSample()->count())
                            <flux:sidebar.item icon="inbox-arrow-down" :href="route('lab.samples')" :current="request()->routeIs('lab.samples')" :badge="$samplesToReceiveCount ?: null" badge:color="amber" wire:navigate>
                                {{ __('Sample Receiving') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('lab.tests')
                            <flux:sidebar.item icon="beaker" :href="route('lab.tests')" :current="request()->routeIs('lab.tests', 'lab.tests.*')" wire:navigate>
                                {{ __('Lab Tests') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="list-bullet" :href="route('lab.fields')" :current="request()->routeIs('lab.fields')" wire:navigate>
                                {{ __('Lab Fields') }}
                            </flux:sidebar.item>
                        @endpageAccess
                    </flux:sidebar.group>
                @endif

                @if ($pageAccess->canAccessAny($user, $financeRoutes))
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            {{ __('Finance') }}
                        </div>

                        @pageAccess('admin.finance')
                            <flux:sidebar.item icon="banknotes" :href="route('admin.finance')" :current="request()->routeIs('admin.finance')" wire:navigate>
                                {{ __('Finance') }}
                            </flux:sidebar.item>
                        @endpageAccess
                    </flux:sidebar.group>
                @endif

                @if ($showExtras)
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">
                            <span class="size-2 rounded-full bg-purple-500"></span>
                            {{ __('More') }}
                        </div>

                        <flux:sidebar.item icon="squares-plus" :href="route('extras')" :current="request()->routeIs('extras', 'extras.*')" wire:navigate>
                            {{ __('Extras') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($pageAccess->canAccessAny($user, $systemRoutes))
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400">
                            <span class="size-2 rounded-full bg-rose-500"></span>
                            {{ __('System') }}
                        </div>

                        @pageAccess('display.tokens')
                            <flux:sidebar.item icon="tv" :href="route('display.tokens')" :current="request()->routeIs('display.tokens')" wire:navigate>
                                {{ __('Token Display') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @if ($pageAccess->canAccessAny($user, $stationRoutes))
                            <flux:sidebar.group
                                icon="beaker"
                                expandable
                                :expanded="request()->routeIs(...$stationRoutes)"
                                :heading="__('Stations')"
                                class="grid"
                            >
                                @pageAccess('display.er')
                                    <flux:sidebar.item icon="beaker" :href="route('display.er')" :current="request()->routeIs('display.er')" wire:navigate>
                                        {{ __('ER Station') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                                @pageAccess('display.drips')
                                    <flux:sidebar.item icon="heart" :href="route('display.drips')" :current="request()->routeIs('display.drips')" wire:navigate>
                                        {{ __('Drip Delivery') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                                @pageAccess('display.er_drips')
                                    <flux:sidebar.item icon="squares-2x2" :href="route('display.er_drips')" :current="request()->routeIs('display.er_drips')">
                                        {{ __('ER + Drips') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                            </flux:sidebar.group>
                        @endif
                        @pageAccess('display.shift_orders')
                            <flux:sidebar.item icon="clipboard-document-check" :href="route('display.shift_orders')" :current="request()->routeIs('display.shift_orders')" wire:navigate>
                                {{ __('Shift Orders') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('reception.shift')
                            <flux:sidebar.item icon="clock" :href="route('reception.shift')" :current="request()->routeIs('reception.shift')" wire:navigate>
                                {{ __('Shift') }}
                            </flux:sidebar.item>
                        @endpageAccess
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />



            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @if (auth()->check() && ! auth()->user()->isUser())
            <livewire:staff-chat />
        @endif

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
