@php($quickCreate = collect(\Centrex\Accounting\Support\AccountingWorkspace::quickCreateActions())->filter(fn ($a) => $a['available'])->values())

<header class="sticky top-0 z-999 flex min-h-16 w-full items-center gap-3 border-b border-base-300 bg-base-100 px-4 py-3 lg:px-6">
    {{-- Mobile: open sidebar overlay + brand --}}
    <button
        type="button"
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-base-content/60 transition hover:bg-base-200 lg:hidden"
        @click="$store.accountingSidebar.toggleMobileOpen()"
        aria-label="Open sidebar"
    >
        <x-tallui-icon name="o-bars-3" class="h-5 w-5" />
    </button>
    <a href="{{ route('accounting.dashboard') }}" class="lg:hidden">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-primary-content">
            <x-tallui-icon name="o-calculator" class="h-4 w-4" />
        </span>
    </a>

    <div class="flex-1"></div>

    <div class="flex items-center gap-2">
        {{-- "+ New" quick-create --}}
        @if ($quickCreate->isNotEmpty())
            <div x-data="{ open: false }" class="relative">
                <button
                    type="button"
                    @click="open = !open"
                    class="btn btn-primary btn-sm gap-1.5"
                >
                    <x-tallui-icon name="o-plus" class="h-4 w-4" />
                    New
                </button>
                <div
                    x-show="open"
                    x-transition.opacity
                    @click.outside="open = false"
                    class="absolute right-0 top-11 z-50 w-72 rounded-2xl border border-base-300 bg-base-100 p-2 shadow-theme-xl"
                    style="display: none;"
                >
                    @foreach ($quickCreate as $action)
                        <a href="{{ $action['url'] }}" class="flex items-start gap-3 rounded-xl px-3 py-2.5 transition hover:bg-base-200">
                            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                <x-tallui-icon :name="$action['icon']" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-base-content">{{ $action['label'] }}</span>
                                <span class="block text-xs leading-5 text-base-content/50">{{ $action['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- User dropdown --}}
        @auth
            <div x-data="{ open: false }" class="relative">
                <button
                    type="button"
                    @click="open = !open"
                    class="inline-flex items-center gap-2 rounded-full border border-base-300 bg-base-100 py-1 pl-2 pr-1 transition hover:border-base-content/20"
                >
                    <span class="hidden flex-col text-right sm:flex">
                        <span class="text-xs font-semibold leading-tight text-base-content">{{ auth()->user()->name }}</span>
                    </span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-content">
                        {{ strtoupper(mb_substr(auth()->user()->name ?? '?', 0, 1)) }}
                    </span>
                </button>
                <div
                    x-show="open"
                    x-transition.opacity
                    @click.outside="open = false"
                    class="absolute right-0 top-12 z-50 w-56 rounded-2xl border border-base-300 bg-base-100 p-2 shadow-theme-xl"
                    style="display: none;"
                >
                    @if (Route::has('logout'))
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-error transition hover:bg-error/10">
                                <x-tallui-icon name="o-arrow-left-on-rectangle" class="h-4 w-4" />
                                Sign out
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endauth
    </div>
</header>
