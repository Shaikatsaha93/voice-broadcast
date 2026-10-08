@extends('layouts.app')
@section('content')
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="vb-title">Users</h1>
    <a class="btn btn-primary btn-sm sm:btn-md" href="{{ route('admin.users.create') }}"><i class="bi bi-plus-lg"></i>New user</a>
</div>
<div class="vb-card overflow-x-auto p-2">
    <table class="vb-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created by</th><th>Status</th><th>DIDs</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $u)
            <tr><td class="font-medium">{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->roles->first()?->label }}</td><td class="text-sm text-base-content/75">{{ $u->creator?->name ?? '-' }}</td><td><x-status :value="$u->status" /></td><td>{{ $u->dids_count }}</td>
            <td class="whitespace-nowrap text-right"><a class="btn btn-outline btn-primary btn-xs" href="{{ route('admin.users.show', $u) }}">View</a> <a class="btn btn-outline btn-xs" href="{{ route('admin.users.edit', $u) }}">Edit</a></td></tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
