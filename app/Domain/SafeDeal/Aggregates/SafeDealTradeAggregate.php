<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Aggregates;

use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Events\SafeDealEvent;
use App\Domain\SafeDeal\Events\TradeAudited;
use App\Domain\SafeDeal\Models\SafeDealAuditRecord;
use App\Domain\SafeDeal\Models\SafeDealEventRecord;
use App\Domain\SafeDeal\Models\SafeDealTrade;
use App\Domain\SafeDeal\Repositories\SafeDealTradeRepository;
use App\Domain\SafeDeal\StateMachines\SafeDealStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventDispatcher;

/**
 * SafeDeal trade aggregate root.
 *
 * Design note (vs AgentProtocol's EscrowAggregate): AgentProtocol uses
 * Spatie event sourcing with its own stored-events/snapshot tables and
 * float amounts. SafeDeal deliberately does NOT reuse that aggregate:
 * the trade lifecycle here is human/business (verification, documents,
 * milestones, dual approval, disputes) rather than agent-to-agent, and
 * amounts are decimal strings. State changes go through the explicit
 * SafeDealStateMachine, every change records an immutable row in the
 * append-only safe_deal_events table, and the Eloquent row is the
 * current-state projection. The terminal transition additionally writes
 * the final SafeDealAuditRecord.
 */
class SafeDealTradeAggregate
{
    private SafeDealTrade $trade;

    private function __construct(SafeDealTrade $trade)
    {
        $this->trade = $trade;
    }

    public static function retrieve(string $tradeId): self
    {
        $trade = app(SafeDealTradeRepository::class)->findOrFail($tradeId);

        return new self($trade);
    }

    public static function fromModel(SafeDealTrade $trade): self
    {
        return new self($trade);
    }

    public function trade(): SafeDealTrade
    {
        return $this->trade->fresh() ?? $this->trade;
    }

    public function status(): TradeStatus
    {
        return $this->trade->status;
    }

    /**
     * Apply a state-machine action + record a domain event, atomically.
     *
     * @param array<string, mixed> $attributes Extra model attributes to persist with the transition.
     */
    public function transition(string $action, SafeDealEvent $event, array $attributes = []): self
    {
        $from = $this->trade->status;
        $to = SafeDealStateMachine::apply($from, $action);

        DB::transaction(function () use ($to, $event, $attributes): void {
            $this->trade->fill($attributes);
            $this->trade->status = $to;
            $this->trade->save();

            $this->recordEvent($event);
        });

        $this->trade->refresh();

        EventDispatcher::dispatch($event);

        if ($to->isTerminal()) {
            $this->writeAuditRecord($to);
        }

        return $this;
    }

    /**
     * Record a domain event without a state change (e.g. document submitted
     * while already in DOCUMENTS_PENDING).
     */
    public function record(SafeDealEvent $event): self
    {
        DB::transaction(function () use ($event): void {
            $this->recordEvent($event);
        });

        EventDispatcher::dispatch($event);

        return $this;
    }

    private function recordEvent(SafeDealEvent $event): void
    {
        SafeDealEventRecord::create([
            'trade_id'    => $this->trade->id,
            'event'       => $event->name(),
            'payload'     => $event->toArray(),
            'occurred_at' => now(),
        ]);
    }

    private function writeAuditRecord(TradeStatus $terminal): void
    {
        if (SafeDealAuditRecord::where('trade_id', $this->trade->id)->exists()) {
            return;
        }

        $events = SafeDealEventRecord::where('trade_id', $this->trade->id)
            ->orderBy('id')
            ->get(['event', 'payload', 'occurred_at']);

        $historyHash = hash('sha256', (string) json_encode(
            $events->map(fn ($e) => [$e->event, $e->payload])->all(),
            JSON_THROW_ON_ERROR
        ));

        $snapshot = $this->trade->fresh()->toArray();
        unset($snapshot['metadata']);

        $audit = SafeDealAuditRecord::create([
            'trade_id'           => $this->trade->id,
            'trade_uuid'         => $this->trade->trade_id,
            'final_status'       => $terminal->value,
            'event_history'      => $events->map(fn ($e) => $e->event)->all(),
            'event_history_hash' => $historyHash,
            'ledger_entry_ids'   => (array) ($this->trade->metadata['ledger_entry_ids'] ?? []),
            'snapshot'           => $snapshot,
            'audited_at'         => now(),
        ]);

        $this->recordEvent(new TradeAudited($this->trade->trade_id, [
            'audit_record_id'    => $audit->id,
            'final_status'       => $terminal->value,
            'event_history_hash' => $historyHash,
        ]));
    }
}
