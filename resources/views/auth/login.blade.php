@extends('layouts.app')
@section('content')
<div class="mx-auto grid w-full max-w-5xl items-stretch gap-6 lg:mt-6 lg:grid-cols-2">
    <section class="vb-hero hidden lg:flex lg:flex-col lg:justify-between">
        <div class="relative z-10">
            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20 text-2xl text-white backdrop-blur"><i class="bi bi-telephone-outbound-fill"></i></span>
            <h2 class="mt-5 text-3xl font-bold leading-tight text-white">Reach every customer,<br>one broadcast at a time.</h2>
            <p class="mt-3 max-w-sm text-sm text-white/85">Upload your audio and numbers, pick a DID and let the platform dial, retry and report for you.</p>
        </div>
        <ul class="relative z-10 mt-8 space-y-3 text-sm text-white">
            <li class="flex items-center gap-3"><span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20"><i class="bi bi-calendar-check"></i></span>Schedule campaigns with automatic retries</li>
            <li class="flex items-center gap-3"><span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20"><i class="bi bi-speedometer2"></i></span>Control DID concurrency, no overload</li>
            <li class="flex items-center gap-3"><span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20"><i class="bi bi-graph-up-arrow"></i></span>Live call reports and delivery stats</li>
        </ul>
    </section>

    <div class="flex flex-col justify-center">
        <div class="mb-6 text-center lg:text-left">
            <span class="vb-logo mb-3 !h-14 !w-14 text-2xl lg:hidden"><i class="bi bi-telephone-outbound-fill"></i></span>
            <h1 class="vb-title block !text-3xl">Welcome back</h1>
            <p class="mt-1 text-sm text-base-content/75">Sign in to {{ config('app.name') }}</p>
        </div>
        <form method="POST" action="/login" class="vb-card">
            @csrf
            <div class="card-body gap-4">
                <label class="block"><span class="mb-1 block text-sm font-medium">Email</span>
                    <span class="relative block"><i class="bi bi-envelope pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base-content/50"></i><input name="email" type="email" value="{{ old('email') }}" required autofocus class="input w-full !pl-9" placeholder="you@example.com"></span></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Password</span>
                    <span class="relative block"><i class="bi bi-lock pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base-content/50"></i><input name="password" type="password" required class="input w-full !pl-9" placeholder="Your password"></span></label>
                <label class="flex cursor-pointer items-center gap-2 text-sm"><input type="checkbox" name="remember" class="checkbox checkbox-primary checkbox-sm"> Remember me</label>
                <button class="btn btn-primary w-full">Login<i class="bi bi-arrow-right"></i></button>
            </div>
        </form>
    </div>
</div>
@endsection
