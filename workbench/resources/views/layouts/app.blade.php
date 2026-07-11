<!DOCTYPE html>
<html lang="en" data-theme="quickbooks">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('accounting.company_name', 'Accounting') }}</title>

    @accountingVite

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('accountingSidebar', {
                isMobileOpen: false,

                toggleMobileOpen() {
                    this.isMobileOpen = !this.isMobileOpen;
                },

                setMobileOpen(value) {
                    this.isMobileOpen = value;
                },
            });
        });
    </script>

    @livewireStyles
</head>
<body x-data class="min-h-screen bg-base-200 font-sans text-base-content antialiased">
    @include('accounting::layouts.shell')

    @stack('modals')
    @livewireScripts
</body>
</html>
