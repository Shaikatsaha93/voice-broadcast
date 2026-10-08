<?php

namespace App\Services\Audio;

use App\Models\AudioFile;
use Illuminate\Support\Facades\Storage;

/**
 * Gets an audio file in front of Asterisk. Asterisk runs as its own user and does not inherit
 * the web user's groups, so it cannot read the private upload storage. The normalized WAV is
 * therefore copied into Asterisk's own sounds folder (config broadcast.asterisk.sounds_dir)
 * and played from there. Without that setting (local dev) the storage path is used directly.
 */
class AsteriskAudio
{
    private function dir(): ?string
    {
        $d = config('broadcast.asterisk.sounds_dir');

        return $d ? rtrim((string) $d, '/\\') : null;
    }

    /** Absolute path WITHOUT extension, exactly as Playback() wants it. Null when there is no audio. */
    public function playPath(?AudioFile $audio): ?string
    {
        $rel = $audio?->normalized_path ?? $audio?->path;
        if (! $rel) {
            return null;
        }

        $src = Storage::disk(config('filesystems.audio_disk', 'local'))->path($rel);

        if (($dir = $this->dir()) && is_file($src)) {
            $dest = $dir.'/'.basename($rel);
            if ((! is_file($dest) || filesize($dest) !== filesize($src)) && is_dir($dir) && is_writable($dir) && @copy($src, $dest)) {
                @chmod($dest, 0644);
            }
            if (is_file($dest)) {
                $src = $dest;
            }
        }

        return pathinfo($src, PATHINFO_DIRNAME).'/'.pathinfo($src, PATHINFO_FILENAME);
    }

    /** Remove the copy Asterisk was using (audio replaced or deleted). */
    public function forget(AudioFile $audio): void
    {
        if (($dir = $this->dir()) && $audio->normalized_path) {
            @unlink($dir.'/'.basename($audio->normalized_path));
        }
    }
}
