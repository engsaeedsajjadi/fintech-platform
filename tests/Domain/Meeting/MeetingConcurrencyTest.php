<?php

declare(strict_types=1);

use App\Domain\Meeting\Exceptions\MeetingConflictException;
use App\Domain\Meeting\Services\MeetingService;
use App\Domain\Meeting\Support\MeetingReservationLock;
use App\Models\User;
use Carbon\Carbon;

/**
 * Concurrency: two overlapping reservations for the same host must never
 * both succeed. The serialisation point is MeetingReservationLock (flock)
 * plus row locks inside the transaction; SQLite ignores FOR UPDATE, so
 * the flock is what actually serialises on the test database.
 */
it('rejects a second reservation while one is in progress for the same host', function () {
    config(['meeting.lock_timeout_seconds' => 0.5]);

    // Hold the host's reservation lock externally, as a concurrent
    // request would, then attempt to schedule for the same host.
    $lock = MeetingReservationLock::acquire([$this->user->uuid], 5.0);
    expect($lock->acquired())->toBeTrue();

    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];

    try {
        expect(fn () => app(MeetingService::class)->schedule($this->user, [
            'title' => 'Blocked', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        ]))->toThrow(MeetingConflictException::class);
    } finally {
        $lock->release();
    }

    expect(\App\Domain\Meeting\Models\Meeting::count())->toBe(0);

    // Once the lock is free the same slot books normally.
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'After lock', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
    expect($meeting->id)->not->toBeNull();
});

it('reschedule also serialises on the reservation lock', function () {
    config(['meeting.lock_timeout_seconds' => 0.5]);

    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'To move', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    $lock = MeetingReservationLock::acquire([$this->user->uuid], 5.0);

    try {
        expect(fn () => app(MeetingService::class)->reschedule(
            $meeting, $this->user, $startsAt->copy()->addDay(), $endsAt->copy()->addDay()
        ))->toThrow(MeetingConflictException::class);
    } finally {
        $lock->release();
    }

    expect($meeting->refresh()->starts_at->equalTo($startsAt))->toBeTrue();
});

it('the reservation lock excludes a second process holding the same user lock', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $uuid = User::factory()->create()->uuid;
    $parentLock = MeetingReservationLock::acquire([$uuid], 5.0);
    expect($parentLock->acquired())->toBeTrue();

    // Child 1: lock is held by the parent, so a short acquisition fails.
    $pid = pcntl_fork();
    if ($pid === 0) {
        $childLock = MeetingReservationLock::acquire([$uuid], 0.3);
        exit($childLock->acquired() ? 1 : 0);
    }
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status))->toBe(0);

    $parentLock->release();

    // Child 2: after release the same lock can be acquired.
    $pid = pcntl_fork();
    if ($pid === 0) {
        $childLock = MeetingReservationLock::acquire([$uuid], 2.0);
        $ok = $childLock->acquired();
        $childLock->release();
        exit($ok ? 0 : 1);
    }
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status))->toBe(0);
});

it('locks are taken in sorted order so two overlapping requests cannot deadlock', function () {
    $a = User::factory()->create()->uuid;
    $b = User::factory()->create()->uuid;

    $first = MeetingReservationLock::acquire([$b, $a], 2.0);
    expect($first->acquired())->toBeTrue();
    expect(MeetingReservationLock::isLocked($a))->toBeTrue()
        ->and(MeetingReservationLock::isLocked($b))->toBeTrue();
    $first->release();

    expect(MeetingReservationLock::isLocked($a))->toBeFalse();
});
