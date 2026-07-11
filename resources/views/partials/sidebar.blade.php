@php($groups = \Centrex\Accounting\Support\AccountingWorkspace::sidebarNavigation())

{{--
    QuickBooks Online's own sidebar is always fully labeled and docked on desktop (it doesn't
    collapse to an icon rail) — so unlike some other shells in this monorepo, there's no
    expand/collapse state here, only a mobile slide-in drawer.
--}}
<aside
    id="accounting-sidebar"
    class="fixed left-0 top-0 z-9999 flex h-screen w-72 -translate-x-full flex-col overflow-hidden border-r border-base-300 bg-base-100 px-4 transition-transform duration-300 ease-in-out lg:translate-x-0"
    :class="$store.accountingSidebar.isMobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
>
    <div class="flex items-center gap-3 pb-7 pt-8">
        <a href="{{ route('accounting.dashboard') }}" class="flex items-center gap-3 overflow-hidden">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-content shadow-theme-sm">
                <x-tallui-icon name="o-calculator" class="h-5 w-5" />
            </span>
            <div>
                <div class="text-sm font-semibold text-base-content">{{ config('accounting.company_name', 'Accounting') }}</div>
                <div class="text-xs text-base-content/50">Books &amp; finances</div>
            </div>
        </a>
    </div>

    <div class="no-scrollbar flex flex-1 flex-col overflow-y-auto pb-4">
        @foreach ($groups as $group)
            @php($visibleItems = collect($group['items'])->filter(fn ($item) => $item['available']))
            @continue($visibleItems->isEmpty())
            <div class="mb-6">
                <h2 class="mb-3 text-xs uppercase leading-5 tracking-widest text-base-content/40">
                    {{ $group['section'] }}
                </h2>

                <ul class="flex flex-col gap-1">
                    @foreach ($visibleItems as $item)
                        <li>
                            <a
                                href="{{ $item['url'] }}"
                                class="menu-item {{ request()->routeIs($item['route']) ? 'menu-item-active' : 'menu-item-inactive' }}"
                            >
                                <span class="{{ request()->routeIs($item['route']) ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                    <x-tallui-icon :name="$item['icon']" class="h-5 w-5" />
                                </span>
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</aside>

<div
    x-show="$store.accountingSidebar.isMobileOpen"
    x-transition.opacity
    @click="$store.accountingSidebar.setMobileOpen(false)"
    class="fixed inset-0 z-9998 bg-neutral/50 lg:hidden"
    style="display: none;"
></div>
