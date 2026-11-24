<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'seller_id'               => ['required', 'string', 'different:buyer_id'],
            'amount'                  => ['required', 'numeric', 'gt:0'],
            'currency'                => ['required', 'string', 'size:3'],
            'terms'                   => ['required', 'array'],
            'terms.description'       => ['required', 'string', 'max:2000'],
            'terms.conditions'        => ['sometimes', 'array'],
            'terms.delivery_deadline' => ['sometimes', 'nullable', 'date'],
            'milestones'              => ['required', 'array', 'min:1'],
            'milestones.*.title'      => ['required', 'string', 'max:255'],
            'milestones.*.amount'     => ['required', 'numeric', 'gt:0'],
        ];
    }
}
