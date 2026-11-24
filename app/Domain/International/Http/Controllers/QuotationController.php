<?php

declare(strict_types=1);

namespace App\Domain\International\Http\Controllers;

use App\Domain\International\Exceptions\ExchangeRateUnavailableException;
use App\Domain\International\Models\ExchangeQuotation;
use App\Domain\International\Services\QuotationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/** Exchange quotations: create (operator-supplied rate) and accept. */
final class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'source_currency'      => ['required', 'string', 'size:3'],
            'destination_currency' => ['required', 'string', 'size:3'],
            'amount_minor'         => ['required', 'integer', 'min:1'],
            'rate'                 => ['required', 'string'],
            'ttl_minutes'          => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        try {
            $quotation = $this->quotations->createQuotation(
                $user,
                $validated['source_currency'],
                $validated['destination_currency'],
                (int) $validated['amount_minor'],
                (string) $validated['rate'],
                (int) ($validated['ttl_minutes'] ?? 15),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($quotation)], 201);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $quotation = ExchangeQuotation::find($id);

        if ($quotation === null || (int) $quotation->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Quotation not found.'],
            ], 404);
        }

        try {
            $quotation = $this->quotations->acceptQuotation($quotation, $user);
        } catch (Throwable $e) {
            $code = $e instanceof ExchangeRateUnavailableException ? 'QUOTATION_EXPIRED' : 'VALIDATION_ERROR';

            return response()->json([
                'success' => false,
                'error'   => ['code' => $code, 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($quotation)]);
    }

    /** @return array<string, mixed> */
    private function present(ExchangeQuotation $quotation): array
    {
        return [
            'id'                       => $quotation->id,
            'source_currency'          => $quotation->source_currency,
            'destination_currency'     => $quotation->destination_currency,
            'amount_minor'             => (int) $quotation->amount_minor,
            'rate'                     => $quotation->rate,
            'destination_amount_minor' => (int) $quotation->destination_amount_minor,
            'source'                   => $quotation->source,
            'status'                   => $quotation->status,
            'expires_at'               => $quotation->expires_at?->toIso8601String(),
            'accepted_at'              => $quotation->accepted_at?->toIso8601String(),
        ];
    }
}
