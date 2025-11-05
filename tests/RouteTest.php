<?php

use PHPUnit\Framework\TestCase;
use Route\Route;

require_once __DIR__ . '/../src/Route.php';
require_once __DIR__ . '/../src/RouteHelper.php';

class RouteTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset Route internals before each test
        $ref = new ReflectionClass(Route::class);
        foreach ($ref->getStaticProperties() as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);

            if (is_array($value)) {
                $property->setValue([]);
            } else {
                $property->setValue('');
            }
        }
    }

    public function testBasicGetRoute()
    {
        ob_start();
        Route::get('/hello', fn() => 'Hello World');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/hello';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('Hello World', $output);
    }

    public function testDynamicParameter()
    {
        ob_start();
        Route::get('/user/{id}', fn($id) => "User:$id");
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/user/99';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('User:99', $output);
    }

    public function testMiddlewareStopsExecution()
    {
        ob_start();
        Route::registerMiddleware('auth', fn() => false);
        Route::get('/secure', fn() => 'You should not see this')->middleware(['auth']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/secure';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('', $output);
    }

    public function testMiddlewareAllowsExecution()
    {
        ob_start();
        Route::registerMiddleware('pass', fn() => true);
        Route::get('/home', fn() => 'Welcome')->middleware(['pass']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/home';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('Welcome', $output);
    }

    public function testGroupPrefix()
    {
        ob_start();
        Route::group('/admin', [], function() {
            Route::get('/dashboard', fn() => 'Dashboard OK');
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/dashboard';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('Dashboard OK', $output);
    }

    public function testNamedRoute()
    {
        Route::get('/posts', fn() => '')->name('posts.list');
        $url = Route::view('posts.list');
        $this->assertEquals('/posts', $url);
    }

    public function testNotFoundHandler()
    {
        ob_start();
        Route::notFound(fn() => print('Custom 404'));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/nope';
        Route::dispatch();
        $output = ob_get_clean();

        $this->assertEquals('Custom 404', $output);
    }
}
