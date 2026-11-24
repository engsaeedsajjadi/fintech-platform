<?php

declare(strict_types=1);

namespace App\Domain\International\Http\Controllers;

use App\Domain\International\Models\InternationalAccount;
use App\Domain\International\Services\InternationalAccountService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Multi-currency accounts: open, list, show (with ledger-derived balance). */
final class InternationalAccountController extends Controller
{
    public function __construct(
        private readonly InternationalAccountService $accounts,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $accounts = InternationalAccount::where('user_id', $user->id)->orderBy('currency')->get();

        return response()->json([
            'success' => true,
            'data'    => $accounts->map(fn (InternationalAccount $a): array => $this->present($a))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'nickname' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $account = $this->accounts->openAccount($user, $validated['currency'], $validated['nickname'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($account)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = InternationalAccount::find($id);

        if ($account === null || (int) $account->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'International account not found.'],
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->present($account)]);
    }

    /** @return array<string, mixed> */
    private function present(InternationalAccount $account): array
    {
        return [
            'id'                  => $account->id,
            'currency'            => $account->currency,
            'ledger_account_code' => $account->ledger_account_code,
            'nickname'            => $account->nickname,
            'status'              => $account->status,
            'balance'             => $this->accounts->balance($account),
            'created_at'          => $account->created_at?->toIso8601String(),
        ];
    }
}
