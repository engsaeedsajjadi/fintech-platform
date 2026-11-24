<?php

declare(strict_types=1);

namespace App\Domain\International\Http\Controllers;

use App\Domain\International\Models\InternationalReconciliationReport;
use App\Domain\International\Services\InternationalReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Operator reconciliation: clearing check + external statement matching. */
final class ReconciliationController extends Controller
{
    public function __construct(
        private readonly InternationalReconciliationService $reconciliation,
    ) {
    }

    public function clearing(Request $request): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $validated = $request->validate([
            'currency'     => ['required', 'string', 'size:3'],
            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date'],
        ]);

        try {
            $report = $this->reconciliation->reconcileClearing(
                $validated['currency'],
                Carbon::parse($validated['period_start']),
                Carbon::parse($validated['period_end']),
                $operator,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($report)], 201);
    }

    public function statement(Request $request): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $validated = $request->validate([
            'rows'                => ['required', 'array', 'min:1'],
            'rows.*.reference'    => ['required', 'string'],
            'rows.*.amount_minor' => ['required', 'integer', 'min:1'],
            'rows.*.currency'     => ['required', 'string', 'size:3'],
        ]);

        $report = $this->reconciliation->matchStatement($validated['rows'], $operator);

        return response()->json(['success' => true, 'data' => $this->present($report)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $report = InternationalReconciliationReport::find($id);

        if ($report === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Reconciliation report not found.'],
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->present($report)]);
    }

    /** @return array<string, mixed> */
    private function present(InternationalReconciliationReport $report): array
    {
        return [
            'id'              => $report->id,
            'period_start'    => $report->period_start?->toDateString(),
            'period_end'      => $report->period_end?->toDateString(),
            'domain'          => $report->domain,
            'gl_account_code' => $report->gl_account_code,
            'gl_balance'      => $report->gl_balance,
            'domain_balance'  => $report->domain_balance,
            'variance'        => $report->variance,
            'status'          => $report->status,
            'details'         => $report->details,
            'created_at'      => $report->created_at?->toIso8601String(),
        ];
    }
}
