<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * What `staticphp queue status`, `failed`, `retry` and `forget` need to ask a backend.
 *
 * Separate from QueueInterface because these are not what makes a queue work - a backend
 * that can run jobs but cannot list its own backlog is a real thing, and the five methods
 * a worker calls should not be held hostage to that. The commands ask for both, and say so
 * plainly when a driver only offers one.
 *
 * The shapes here are the database driver's, because that is the one somebody will already
 * have read the table of. A driver that stores things differently answers in these terms
 * anyway rather than inventing its own columns.
 */
interface QueueReports
{
    /**
     * The backlog per queue, split the ways that matter when something looks stuck.
     *
     * `pending` is what a worker could take right now, `delayed` is waiting for its time,
     * `reserved` is what a worker is holding.
     *
     * @access public
     * @return list<array{queue: string, pending: int, delayed: int, reserved: int, total: int}>
     */
    public function stats(): array;

    /**
     * @access public
     * @return int
     */
    public function failedCount(): int;

    /**
     * Recent failures, newest first.
     *
     * Keys used by the command: id, failed_at, queue, name, attempts, error.
     *
     * @access public
     * @param  int $limit
     * @return list<array<string, mixed>>
     */
    public function failedRows(int $limit): array;

    /**
     * Put failed jobs back on the queue.
     *
     * @access public
     * @param  ?int $id          One job, or null for all of them
     * @param  int  $maxAttempts
     * @return int How many were requeued
     */
    public function retryFailed(?int $id, int $maxAttempts): int;

    /**
     * Delete failed jobs, by id or by age.
     *
     * @access public
     * @param  ?int    $id
     * @param  ?string $before YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, read as UTC
     * @return int
     */
    public function forgetFailed(?int $id, ?string $before): int;
}
