<?php

declare(strict_types=1);

namespace App\Domain\International\Http\Controllers;

use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Counterparty + beneficiary management (owner-scoped). */
final class CounterpartyController extends Controller
{
    public function indexCounterparties(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => Counterparty::where('user_id', $user->id)->orderByDesc('created_at')->get()->map(
                fn (Counterparty $c): array => $this->presentCounterparty($c)
            )->all(),
        ]);
    }

    public function storeCounterparty(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type'      => ['required', 'string', 'in:individual,business'],
            'full_name' => ['required', 'string', 'max:255'],
            'country'   => ['required', 'string', 'size:2'],
        ]);

        $counterparty = Counterparty::create([
            'user_id'          => $user->id,
            'type'             => $validated['type'],
            'full_name'        => $validated['full_name'],
            'country'          => strtoupper($validated['country']),
            'screening_status' => 'not_screened',
        ]);

        return response()->json(['success' => true, 'data' => $this->presentCounterparty($counterparty)], 201);
    }

    public function showCounterparty(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $counterparty = Counterparty::find($id);

        if ($counterparty === null || (int) $counterparty->user_id !== (int) $user->id) {
            return $this->notFound('Counterparty not found.');
        }

        return response()->json(['success' => true, 'data' => $this->presentCounterparty($counterparty)]);
    }

    public function indexBeneficiaries(Request $request, string $counterpartyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $counterparty = Counterparty::find($counterpartyId);

        if ($counterparty === null || (int) $counterparty->user_id !== (int) $user->id) {
            return $this->notFound('Counterparty not found.');
        }

        return response()->json([
            'success' => true,
            'data'    => Beneficiary::where('counterparty_id', $counterparty->id)->orderByDesc('created_at')->get()->map(
                fn (Beneficiary $b): array => $this->presentBeneficiary($b)
            )->all(),
        ]);
    }

    public function storeBeneficiary(Request $request, string $counterpartyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $counterparty = Counterparty::find($counterpartyId);

        if ($counterparty === null || (int) $counterparty->user_id !== (int) $user->id) {
            return $this->notFound('Counterparty not found.');
        }

        $validated = $request->validate([
            'display_name'  => ['required', 'string', 'max:255'],
            'country'       => ['required', 'string', 'size:2'],
            'bank_name'     => ['nullable', 'string', 'max:255'],
            'bic'           => ['nullable', 'string', 'max:11'],
            'iban_last4'    => ['nullable', 'string', 'size:4'],
            'account_last4' => ['nullable', 'string', 'size:4'],
            'currency'      => ['required', 'string', 'size:3'],
        ]);

        $beneficiary = Beneficiary::create([
            'user_id'         => $user->id,
            'counterparty_id' => $counterparty->id,
            'display_name'    => $validated['display_name'],
            'country'         => strtoupper($validated['country']),
            'bank_name'       => $validated['bank_name'] ?? null,
            'bic'             => $validated['bic'] ?? null,
            'iban_last4'      => $validated['iban_last4'] ?? null,
            'account_last4'   => $validated['account_last4'] ?? null,
            'currency'        => strtoupper($validated['currency']),
            'is_active'       => true,
        ]);

        return response()->json(['success' => true, 'data' => $this->presentBeneficiary($beneficiary)], 201);
    }

    /** @return array<string, mixed> */
    private function presentCounterparty(Counterparty $counterparty): array
    {
        return [
            'id'               => $counterparty->id,
            'type'             => $counterparty->type,
            'full_name'        => $counterparty->full_name,
            'country'          => $counterparty->country,
            'screening_status' => $counterparty->screening_status,
            'last_screened_at' => $counterparty->last_screened_at?->toIso8601String(),
            'created_at'       => $counterparty->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentBeneficiary(Beneficiary $beneficiary): array
    {
        return [
            'id'              => $beneficiary->id,
            'counterparty_id' => $beneficiary->counterparty_id,
            'display_name'    => $beneficiary->display_name,
            'country'         => $beneficiary->country,
            'bank_name'       => $beneficiary->bank_name,
            'bic'             => $beneficiary->bic,
            'iban_last4'      => $beneficiary->iban_last4,
            'account_last4'   => $beneficiary->account_last4,
            'currency'        => $beneficiary->currency,
            'is_active'       => $beneficiary->is_active,
            'created_at'      => $beneficiary->created_at?->toIso8601String(),
        ];
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => 'NOT_FOUND', 'message' => $message],
        ], 404);
    }
}
