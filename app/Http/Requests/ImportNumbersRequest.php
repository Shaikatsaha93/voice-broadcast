<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportNumbersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['numbers' => ['required', 'file', 'extensions:csv,txt', 'mimetypes:text/csv,text/plain,application/csv', 'max:'.config('broadcast.max_import_kb')]];
    }
}
