@extends('layouts.app')
@section('content')
<form method="POST" action="{{ $campaign->exists ? route('campaigns.update', $campaign) : route('campaigns.store') }}" enctype="multipart/form-data" class="vb-card max-w-2xl">
    @csrf @if($campaign->exists) @method('PUT') @endif
    <div class="card-body gap-3">
        <h1 class="vb-title">{{ $campaign->exists ? 'Edit' : 'New' }} campaign</h1>
        <label class="block"><span class="mb-1 block text-sm">Campaign name</span><input name="name" value="{{ old('name', $campaign->name) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">DID</span><select name="did_id" class="select w-full" required><option value="">Select DID</option>@foreach($dids as $d)<option value="{{ $d->id }}" @selected(old('did_id', $campaign->did_id) == $d->id)>{{ $d->number }} (max {{ $d->max_concurrent_calls }})</option>@endforeach</select></label>
        <label class="block"><span class="mb-1 block text-sm">Audio</span><select name="audio_file_id" class="select w-full"><option value="">Select audio (READY)</option>@foreach($audios as $a)<option value="{{ $a->id }}" @selected(old('audio_file_id', $campaign->audio_file_id) == $a->id)>{{ $a->original_name }}</option>@endforeach</select></label>
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block"><span class="mb-1 block text-sm">Max attempts <span class="text-xs text-base-content/65">(incl. first)</span></span><input name="max_attempts" type="number" min="1" max="{{ config('broadcast.max_attempts_limit') }}" value="{{ old('max_attempts', $campaign->max_attempts) }}" class="input w-full"></label>
            <label class="block"><span class="mb-1 block text-sm">Requested concurrency</span><input name="requested_concurrency" type="number" min="1" value="{{ old('requested_concurrency', $campaign->requested_concurrency) }}" class="input w-full"></label>
            <label class="block"><span class="mb-1 block text-sm">Retry delay (sec)</span><input name="retry_delay_seconds" type="number" min="30" value="{{ old('retry_delay_seconds', $campaign->retry_delay_seconds ?? config('broadcast.retry_delay_seconds')) }}" class="input w-full"></label>
        </div>
        <label class="block"><span class="mb-1 block text-sm">Scheduled start <span class="text-xs text-base-content/65">(optional)</span></span><input name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}" class="input w-full"></label>
        @unless($campaign->exists)
            <label class="block">
                <span class="mb-1 block text-sm">Phone numbers <span class="text-xs text-base-content/65">(optional CSV/TXT, one number per line)</span></span>
                <input type="file" name="numbers" accept=".csv,.txt" class="file-input file-input-bordered w-full">
                <span class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-base-content/75">
                    <a href="{{ route('campaigns.sample') }}" class="link link-primary inline-flex items-center gap-1 font-medium"><i class="bi bi-download"></i>Download sample CSV</a>
                    <span>One number per line, first column. Formats like <code>{{ config('broadcast.default_country_prefix') }}1712345678</code>, <code>01712345678</code> or <code>+{{ config('broadcast.default_country_prefix') }}1712345678</code> work. Replace the sample rows with real numbers.</span>
                </span>
                <span class="mt-1 block text-xs text-base-content/75">You can also upload or replace numbers later from the campaign page.</span>
            </label>
        @endunless
        <div class="card-actions mt-2"><button class="btn btn-primary px-8">Save</button><a href="{{ url()->previous() }}" class="btn btn-ghost">Cancel</a></div>
    </div>
</form>
@endsection
