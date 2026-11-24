<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution'         => ['required', Rule::in(['release_to_seller', 'return_to_buyer', 'split'])],
            'split'              => ['required_if:resolution,split', 'array'],
            'split.buyer_share'  => ['required_if:resolution,split', 'numeric', 'gte:0'],
            'split.seller_share' => ['required_if:resolution,split', 'numeric', 'gte:0'],
        ];
    }
}
