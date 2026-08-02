<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * Runs queued jobs, in a process that is not serving a request.
 *
 * The loop is deliberately dull: reserve one job, run it, record what happened, repeat.
 * Everything interesting is about stopping - on a signal, on a limit, or because the
 * database went away - because a worker is a long-lived process and the ways it ends badly
 * are what make a queue untrustworthy.
 *
 * Two ways to deploy it, and the class supports both because which one is available is a
 * hosting question rather than a design one:
 *
 *   - Supervised: `staticphp queue work` under systemd or supervisord, restarted when it
 *     exits. Jobs start within `sleep` seconds of being queued.
 *   - Cron: `staticphp queue work --max-time=55` every minute. No daemon to supervise,
 *     nothing to restart on deploy, at the cost of up to a minute of latency.
 *
 * Neither is a fallback for the other. Pick by what the host will let you run.
 */
class Worker
{
    private QueueInterface $queue;
    private string $id;
    private bool $shouldQuit = false;
    private bool $inJob = false;

    /** @var ?callable(string): void */
    private $out;

    /** @var callable(string): Handler */
    private $resolver;

    /**
     * @access public
     * @param QueueInterface           $queue
     * @param ?callable(string): void  $out      Receives one line at a time, without a trailing newline
     * @param ?callable(string): Handler $resolver Builds a handler from a job name
     * @param ?string                  $id       Identifies this worker in the table
     */
    public function __construct(
        QueueInterface $queue,
        ?callable $out = null,
        ?callable $resolver = null,
        ?string $id = null
    ) {
        $this->queue = $queue;
        $this->out = $out;
        $this->resolver = ($resolver ?? Queue::handler(...));

        $host = gethostname();
        $this->id = substr(($id ?? (is_string($host) ? $host : 'worker') . ':' . getmypid()), 0, 64);
    }

    /**
     * Work until told to stop.
     *
     * @access public
     * @param  list<string> $queues        In precedence order
     * @param  int          $timeout       Visibility timeout, and the best-effort job time limit
     * @param  int          $sleep         Seconds to wait when there is nothing to do
     * @param  int          $maxJobs       Stop after this many jobs, 0 for no limit
     * @param  int          $maxTime       Stop after this many seconds, 0 for no limit
     * @param  int          $memoryLimit   Stop when the process passes this many MB, 0 for no limit
     * @param  bool         $stopWhenEmpty Stop as soon as there is nothing left rather than waiting
     * @return int Process exit code
     */
    public function run(
        array $queues,
        int $timeout = 300,
        int $sleep = 1,
        int $maxJobs = 0,
        int $maxTime = 0,
        int $memoryLimit = 0,
        bool $stopWhenEmpty = false
    ): int {
        if ($queues === []) {
            $queues = ['default'];
        }

        $timeout = max(1, $timeout);
        $this->listen();

        $started = time();
        $done = 0;
        $failures = 0;

        $this->line('Worker ' . $this->id . ' watching ' . implode(', ', $queues));

        while (true) {
            if ($this->shouldQuit === true) {
                $this->line('Stopping: asked to shut down');
                break;
            }

            try {
                $job = $this->queue->reserve($queues, $timeout, $this->id);
                $failures = 0;
            } catch (\Throwable $exception) {
                $failures++;

                // A database that has gone away comes back, usually. One that has not come
                // back after five tries is not going to while this process waits for it,
                // and exiting non-zero is how a supervisor is told to start a fresh one.
                $this->line("error: could not reserve a job: {$exception->getMessage()}");

                if ($failures >= 5) {
                    $this->line('Stopping: the queue has been unreadable five times running');

                    return 1;
                }

                $this->rest($sleep);
                continue;
            }

            if ($job === null) {
                if ($stopWhenEmpty === true) {
                    $this->line('Nothing left to do');
                    break;
                }

                $stop = $this->limitReached($started, $done, $maxJobs, $maxTime, $memoryLimit);
                if ($stop !== null) {
                    $this->line("Stopping: {$stop}");
                    break;
                }

                $this->rest($sleep);
                continue;
            }

            $this->runJob($job, $timeout);
            $done++;

            $stop = $this->limitReached($started, $done, $maxJobs, $maxTime, $memoryLimit);
            if ($stop !== null) {
                $this->line("Stopping: {$stop}");
                break;
            }
        }

        $this->line("Ran {$done} " . ($done === 1 ? 'job' : 'jobs'));

        return 0;
    }

    /**
     * Reserve and run at most one job.
     *
     * @access public
     * @param  list<string> $queues
     * @param  int          $timeout
     * @return bool Whether there was anything to run
     */
    public function runNext(array $queues, int $timeout = 300): bool
    {
        $job = $this->queue->reserve(($queues === [] ? ['default'] : $queues), max(1, $timeout), $this->id);
        if ($job === null) {
            return false;
        }

        $this->runJob($job, max(1, $timeout));

        return true;
    }

