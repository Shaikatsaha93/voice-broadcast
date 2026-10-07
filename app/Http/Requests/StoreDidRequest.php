<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\Did::class);
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'regex:/^\+?[0-9]{5,20}$/', Rule::unique('dids', 'number')->ignore($this->route('did'))],
            'label' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'max_concurrent_calls' => ['required', 'integer', 'min:1', 'max:1000'],
            'trunk' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-\/@{}.]+$/'],
        ];
    }
}
