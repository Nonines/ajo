<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;

final class RedisQueueProbe implements ShouldQueue
{
    public function __construct(public string $key) {}

    public function handle(): void
    {
        Cache::store('redis')->put($this->key, 'processed', 60);
    }
}

test('Horizon provisions dedicated capacity for each workload', function (string $environment) {
    $plan = new ProvisioningPlan('test-master', config('horizon.environments'), config('horizon.defaults'));

    foreach (['default', 'webhooks', 'accrual', 'payouts'] as $queue) {
        $options = $plan->optionsFor($environment, 'supervisor-'.$queue);

        expect($options)->not->toBeNull()
            ->and($options->connection)->toBe('redis')
            ->and($options->queue)->toBe($queue)
            ->and($options->minProcesses)->toBeGreaterThanOrEqual(1)
            ->and($options->maxProcesses)->toBeGreaterThanOrEqual($options->minProcesses)
            ->and($options->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    }
})->with(['local', 'production']);

test('Redis locks reject competitors and allow acquisition after release', function () {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and a Redis service.');
    }
    $key = 'redis-test:'.Str::uuid();
    $lock = Cache::store('redis')->lock($key, 10);
    $competitor = Cache::store('redis')->lock($key, 10);

    try {
        expect($lock->get())->toBeTrue()
            ->and($competitor->get())->toBeFalse();
        $lock->release();
        expect($competitor->get())->toBeTrue();
    } finally {
        $lock->release();
        $competitor->release();
    }
})->group('redis');

test('Redis queues persist and execute jobs with isolated queue names', function (string $workload) {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and a Redis service.');
    }
    $key = 'redis-test:'.Str::uuid();
    $queueName = $key.':'.$workload;
    $queue = Queue::connection('redis');
    $job = null;

    try {
        $queue->push(new RedisQueueProbe($key), queue: $queueName);
        expect($queue->size($queueName))->toBe(1);
        $job = $queue->pop($queueName);
        expect($job)->not->toBeNull();
        $job->fire();
        $job->delete();

        expect(Cache::store('redis')->get($key))->toBe('processed')
            ->and($queue->size($queueName))->toBe(0);
    } finally {
        $job?->delete();
        $queue->clear($queueName);
        Cache::store('redis')->forget($key);
    }
})->with(['webhooks', 'accrual', 'payouts'])->group('redis');
