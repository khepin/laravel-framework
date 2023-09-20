<?php

namespace Illuminate\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;

class ChainedBatch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public array|Collection $jobs;

    public array $options;

    public string $name;

    public function __construct(PendingBatch $batch)
    {
        $this->jobs = $batch->jobs;
        $this->options = $batch->options;
        $this->name = $batch->name;
    }

    public function handle(Container $container)
    {
        $batch = new PendingBatch($container, $this->jobs);
        $batch->name = $this->name;
        $batch->options = $this->options;

        $this->hijackChain($batch);

        if ($this->queue) {
            $batch->onQueue($this->queue);
        }

        if ($this->connection) {
            $batch->onConnection($this->connection);
        }

        foreach ($this->chainCatchCallbacks as $cb) {
            $batch->catch($cb);
        }

        $batch->dispatch();
    }

    protected function hijackChain(PendingBatch $batch)
    {
        if (property_exists($this, 'chained') && ! empty($this->chained)) {
            $next = unserialize(array_shift($this->chained));
            $next->chained = $this->chained;

            $next->onConnection($next->connection ?: $this->chainConnection);
            $next->onQueue($next->queue ?: $this->chainQueue);

            $next->chainConnection = $this->chainConnection;
            $next->chainQueue = $this->chainQueue;
            $next->chainCatchCallbacks = $this->chainCatchCallbacks;

            $batch->then(fn () => dispatch($next));

            $this->chained = [];
        }
    }
}
