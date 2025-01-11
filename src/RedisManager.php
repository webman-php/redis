<?php

namespace Webman\Redis;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use StdClass;
use Throwable;
use Webman\Context;
use Webman\Coroutine\Pool;

class RedisManager extends \Illuminate\Redis\RedisManager
{
    /**
     * @var Pool[]
     */
    protected static array $pools = [];

    /**
     * Get connection.
     *
     * @param $name
     * @return Connection|mixed|StdClass|null
     * @throws Throwable
     */
    public function connection($name = null)
    {
        $name = $name ?: 'default';
        $key = "redis.connections.$name";
        $connection = Context::get($key);
        if (!$connection) {
            if (!isset(static::$pools[$name])) {
                $poolConfig = $this->config[$name]['pool'] ?? [];
                $pool = new Pool($poolConfig['max_connections'] ?? 10, $poolConfig);
                $pool->setConnectionCreator(function () use ($name) {
                    $connection = $this->configure($this->resolve($name), $name);
                    if (class_exists(Dispatcher::class)) {
                        $connection->setEventDispatcher(new Dispatcher());
                    }
                    return $connection;
                });
                $pool->setConnectionCloser(function ($connection) {
                    $connection->client()->close();
                });
                $pool->setHeartbeatChecker(function ($connection) {
                    return $connection->ping();
                });
                static::$pools[$name] = $pool;
            }
            try {
                $connection = static::$pools[$name]->get();
                Context::set($key, $connection);
            } finally {
                Context::onDestroy(function () use ($connection, $name) {
                    try {
                        $connection && static::$pools[$name]->put($connection);
                    } catch (Throwable) {
                        // ignore
                    }
                });
            }
        }
        return $connection;
    }
}