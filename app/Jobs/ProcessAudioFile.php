<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Services\Audio\AudioProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAudioFile implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $audioId)
    {
    }

    public function handle(AudioProcessor $processor): void
    {
        if ($audio = AudioFile::find($this->audioId)) {
            $processor->process($audio);
        }
    }
}
