<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['audio' => ['required', 'file', 'extensions:wav,mp3', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/mpeg,audio/mp3', 'max:'.config('broadcast.max_audio_kb')]];
    }
}