    /**
     * @access public
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Run one reserved job and record what happened to it.
     *
     * @access private
     * @param  Job $job
     * @param  int $timeout
     * @return void
     */
    private function runJob(Job $job, int $timeout): void
    {
        $this->line("-> {$job->name} #{$job->id} (attempt {$job->attempts}/{$job->maxAttempts})");
        $started = microtime(true);

        try {
            $this->inJob = true;
            $this->alarm($timeout);

            $handler = ($this->resolver)($job->name);
            $handler->handle($job->payload, $job);

            $this->alarm(0);
            $this->inJob = false;
        } catch (\Throwable $exception) {
            $this->alarm(0);
            $this->inJob = false;
            $this->jobFailed($job, $exception);

            return;
        }

        if ($job->wasReleased() === true) {
            $this->queue->release($job, $job->releaseDelay(), '');
            $this->line("   released, back in {$job->releaseDelay()}s");

            return;
        }

        $this->queue->delete($job);
        $this->line(sprintf('   done in %dms', (int) round((microtime(true) - $started) * 1000)));
    }

    /**
     * @access private
     * @param  Job        $job
     * @param  \Throwable $exception
     * @return void
     */
    private function jobFailed(Job $job, \Throwable $exception): void
    {
        $detail = $exception::class . ': ' . $exception->getMessage()
            . "\n" . $exception->getFile() . ':' . $exception->getLine()
            . "\n" . $exception->getTraceAsString();

        if ($job->isLastAttempt() === true) {
            $this->queue->fail($job, $detail);
            $this->line("   failed for good: {$exception->getMessage()}");

            // Also to the error log, because the trail a supervisor keeps is the process
            // output, and nobody reads that until they already know something is wrong.
            error_log("Queue job {$job->name} #{$job->id} failed permanently: {$exception->getMessage()}");

            return;
        }

        $delay = Queue::backoff($job->attempts);
        $this->queue->release($job, $delay, $detail);
        $this->line("   failed, retrying in {$delay}s: {$exception->getMessage()}");
    }

    /**
     * Why the loop should stop, or null to carry on.
     *
     * @access private
     * @param  int $started
     * @param  int $done
     * @param  int $maxJobs
     * @param  int $maxTime
     * @param  int $memoryLimit
     * @return ?string
     */
    private function limitReached(int $started, int $done, int $maxJobs, int $maxTime, int $memoryLimit): ?string
    {
        if ($maxJobs > 0 && $done >= $maxJobs) {
            return "ran {$done} " . ($done === 1 ? 'job' : 'jobs');
        }

        if ($maxTime > 0 && (time() - $started) >= $maxTime) {
            return "been going for {$maxTime}s";
        }

        if ($memoryLimit > 0) {
            $used = (int) round(memory_get_usage(true) / 1048576);
            if ($used >= $memoryLimit) {
                return "using {$used}MB";
            }
        }

        return null;
    }

    /**
     * Wait, but not through a shutdown request.
     *
     * @access private
     * @param  int $seconds
     * @return void
     */
    private function rest(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->shouldQuit === true) {
                return;
            }

            sleep(1);
        }
    }

    /**
     * Finish the job in hand before exiting.
     *
     * Without this a deploy that restarts workers kills whatever was running, and the job
     * only comes back once its visibility timeout expires - so a restart looks like
     * minutes of nothing happening. With it, SIGTERM sets a flag and the loop stops at the
     * next clean point.
     *
     * @access private
     * @return void
     */
    private function listen(): void
    {
        if (function_exists('pcntl_async_signals') === false || function_exists('pcntl_signal') === false) {
            $this->line('note: ext-pcntl is not loaded, so this worker cannot shut down gracefully');

            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldQuit = true;
            });
        }

        pcntl_signal(SIGALRM, function (): void {
            if ($this->inJob === true) {
                throw new QueueError('The job ran past the timeout');
            }
        });
    }

    /**
     * Set or clear the per-job time limit.
     *
     * Best effort, and honestly so: the signal is only delivered when control is back in
     * PHP, so a job blocked in a long database query runs until the query returns. What it
     * does catch is the runaway loop and the retry that never gives up, which is most of
     * what makes a worker stop working.
     *
     * @access private
     * @param  int $seconds Zero cancels
     * @return void
     */
    private function alarm(int $seconds): void
    {
        if (function_exists('pcntl_alarm') === true) {
            pcntl_alarm(max(0, $seconds));
        }
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function line(string $line = ''): void
    {
        if ($this->out !== null) {
            ($this->out)($line);
        }
    }
}
