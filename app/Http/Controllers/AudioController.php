<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadAudioRequest;
use App\Jobs\ProcessAudioFile;
use App\Models\AudioFile;
use App\Services\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    public function index(Request $request)
    {
        $audios = AudioFile::when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))->latest('id')->paginate(20);

        return view('audio.index', compact('audios'));
    }

    public function store(UploadAudioRequest $request)
    {
        $file = $request->file('audio');
        $audio = AudioFile::create([
            'user_id' => $request->user()->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'path' => $file->storeAs('audio/'.$request->user()->id, bin2hex(random_bytes(16)).'.'.$file->getClientOriginalExtension()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'UPLOADED',
        ]);
        Audit::log('audio.uploaded', $audio, null, null, $audio->only('original_name', 'size'));
        ProcessAudioFile::dispatch($audio->id);

        return back()->with('status', 'Audio uploaded; processing.');
    }

    /** Replace is only allowed while no campaign using this audio is past DRAFT/REJECTED. */
    public function replace(UploadAudioRequest $request, AudioFile $audio)
    {
        Gate::authorize('use', $audio);
        if (\App\Models\Campaign::where('audio_file_id', $audio->id)->whereNotIn('status', ['DRAFT', 'REJECTED'])->exists()) {
            return back()->withErrors(['audio' => 'Audio is locked: a campaign using it was already submitted.']);
        }
        $file = $request->file('audio');
        Storage::delete(array_filter([$audio->path, $audio->normalized_path]));
        $audio->update([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'path' => $file->storeAs('audio/'.$audio->user_id, bin2hex(random_bytes(16)).'.'.$file->getClientOriginalExtension()),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'normalized_path' => null, 'status' => 'UPLOADED', 'duration' => null,
        ]);
        Audit::log('audio.replaced', $audio);
        ProcessAudioFile::dispatch($audio->id);

        return back()->with('status', 'Audio replaced; processing.');
    }

    /** Authorized streaming only - files are never publicly reachable. */
    public function stream(AudioFile $audio)
    {
        Gate::authorize('view', $audio);
        $path = $audio->normalized_path ?? $audio->path;
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
