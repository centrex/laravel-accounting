{{--
    QuickBooks-style app shell: a docked, always-labeled sidebar on desktop + a slide-in drawer
    on mobile, plus a sticky header. Included from a host layout's <body> (see
    workbench/resources/views/layouts/app.blade.php for the reference wiring — the Alpine
    `accountingSidebar` store must be registered in <head> before this include runs, and the
    ancestor <body> must carry x-data for the store bindings below to be reactive).
--}}
<div class="min-h-screen bg-base-200">
    @include('accounting::partials.sidebar')

    <div class="min-w-0 lg:ml-72">
        @include('accounting::partials.header')

        <main class="mx-auto max-w-(--breakpoint-2xl) px-4 py-6 lg:px-6">
            {{ $slot }}
        </main>
    </div>
</div>
