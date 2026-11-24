<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Listeners;

use App\Domain\Account\DataObjects\Money as AccountMoney;
use App\Domain\Ledger\Models\LedgerPostingFailure;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\PostingRuleEngine;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Throwable;

/**
 * Auto-posts GL entries for domain events that have posting rules.
 *
 * Registration (Phase 4.2-A): this class is a Spatie Reactor, discovered
 * automatically by `config/event-sourcing.php` (which scans the Listeners
 * directories under app/Domain). The previous version was a plain class with a
 * `handle()` method: Spatie's DiscoverEventHandlers only registers
 * subclasses of EventHandler, and EventServiceProvider never listed it, so
 * auto-posting NEVER ran in production — financial events produced no
 * journal entries at all. `onEvent(object)` is the generic handler Spatie
 * routes every stored event to (type-based dispatch, not name-based).
 *
 * Failure handling (Phase 4.2-D): a failed posting is recorded durably in
 * `ledger_posting_failures` AND rethrown, so the event job fails and the
 * queue retries it. A failed posting is never silently logged while the
 * business operation looks successful.
 *
 * Idempotency (Phase 4.2-C): postings go through postIdempotent() with a
 * deterministic key (`gl:{Event}:{event id | payload hash}`), so a replayed
 * event cannot post a second entry.
 */
final class PostGlEntryListener extends Reactor
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly PostingRuleEngine $ruleEngine,
    ) {
    }

    public function onEvent(object $event): void
    {
        if (! config('ledger.auto_posting', true)) {
            return;
        }

        $eventName = class_basename($event);
        $eventData = $this->normaliseEventData($event);

        $rules = $this->ruleEngine->resolveRules($eventName, $eventData);

        if (empty($rules)) {
            return;
        }

        $lines = [];
        foreach ($rules as $rule) {
            $lines[] = [
                'account_code' => $rule['debit_account'],
                'debit'        => $rule['amount'],
                'credit'       => '0.0000',
                'currency'     => $rule['currency'],
            ];
            $lines[] = [
                'account_code' => $rule['credit_account'],
                'debit'        => '0.0000',
                'credit'       => $rule['amount'],
                'currency'     => $rule['currency'],
            ];
        }

        $eventId = $this->extractEventId($eventData);
        $domain = $this->extractDomain($event);
        $idempotencyKey = "gl:{$eventName}:{$eventId}";

        try {
            $this->ledgerService->postIdempotent(
                $idempotencyKey,
                "Auto: {$eventName}",
                $lines,
                $domain,
                $eventId,
            );
        } catch (Throwable $e) {
            $this->recordFailure($eventName, $eventId, $domain, $idempotencyKey, $eventData, $e);

            // Rethrow: the Spatie job must fail (and be retried) — a failed
            // GL posting must never look like a successful business step.
            throw $e;
        }
    }

    /**
     * Durable failure record: a failed GL posting is never silent.
     *
     * @param array<string, mixed> $eventData
     */
    private function recordFailure(
        string $eventName,
        string $eventId,
        string $domain,
        string $idempotencyKey,
        array $eventData,
        Throwable $e,
    ): void {
        try {
            $failure = LedgerPostingFailure::firstOrNew(['idempotency_key' => $idempotencyKey]);
            $failure->event_name = $eventName;
            $failure->event_id = $eventId;
            $failure->source_domain = $domain;
            $failure->event_payload = mb_substr((string) json_encode($eventData), 0, 10000);
            $failure->error = mb_substr($e->getMessage(), 0, 2000);
            $failure->status = 'pending';
            $failure->attempts = (int) ($failure->attempts ?? 0) + 1;
            $failure->resolved_at = null;
            $failure->save();
        } catch (Throwable $recordingError) {
            // Recording must never mask the original posting failure.
            Log::error('Could not record GL posting failure', [
                'event'           => $eventName,
                'error'           => $e->getMessage(),
                'recording_error' => $recordingError->getMessage(),
            ]);
        }

        Log::error('GL auto-posting failed', [
            'event'           => $eventName,
            'event_id'        => $eventId,
            'idempotency_key' => $idempotencyKey,
            'error'           => $e->getMessage(),
        ]);
    }

    /**
     * Flatten an event object into a plain scalar array for rule matching.
     * Money objects carry MINOR units with no currency of their own, so
     * they are exposed as `amount_minor` (converted by the rule engine),
     * never as `amount` — reading cents as whole units would mis-post 100x.
     *
     * @return array<string, mixed>
     */
    private function normaliseEventData(object $event): array
    {
        $data = method_exists($event, 'toArray') ? $event->toArray() : get_object_vars($event);

        if (! is_array($data)) {
            return [];
        }

        $normalised = [];
        foreach ($data as $key => $value) {
            $normalised[(string) $key] = $this->normaliseValue($value);

            // A Money-typed property IS the event's amount: hoist its minor
            // units to the top level so rules can use `event.amount_minor`.
            if (is_array($normalised[(string) $key])
                && array_keys($normalised[(string) $key]) === ['amount_minor']
                && ! isset($normalised['amount_minor'])) {
                $normalised['amount_minor'] = $normalised[(string) $key]['amount_minor'];
            }
        }

        return $normalised;
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof AccountMoney) {
            return ['amount_minor' => $value->getAmount()];
        }

        if ($value instanceof Carbon || $value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            if (method_exists($value, 'getAmount') && is_numeric($value->getAmount())) {
                return ['amount_minor' => (int) $value->getAmount()];
            }

            if (method_exists($value, 'toArray')) {
                $array = $value->toArray();

                return is_array($array) ? $this->normaliseValue($array) : $array;
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return $this->normaliseValue(get_object_vars($value));
        }

        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[$key] = $this->normaliseValue($item);
            }

            return $normalised;
        }

        return $value;
    }

    /**
     * Stable id for the event: a declared id/uuid/reference when the event
     * carries one, otherwise a hash of its payload (deterministic per event
     * class + content, so a replay maps to the same idempotency key).
     *
     * @param array<string, mixed> $eventData
     */
    private function extractEventId(array $eventData): string
    {
        foreach (['id', 'uuid', 'aggregate_uuid', 'reference', 'transaction_id', 'order_id', 'trade_id', 'transfer_id'] as $key) {
            $value = $eventData[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return 'sha256:' . hash('sha256', (string) json_encode($eventData));
    }

    private function extractDomain(object $event): string
    {
        $class = $event::class;
        // Extract domain from namespace: App\Domain\{DomainName}\Events\...
        if (preg_match('/App\\\\Domain\\\\(\w+)\\\\/', $class, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }
}
