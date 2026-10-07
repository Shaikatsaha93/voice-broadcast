@extends('layouts.app')
@section('content')
<h1 class="vb-title mb-5">Audit log</h1>
<div class="vb-card overflow-x-auto p-2">
    <table class="vb-table table-sm align-top">
        <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Old</th><th>New</th><th>IP</th></tr></thead>
        <tbody>
        @foreach($logs as $l)
            <tr><td class="whitespace-nowrap">{{ $l->created_at }}</td><td>{{ $l->actor?->name }}</td><td><span class="badge badge-outline badge-sm">{{ $l->action }}</span></td><td>{{ $l->entity }}#{{ $l->entity_id }}</td><td><code>{{ json_encode($l->old_values) }}</code></td><td><code>{{ json_encode($l->new_values) }}</code></td><td>{{ $l->ip }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
