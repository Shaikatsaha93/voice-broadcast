<?php

namespace App\Http\Requests;

use App\Models\AudioFile;
use App\Models\Did;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route("campaign");

        return $campaign ? $this->user()->can("update", $campaign) : true; // authorize BEFORE validating (no IDOR probing)
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'did_id' => ['required', 'integer'],
            'audio_file_id' => ['nullable', 'integer'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:'.config('broadcast.max_attempts_limit')],
            'requested_concurrency' => ['required', 'integer', 'min:1'],
            'retry_delay_seconds' => ['nullable', 'integer', 'min:30', 'max:86400'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            // Optional on create only; same rules as the separate "Upload CSV" action.
            'numbers' => $this->route('campaign') ? ['prohibited'] : ['nullable', ...array_diff((new ImportNumbersRequest)->rules()['numbers'], ['required'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $user = $this->user();
            $did = Did::find($this->input('did_id'));

            // Server-side: only an assigned, active DID is usable; never trust the posted id.
            if (! $did || ! $user->can('use', $did)) {
                $v->errors()->add('did_id', 'Select one of your assigned active DIDs.');
            } elseif ((int) $this->input('requested_concurrency') > $did->max_concurrent_calls) {
                $v->errors()->add('requested_concurrency', "Cannot exceed the DID maximum of {$did->max_concurrent_calls}.");
            }

            if ($this->filled('audio_file_id')) {
                $audio = AudioFile::find($this->input('audio_file_id'));
                if (! $audio || ! $user->can('use', $audio)) {
                    $v->errors()->add('audio_file_id', 'Select one of your own audio files.');
                }
            }
        }];
    }
}
