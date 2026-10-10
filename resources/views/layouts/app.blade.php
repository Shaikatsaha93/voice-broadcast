<!DOCTYPE html>
<html lang="en" data-theme="vb">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <script>
        // Runs before first paint: saved choice, else the OS preference. Avoids a light flash in dark mode.
        (function () {
            var t = 'vb';
            try {
                t = localStorage.getItem('vb-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'vb-dark' : 'vb');
            } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
        })();
        function vbToggleTheme() {
            var next = document.documentElement.getAttribute('data-theme') === 'vb-dark' ? 'vb' : 'vb-dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('vb-theme', next); } catch (e) {}
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col">
@php
    $links = [
        ['Campaigns', 'campaigns.index', 'campaigns*', 'bi-megaphone'],
        ['Audio', 'audio.index', 'audio*', 'bi-music-note-beamed'],
        ['Reports', 'reports', 'reports*', 'bi-bar-chart-line'],
    ];
    if (auth()->check() && auth()->user()->isSuperAdmin()) {
        $links = array_merge($links, [
            ['Approvals', 'admin.approvals', 'admin/approvals*', 'bi-check2-circle'],
            ['Users', 'admin.users.index', 'admin/users*', 'bi-people'],
            ['DIDs', 'admin.dids.index', 'admin/dids*', 'bi-telephone'],
            ['Audit', 'admin.audit', 'admin/audit*', 'bi-journal-text'],
        ]);
    } elseif (auth()->check() && auth()->user()->isAdmin()) {
        $links = array_merge($links, [
            ['Approvals', 'admin.approvals', 'admin/approvals*', 'bi-check2-circle'],
            ['Users', 'admin.users.index', 'admin/users*', 'bi-people'],
            ['DIDs', 'admin.dids.index', 'admin/dids*', 'bi-telephone'],
        ]);
    }
@endphp

{{-- Floating glass header --}}
<header class="sticky top-0 z-30 px-3 pt-3 sm:px-4">
    <div class="vb-header navbar mx-auto max-w-[90rem] rounded-2xl px-3 shadow-lg ring-1 backdrop-blur-xl">
        <div class="navbar-start">
            @auth
                <div class="dropdown lg:hidden">
                    <button tabindex="0" class="btn btn-ghost btn-square" aria-label="Menu"><i class="bi bi-list text-2xl"></i></button>
                    <ul tabindex="0" class="menu dropdown-content z-40 mt-3 w-60 rounded-box bg-base-100 p-2 shadow-xl ring-1 ring-base-300">
                        @foreach($links as [$label, $route, $pattern, $icon])
                            <li><a href="{{ route($route) }}" class="{{ request()->is($pattern) ? 'menu-active' : '' }}"><i class="bi {{ $icon }}"></i>{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endauth
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-2 text-lg font-bold tracking-tight">
                <span class="vb-logo"><i class="bi bi-telephone-outbound-fill"></i></span>
                <span class="hidden sm:inline">{{ config('app.name') }}</span>
            </a>
        </div>
        @auth
            <div class="navbar-center hidden lg:flex">
                <ul class="menu menu-horizontal gap-1 px-1">
                    @foreach($links as [$label, $route, $pattern, $icon])
                        <li><a href="{{ route($route) }}" class="rounded-xl {{ request()->is($pattern) ? 'menu-active' : '' }}"><i class="bi {{ $icon }}"></i>{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endauth
        <div class="navbar-end gap-1.5">
            <button type="button" onclick="vbToggleTheme()" class="btn btn-ghost btn-circle btn-sm" aria-label="Toggle dark / light mode" title="Toggle dark / light mode">
                <i class="bi bi-moon-stars-fill vb-icon-moon text-lg"></i>
                <i class="bi bi-sun-fill vb-icon-sun text-lg text-warning"></i>
            </button>
            @auth
                <div class="hidden items-center gap-2 rounded-full bg-base-200 py-1 pl-1 pr-3 md:flex">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-content">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="text-sm font-medium">{{ auth()->user()->name }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="btn btn-outline btn-sm rounded-full"><i class="bi bi-box-arrow-right"></i><span class="hidden sm:inline">Logout</span></button>
                </form>
            @endauth
        </div>
    </div>
</header>

<main class="vb-main mx-auto w-full max-w-[90rem] flex-1 px-4 py-6 sm:py-8">
    @if(session('status'))<div role="alert" class="alert alert-success alert-soft mb-5"><i class="bi bi-check-circle-fill"></i><span>{{ session('status') }}</span></div>@endif
    @if(session('warning'))<div role="alert" class="alert alert-warning alert-soft mb-5"><i class="bi bi-exclamation-circle-fill"></i><span>{{ session('warning') }}</span></div>@endif
    @if($errors->any())<div role="alert" class="alert alert-error alert-soft mb-5"><i class="bi bi-exclamation-triangle-fill"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
    @yield('content')
</main>

{{-- Footer --}}
<footer class="px-3 pb-3 sm:px-4 sm:pb-4">
    <div class="vb-footer mx-auto max-w-[90rem] rounded-2xl px-5 py-6 sm:px-8">
        <div class="grid gap-6 md:grid-cols-3">
            <div>
                <div class="flex items-center gap-2.5 font-bold"><span class="vb-logo !h-8 !w-8 text-sm"><i class="bi bi-telephone-outbound-fill"></i></span>{{ config('app.name') }}</div>
                <p class="mt-2 max-w-xs text-sm text-base-content/75">Schedule and run voice broadcast campaigns with DID concurrency control and live call reports.</p>
            </div>
            @auth
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-base-content/65">Quick links</div>
                    <ul class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                        @foreach($links as [$label, $route, $pattern, $icon])
                            <li><a href="{{ route($route) }}" class="inline-flex items-center gap-1.5 text-base-content/80 hover:text-primary"><i class="bi {{ $icon }}"></i>{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-base-content/65">System</div>
                    <ul class="space-y-1.5 text-sm text-base-content/80">
                        <li class="flex items-center gap-2"><span class="status status-success"></span>App online</li>
                        <li class="flex items-center gap-2">
                            @if(config('broadcast.asterisk.dry_run'))
                                <span class="status status-warning"></span>Asterisk: dry-run (no real calls)
                            @else
                                <span class="status status-info"></span>Asterisk: live mode
                            @endif
                        </li>
                    </ul>
                </div>
            @endauth
        </div>
        <div class="mt-6 border-t border-base-300 pt-4 text-center text-xs text-base-content/65 sm:text-left">
            &copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved. Developed by <span class="font-semibold">Shaikat</span>.
        </div>
    </div>
</footer>
@livewireScripts
</body>
</html>
