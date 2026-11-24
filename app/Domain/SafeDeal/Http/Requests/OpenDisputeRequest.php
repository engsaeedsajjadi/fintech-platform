<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason'   => ['required', 'string', 'max:2000'],
            'evidence' => ['sometimes', 'array'],
        ];
    }
}
