@extends('layouts.app')
@section('content')
<h1 class="vb-title mb-5">My profile</h1>
<div class="grid gap-6 lg:grid-cols-2">
    <div class="vb-card"><div class="card-body gap-3">
        <h2 class="font-semibold"><i class="bi bi-person-circle mr-1 text-primary"></i>Account</h2>
        <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 text-sm">
            <dt class="text-base-content/75">Name</dt><dd class="font-medium">{{ $user->name }}</dd>
            <dt class="text-base-content/75">Email</dt><dd class="break-all font-medium">{{ $user->email }}</dd>
            <dt class="text-base-content/75">Company</dt><dd class="font-medium">{{ $user->company_name ?: '-' }}</dd>
            <dt class="text-base-content/75">Role</dt><dd class="font-medium">{{ $user->roles->first()?->label ?? '-' }}</dd>
            <dt class="text-base-content/75">Status</dt><dd><x-status :value="$user->status" /></dd>
            @if($user->valid_until)<dt class="text-base-content/75">Valid until</dt><dd class="font-medium">{{ $user->valid_until->format('Y-m-d') }}@if($user->isExpired()) <span class="badge badge-error badge-soft badge-sm">EXPIRED</span>@endif</dd>@endif
            @if($user->rate_per_pulse !== null)<dt class="text-base-content/75">Balance</dt><dd class="font-medium tabular-nums">{{ \App\Support\Money::tk($user->balance ?? 0) }}</dd>@endif
            <dt class="text-base-content/75">Member since</dt><dd class="font-medium">{{ $user->created_at?->format('Y-m-d') }}</dd>
        </dl>
        <p class="text-xs text-base-content/70">To change your name, email or company, ask your administrator.</p>
    </div></div>
    <div class="vb-card"><div class="card-body gap-3">
        <h2 class="font-semibold"><i class="bi bi-key mr-1 text-primary"></i>Change password</h2>
        <form method="POST" action="{{ route('profile.password') }}" class="space-y-3">@csrf @method('PUT')
            <label class="block"><span class="mb-1 block text-sm">Current password</span><input type="password" name="current_password" required autocomplete="current-password" class="input w-full"></label>
            <label class="block"><span class="mb-1 block text-sm">New password</span><input type="password" name="password" required minlength="12" autocomplete="new-password" class="input w-full"><span class="mt-1 block text-xs text-base-content/70">At least 12 characters.</span></label>
            <label class="block"><span class="mb-1 block text-sm">Confirm new password</span><input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password" class="input w-full"></label>
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i>Change password</button>
        </form>
    </div></div>
</div>
@endsection
