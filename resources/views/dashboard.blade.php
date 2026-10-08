@extends('layouts.app')
@section('content')
@php
    $hour = now()->hour;
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $isAdmin = auth()->user()->isManager();
    $isSuper = auth()->user()->isSuperAdmin();
@endphp
<section class="vb-hero mb-6">
    <div class="relative z-10 flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="text-sm font-medium text-white/80">{{ now()->format('l, d F Y') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-white sm:text-3xl">{{ $greet }}, {{ auth()->user()->name }} <span aria-hidden="true">&#128075;</span></h1>
            <p class="mt-1 max-w-xl text-sm text-white/85">Here is what is happening with your voice broadcasts right now. Numbers refresh automatically every few seconds.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('campaigns.create') }}" class="btn border-0 bg-white text-indigo-700 shadow-lg hover:bg-white/90"><i class="bi bi-plus-lg"></i>New campaign</a>
            @unless($isAdmin)
                <a href="{{ route('audio.index') }}" class="btn border-white/40 bg-white/15 text-white backdrop-blur hover:bg-white/25"><i class="bi bi-music-note-beamed"></i>Audio</a>
            @else
                <a href="{{ route('admin.approvals') }}" class="btn border-0 bg-white text-indigo-700 shadow-lg hover:bg-white/90"><i class="bi bi-check2-circle"></i>Approvals</a>
                @if($isSuper)
                    <a href="{{ route('admin.dids.index') }}" class="btn border-white/40 bg-white/15 text-white backdrop-blur hover:bg-white/25"><i class="bi bi-telephone"></i>DIDs</a>
                @else
                    <a href="{{ route('admin.users.create') }}" class="btn border-0 bg-white text-indigo-700 shadow-lg hover:bg-white/90"><i class="bi bi-person-plus"></i>New user</a>
                    <a href="{{ route('admin.users.index') }}" class="btn border-white/40 bg-white/15 text-white backdrop-blur hover:bg-white/25"><i class="bi bi-people"></i>Users</a>
                @endif
            @endunless
            <a href="{{ route('reports') }}" class="btn border-white/40 bg-white/15 text-white backdrop-blur hover:bg-white/25"><i class="bi bi-bar-chart-line"></i>Reports</a>
        </div>
    </div>
</section>
<livewire:dashboard-stats />
@endsection
