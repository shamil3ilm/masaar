<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run a callback with the app reporting itself as handling a request.
     *
     * TenantScope stands down in console context, because commands and queue
     * workers carry no credential to derive a tenant from. PHPUnit is console,
     * so a plain feature test — including one issuing $this->get() — exercises
     * the unscoped path and cannot see a tenant leak.
     *
     * Any test whose subject depends on tenant scoping has to wrap the part it
     * is asserting on.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asRequest(callable $callback): mixed
    {
        $app = app();
        $flag = new ReflectionProperty($app, 'isRunningInConsole');
        $original = $app->runningInConsole();

        $flag->setValue($app, false);

        try {
            return $callback();
        } finally {
            $flag->setValue($app, $original);
        }
    }
}
