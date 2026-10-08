<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('did') ?? \App\Models\Did::class);
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'regex:/^\+?[0-9]{5,20}$/', Rule::unique('dids', 'number')->ignore($this->route('did'))],
            'label' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'max_concurrent_calls' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_per_pulse' => ['required', 'numeric', 'min:0', 'max:99999'],
            'pulse_seconds' => ['required', 'integer', 'between:1,3600'],
            // Opening balance only when the DID is created; afterwards use Add / Remove balance (ledger).
            'opening_balance' => $this->route('did') ? ['prohibited'] : ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'sip_host' => [Rule::requiredIf(fn () => $this->sipRequired()), 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'sip_port' => ['nullable', 'integer', 'between:1,65535'],
            'sip_username' => [Rule::requiredIf(fn () => $this->sipRequired()), 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._+@\-]+$/'],
            // required the first time SIP details are saved; blank on edit keeps the stored password
            'sip_password' => [Rule::requiredIf(fn () => ($this->sipRequired() || filled($this->input('sip_host'))) && blank($this->route('did')?->sip_password)), 'nullable', 'string', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
        ];
    }

    /** New DIDs, and DIDs that already use SIP, must have SIP details. Only legacy DIDs with a manual trunk may skip them. */
    private function sipRequired(): bool
    {
        $did = $this->route('did');

        return ! $did || $did->hasSip();
    }

    public function messages(): array
    {
        return [
            'sip_host.regex' => 'SIP server must be a hostname or IP address (no spaces, no sip: prefix, no port).',
            'sip_username.regex' => 'SIP username may only contain letters, digits and . _ + @ -',
            'sip_password.regex' => 'SIP password cannot contain control characters or line breaks.',
        ];
    }
}
