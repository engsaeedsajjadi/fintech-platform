<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Support;

use RuntimeException;

/**
 * Cross-process mutex for meeting reservations, one lock file per user.
 *
 * Why a file lock and not only a DB transaction: the conflict check in
 * MeetingService is check-then-insert, so two concurrent requests for the
 * same host/participant can both pass the check before either inserts.
 * `lockForUpdate()` on the meeting rows does not help — there is no row to
 * lock yet (phantom reads) — and SQLite ignores `FOR UPDATE` entirely.
 * Locking the involved *user* rows helps on MySQL but not on SQLite, so
 * this flock-based mutex is the portable serialisation point: schedule()
 * and reschedule() hold the locks for every involved user (sorted, so two
 * requests can never deadlock against each other) for the whole
 * check-and-insert transaction. The DB transaction + row locks remain in
 * place as a second layer on MySQL.
 *
 * Locks are best-effort local serialisation, not a distributed lock: on a
 * multi-node deployment a shared lock (Redis/database advisory lock) is
 * required instead. The lock directory is created under storage/framework
 * and is never used for any other purpose.
 */
final class MeetingReservationLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    private bool $released = false;

    /**
     * Acquire exclusive locks for all given user UUIDs, in sorted order.
     *
     * @param  list<string>  $userUuids
     */
    public static function acquire(array $userUuids, float $timeoutSeconds = 10.0): self
    {
        $lock = new self();
        $uuids = array_values(array_unique(array_filter($userUuids)));
        sort($uuids);

        $dir = storage_path('framework/meeting-locks');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create meeting lock directory.');
        }

        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        foreach ($uuids as $uuid) {
            $path = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uuid) . '.lock';
            $handle = fopen((string) $path, 'c');
            if ($handle === false) {
                $lock->release();
                throw new RuntimeException('Unable to open meeting lock file.');
            }

            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    fclose($handle);
                    $lock->release();

                    return $lock->failed();
                }
                usleep(50_000);
            }

            $lock->handles[$uuid] = $handle;
        }

        return $lock;
    }

    /** Non-blocking probe used by tests: true when the lock is held by someone else. */
    public static function isLocked(string $userUuid): bool
    {
        $dir = storage_path('framework/meeting-locks');
        $path = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $userUuid) . '.lock';
        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        $locked = ! flock($handle, LOCK_EX | LOCK_NB);
        if (! $locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $locked;
    }

    private function failed(): self
    {
        $this->released = true;

        return $this;
    }

    public function acquired(): bool
    {
        return ! $this->released;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        foreach ($this->handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->handles = [];
    }

    public function __destruct()
    {
        $this->release();
    }
}
