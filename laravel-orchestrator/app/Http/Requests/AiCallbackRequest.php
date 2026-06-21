<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['completed', 'failed'])],
            'result' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
