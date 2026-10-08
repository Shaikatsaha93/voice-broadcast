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
        $audios = $request->user()->limitToVisibleOwners(AudioFile::query(), 'audio_files.user_id')->with('user:id,name')->latest('id')->paginate(20);

        return view('audio.index', compact('audios'));
    }

    /** Light JSON poll for the audio page: status of the (visible) files being processed. */
    public function status(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->filter(fn ($v) => ctype_digit($v))->map(fn ($v) => (int) $v)->take(50)->all();
        $rows = $request->user()->limitToVisibleOwners(AudioFile::query(), 'audio_files.user_id')->whereIn('audio_files.id', $ids)->get(['id', 'status']);

        return response()->json($rows->pluck('status', 'id'));
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
        app(\App\Services\Audio\AsteriskAudio::class)->forget($audio);
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

    public function destroy(AudioFile $audio)
    {
        Gate::authorize('delete', $audio);
        $using = \App\Models\Campaign::where('audio_file_id', $audio->id)->count();
        if ($using > 0) {
            return back()->withErrors(['audio' => "Cannot delete \"{$audio->original_name}\": it is used by {$using} campaign(s). Delete those campaigns first."]);
        }
        app(\App\Services\Audio\AsteriskAudio::class)->forget($audio);
        Storage::delete(array_filter([$audio->path, $audio->normalized_path]));
        Audit::log('audio.deleted', $audio, null, null, $audio->only('original_name'));
        $audio->delete();

        return back()->with('status', 'Audio deleted.');
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
