@extends('layouts.app')
@section('content')
<form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="vb-card max-w-lg">
    @csrf @if($user->exists) @method('PUT') @endif
    <div class="card-body gap-3">
        <h1 class="vb-title">{{ $user->exists ? 'Edit' : 'Create' }} user</h1>
        <label class="block"><span class="mb-1 block text-sm">Name</span><input name="name" value="{{ old('name', $user->name) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Email</span><input name="email" type="email" value="{{ old('email', $user->email) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Password</span><input name="password" type="password" placeholder="{{ $user->exists ? 'New password (optional, min 12)' : 'Min 12 characters' }}" class="input w-full" @required(! $user->exists)></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block"><span class="mb-1 block text-sm">Role</span><select name="role" class="select w-full">@foreach(array_intersect_key(['user' => 'Normal User', 'admin' => 'Admin', 'super_admin' => 'Super Admin'], array_flip(auth()->user()->assignableRoles())) as $v => $l)<option value="{{ $v }}" @selected(old('role', $user->exists ? $user->roles->first()?->name : 'user') === $v)>{{ $l }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-sm">Status</span><select name="status" class="select w-full">@foreach(['active', 'inactive'] as $s)<option @selected(old('status', $user->status) === $s)>{{ $s }}</option>@endforeach</select></label>
        </div>
        @if(auth()->user()->isSuperAdmin() && ($admins ?? collect())->isNotEmpty())
            <label class="block"><span class="mb-1 block text-sm">Managed by Admin <span class="text-xs text-base-content/65">(normal users only)</span></span>
                <select name="owner_admin_id" class="select w-full"><option value="">Me (Super Admin only)</option>@foreach($admins as $a)<option value="{{ $a->id }}" @selected((string) old('owner_admin_id', $user->created_by) === (string) $a->id)>{{ $a->name }}</option>@endforeach</select>
            </label>
        @endif
        <div class="card-actions mt-2"><button class="btn btn-primary px-8">Save</button><a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Cancel</a></div>
    </div>
</form>
@endsection
