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
                'reception.walkin', 'reception.reservation', 'reception.lab-entry',
                'reception.vitals', 'reception.procedures',
                'reception.token-flow', 'payout.daily',
            ];
            $managementRoutes = [
                'lab-entries', 'reception.mr-lookup',
                'reception.invoices', 'reception.queue', 'payout.doctor', 'management.shift-history', 'management.approvals',
                'admin.drive', 'admin.pdf-print', 'admin.notifications',
            ];
            $administrationRoutes = [
                'management.crud', 'admin.users', 'admin.employees', 'admin.health-aides',
                'admin.policy-journal',
                'admin.notifications', 'admin.reports', 'admin.monthly-report', 'admin.procedure-finances', 'admin.service-stats',
                'admin.medication-deliveries', 'admin.rechecks', 'admin.patient-flow',
                'admin.page-access', 'admin.act-as-role',
            ];
            $devSideRoutes = [
                'admin.sms-logs', 'admin.merge-duplicates', 'admin.sql-runner', 'admin.kanban',
            ];
            $systemRoutes = [
                'display.tokens', 'display.er', 'display.drips', 'display.stock', 'display.er_drips', 'display.shift_orders', 'reception.shift',
            ];
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

                        @pageAccess('lab-entries')
                            <flux:sidebar.item icon="beaker" :href="route('lab-entries')" :current="request()->routeIs('lab-entries')" wire:navigate>
                                {{ __('Lab Entries') }}
                            </flux:sidebar.item>
                        @endpageAccess
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
                        @pageAccess('reception.queue')
                            <flux:sidebar.item icon="queue-list" :href="route('reception.queue')" :current="request()->routeIs('reception.queue')" wire:navigate>
                                {{ __('Queue') }}
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
                    </flux:sidebar.group>
                @endif

                @if ($user->isAdmin())
                    <flux:sidebar.group class="grid">
                        <div class="mb-2 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">
                            <span class="size-2 rounded-full bg-purple-500"></span>
                            {{ __('Administration') }}
                        </div>

                        @pageAccess('management.crud')
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('management.crud')" :current="request()->routeIs('management.crud')" wire:navigate>
                                {{ __('Management CRUD') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.users')
                            <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                                {{ __('Users') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.employees')
                            <flux:sidebar.item icon="identification" :href="route('admin.employees')" :current="request()->routeIs('admin.employees*')" wire:navigate>
                                {{ __('Staff Profiles') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.health-aides')
                            <flux:sidebar.item icon="finger-print" :href="route('admin.health-aides')" :current="request()->routeIs('admin.health-aides')" wire:navigate>
                                {{ __('Health Aides') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @if ($pageAccess->canAccessAny($user, $devSideRoutes))
                            <flux:sidebar.group
                                icon="code-bracket"
                                expandable
                                :expanded="request()->routeIs(...$devSideRoutes)"
                                :heading="__('Dev Side')"
                                class="grid"
                            >
                                @pageAccess('admin.sms-logs')
                                    <flux:sidebar.item icon="chat-bubble-left-right" :href="route('admin.sms-logs')" :current="request()->routeIs('admin.sms-logs')" wire:navigate>
                                        {{ __('SMS Logs') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                                @pageAccess('admin.merge-duplicates')
                                    <flux:sidebar.item icon="user-group" :href="route('admin.merge-duplicates')" :current="request()->routeIs('admin.merge-duplicates')" wire:navigate>
                                        {{ __('Merge Duplicates') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                                @pageAccess('admin.sql-runner')
                                    <flux:sidebar.item icon="command-line" :href="route('admin.sql-runner')" :current="request()->routeIs('admin.sql-runner')" wire:navigate>
                                        {{ __('SQL Runner') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                                @pageAccess('admin.kanban')
                                    <flux:sidebar.item icon="squares-2x2" :href="route('admin.kanban')" :current="request()->routeIs('admin.kanban')" wire:navigate>
                                        {{ __('Kanban') }}
                                    </flux:sidebar.item>
                                @endpageAccess
                            </flux:sidebar.group>
                        @endif
                        @pageAccess('admin.policy-journal')
                            <flux:sidebar.item icon="book-open" :href="route('admin.policy-journal')" :current="request()->routeIs('admin.policy-journal')" wire:navigate>
                                {{ __('Policy Journal') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.notifications')
                            <flux:sidebar.item icon="bell" :href="route('admin.notifications')" :current="request()->routeIs('admin.notifications')" wire:navigate>
                                {{ __('Notifications') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.reports')
                            <flux:sidebar.item icon="inbox-arrow-down" :href="route('admin.reports')" :current="request()->routeIs('admin.reports')" wire:navigate>
                                {{ __('Reports to Admin') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.monthly-report')
                            <flux:sidebar.item icon="chart-bar" :href="route('admin.monthly-report')" :current="request()->routeIs('admin.monthly-report')" wire:navigate>
                                {{ __('Monthly Report') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.procedure-finances')
                            <flux:sidebar.item icon="banknotes" :href="route('admin.procedure-finances')" :current="request()->routeIs('admin.procedure-finances')" wire:navigate>
                                {{ __('Procedure Finances') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.service-stats')
                            <flux:sidebar.item icon="chart-bar" :href="route('admin.service-stats')" :current="request()->routeIs('admin.service-stats')" wire:navigate>
                                {{ __('Service Statistics') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.medication-deliveries')
                            <flux:sidebar.item icon="beaker" :href="route('admin.medication-deliveries')" :current="request()->routeIs('admin.medication-deliveries')" wire:navigate>
                                {{ __('Medication Deliveries') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.rechecks')
                            <flux:sidebar.item icon="clock" :href="route('admin.rechecks')" :current="request()->routeIs('admin.rechecks')" wire:navigate>
                                {{ __('Recheck Timers') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('admin.patient-flow')
                            <flux:sidebar.item icon="map" :href="route('admin.patient-flow')" :current="request()->routeIs('admin.patient-flow')" wire:navigate>
                                {{ __('Patient Flow') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        <flux:sidebar.item icon="key" :href="route('admin.page-access')" :current="request()->routeIs('admin.page-access')" wire:navigate>
                            {{ __('Page Access') }}
                        </flux:sidebar.item>
                        @if ($user->isActuallyAdmin())
                            <flux:sidebar.item icon="eye" :href="route('admin.act-as-role')" :current="request()->routeIs('admin.act-as-role')" wire:navigate>
                                {{ __('Act as Role') }}
                            </flux:sidebar.item>
                        @endif
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
                        @pageAccess('display.stock')
                            <flux:sidebar.item icon="archive-box" :href="route('display.stock')" :current="request()->routeIs('display.stock')" wire:navigate>
                                {{ __('Stock Station') }}
                            </flux:sidebar.item>
                        @endpageAccess
                        @pageAccess('display.er_drips')
                            <flux:sidebar.item icon="squares-2x2" :href="route('display.er_drips')" :current="request()->routeIs('display.er_drips')">
                                {{ __('ER + Drips') }}
                            </flux:sidebar.item>
                        @endpageAccess
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
