@extends('layouts.app')
@section('content')
<h1 class="vb-title mb-5">Audio files</h1>
<form method="POST" action="{{ route('audio.store') }}" enctype="multipart/form-data" class="vb-card mb-5">@csrf
    <div class="card-body flex-col gap-2 p-4 sm:flex-row sm:items-center">
        <input type="file" name="audio" accept=".wav,.mp3" required class="file-input file-input-bordered w-full">
        <button class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i>Upload</button>
    </div>
</form>
<div class="vb-card overflow-x-auto p-2">
    <table class="vb-table">
        <thead><tr><th>Name</th><th>Status</th><th>Duration</th><th>Play</th><th>Replace</th></tr></thead>
        <tbody>
        @forelse($audios as $a)
            <tr>
                <td class="font-medium">{{ $a->original_name }}</td>
                <td><x-status :value="$a->status" /> <span class="text-xs text-error">{{ $a->error }}</span></td>
                <td>{{ $a->duration }}</td>
                <td>@if($a->isReady())<audio controls preload="none" src="{{ route('audio.stream', $a) }}"></audio>@endif</td>
                <td>@if($a->user_id === auth()->id())<form method="POST" action="{{ route('audio.replace', $a) }}" enctype="multipart/form-data" class="flex min-w-64 gap-2">@csrf<input type="file" name="audio" accept=".wav,.mp3" required class="file-input file-input-bordered file-input-xs w-full"><button class="btn btn-outline btn-primary btn-xs">Replace</button></form>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-base-content/75">No audio files yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $audios->links() }}</div>
@endsection
