<?php

namespace App\Services\Audio;

use App\Models\AudioFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Validate integrity with ffprobe and normalize to 8kHz mono PCM WAV (Asterisk friendly). */
class AudioProcessor
{
    public function process(AudioFile $audio): void
    {
        $audio->update(['status' => 'PROCESSING', 'error' => null]);

        try {
            $disk = Storage::disk(config('filesystems.audio_disk', 'local'));
            $src = $disk->path($audio->path);

            $probe = new Process([env('FFPROBE_BINARY', 'ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $src]);
            $probe->setTimeout(30)->run();
            $duration = (float) trim($probe->getOutput());
            if (! $probe->isSuccessful() || $duration <= 0) {
                throw new \RuntimeException('Audio file is corrupt or unreadable.');
            }

            $out = preg_replace('/\.[^.]+$/', '', $audio->path).'.norm.wav';
            $conv = new Process([env('FFMPEG_BINARY', 'ffmpeg'), '-y', '-i', $src, '-ar', '8000', '-ac', '1', '-c:a', 'pcm_s16le', $disk->path($out)]);
            $conv->setTimeout(120)->run();
            if (! $conv->isSuccessful()) {
                throw new \RuntimeException('Audio conversion failed.');
            }

            $audio->update(['normalized_path' => $out, 'duration' => round($duration, 2), 'status' => 'READY']);
        } catch (\Throwable $e) {
            $audio->update(['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }
}
