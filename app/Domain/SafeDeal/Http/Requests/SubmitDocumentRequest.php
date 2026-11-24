<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'      => ['required', 'string', 'max:100'],
            'reference' => ['required', 'string', 'max:500'],
        ];
    }
}
