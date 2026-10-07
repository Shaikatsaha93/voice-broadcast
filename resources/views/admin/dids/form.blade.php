@extends('layouts.app')
@section('content')
<form method="POST" action="{{ $did->exists ? route('admin.dids.update', $did) : route('admin.dids.store') }}" class="vb-card max-w-lg">
    @csrf @if($did->exists) @method('PUT') @endif
    <div class="card-body gap-3">
        <h1 class="vb-title">{{ $did->exists ? 'Edit' : 'Create' }} DID</h1>
        <label class="block"><span class="mb-1 block text-sm">Number</span><input name="number" value="{{ old('number', $did->number) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Label</span><input name="label" value="{{ old('label', $did->label) }}" class="input w-full"></label>
        <label class="block"><span class="mb-1 block text-sm">Max concurrent calls</span><input name="max_concurrent_calls" type="number" min="1" value="{{ old('max_concurrent_calls', $did->max_concurrent_calls) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Trunk <span class="text-xs text-base-content/65">(optional)</span></span><input name="trunk" value="{{ old('trunk', $did->trunk) }}" placeholder="PJSIP/{number}@trunk" class="input w-full"></label>
        <label class="block"><span class="mb-1 block text-sm">Status</span><select name="status" class="select w-full">@foreach(['active', 'inactive'] as $s)<option @selected(old('status', $did->status) === $s)>{{ $s }}</option>@endforeach</select></label>
        <div class="card-actions mt-2"><button class="btn btn-primary px-8">Save</button><a href="{{ route('admin.dids.index') }}" class="btn btn-ghost">Cancel</a></div>
    </div>
</form>
@endsection
