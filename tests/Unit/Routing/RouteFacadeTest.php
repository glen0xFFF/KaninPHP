<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Kanin\Container;
use Kanin\Routing\Route;
use Kanin\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Routing\Fixtures\PostController;

class RouteFacadeTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $container = new Container();
        $this->router = new Router($container);
        $container->instance(Router::class, $this->router);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function test_verb_methods_register_on_the_container_router(): void
    {
        $action = fn () => null;

        $get = Route::get('/a', $action);
        Route::post('/b', $action);
        Route::put('/c', $action);
        Route::patch('/d', $action);
        Route::delete('/e', $action);
        Route::match(['PUT', 'PATCH'], '/f', $action);
        Route::any('/g', $action);

        $summary = array_map(
            fn (Route $route) => implode('|', $route->methods()) . ' ' . $route->uri(),
            $this->router->routes(),
        );

        $this->assertSame($get, $this->router->routes()[0]);
        $this->assertSame([
            'GET /a',
            'POST /b',
            'PUT /c',
            'PATCH /d',
            'DELETE /e',
            'PUT|PATCH /f',
            'GET|POST|PUT|PATCH|DELETE /g',
        ], $summary);
    }

    public function test_group_and_api_resource_go_through_the_facade(): void
    {
        Route::group(['prefix' => 'api', 'middleware' => 'auth.token'], function (): void {
            Route::apiResource('posts', PostController::class, ['only' => ['index']]);
        });

        $route = $this->router->routes()[0];

        $this->assertSame('/api/posts', $route->uri());
        $this->assertSame('posts.index', $route->getName());
        $this->assertSame(['auth.token'], $route->getMiddleware());
    }
}
