<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Kanin\Container;
use Kanin\Http\JsonResponse;
use Kanin\Http\MethodNotAllowedHttpException;
use Kanin\Http\NotFoundHttpException;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Routing\Route;
use Kanin\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Routing\Fixtures\BlockMiddleware;
use Tests\Unit\Routing\Fixtures\PostController;
use Tests\Unit\Routing\Fixtures\TraceMiddleware;

class RouterTest extends TestCase
{
    private Container $container;

    private Router $router;

    protected function setUp(): void
    {
        TraceMiddleware::$log = [];
        $this->container = new Container();
        $this->router = new Router($this->container);
    }

    private function send(string $method, string $uri, array $parameters = []): Response
    {
        return $this->router->dispatch(Request::create($uri, $method, $parameters));
    }

    public function test_verb_methods_register_routes_with_the_right_methods(): void
    {
        $action = fn () => null;

        $this->assertSame(['GET'], $this->router->get('/a', $action)->methods());
        $this->assertSame(['POST'], $this->router->post('/a', $action)->methods());
        $this->assertSame(['PUT'], $this->router->put('/a', $action)->methods());
        $this->assertSame(['PATCH'], $this->router->patch('/a', $action)->methods());
        $this->assertSame(['DELETE'], $this->router->delete('/a', $action)->methods());
        $this->assertSame(['PUT', 'PATCH'], $this->router->match(['put', 'patch'], '/a', $action)->methods());
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $this->router->any('/a', $action)->methods());
        $this->assertCount(7, $this->router->routes());
        $this->assertContainsOnlyInstancesOf(Route::class, $this->router->routes());
    }

    public function test_it_dispatches_a_closure_and_converts_arrays_to_json(): void
    {
        $this->router->get('/hello', fn () => ['message' => 'hi']);

        $response = $this->send('GET', '/hello');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'hi'], $response->getData());
    }

    public function test_route_parameters_are_injected_and_cast(): void
    {
        $this->router->get('/users/{id}', fn (int $id) => ['id' => $id, 'type' => get_debug_type($id)]);

        $this->assertSame(['id' => 5, 'type' => 'int'], $this->send('GET', '/users/5')->getData());
    }

    public function test_non_numeric_value_for_an_int_parameter_is_a_404(): void
    {
        $this->router->get('/users/{id}', fn (int $id) => ['id' => $id]);

        $this->expectException(NotFoundHttpException::class);

        $this->send('GET', '/users/abc');
    }

    public function test_overflowing_value_for_an_int_parameter_is_a_404(): void
    {
        $this->router->get('/users/{id}', fn (int $id) => ['id' => $id]);

        $this->expectException(NotFoundHttpException::class);

        $this->send('GET', '/users/99999999999999999999');
    }

    public function test_non_numeric_value_for_a_float_parameter_is_a_404(): void
    {
        $this->router->get('/price/{amount}', fn (float $amount) => ['amount' => $amount]);

        $this->expectException(NotFoundHttpException::class);

        $this->send('GET', '/price/abc');
    }

    public function test_string_parameters_are_never_rejected(): void
    {
        $this->router->get('/tags/{name}', fn (string $name) => ['name' => $name]);

        $this->assertSame(['name' => 'abc'], $this->send('GET', '/tags/abc')->getData());
    }

    public function test_route_parameters_are_stored_on_the_request(): void
    {
        $this->router->get('/users/{id}', fn (Request $request) => ['id' => $request->route('id')]);

        $request = Request::create('/users/9');
        $response = $this->router->dispatch($request);

        $this->assertSame(['id' => '9'], $response->getData());
        $this->assertSame(['id' => '9'], $request->route());
        $this->assertSame($request, $this->container->make(Request::class));
    }

    public function test_missing_optional_parameter_uses_the_default(): void
    {
        $this->router->get('/greet/{name?}', [PostController::class, 'greet']);

        $this->assertSame('Hello, guest', $this->send('GET', '/greet')->getContent());
        $this->assertSame('Hello, Ada', $this->send('GET', '/greet/Ada')->getContent());
    }

    public function test_where_constraint_failure_is_a_404(): void
    {
        $this->router->get('/users/{id}', fn () => 'found')->where('id', '[0-9]+');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Not Found');

        $this->send('GET', '/users/abc');
    }

    public function test_unknown_path_is_a_404(): void
    {
        $this->router->get('/users', fn () => 'users');

        try {
            $this->send('GET', '/nope');
            $this->fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Not Found', $e->getMessage());
        }
    }

    public function test_known_path_with_wrong_method_is_a_405_listing_allowed_methods(): void
    {
        $this->router->get('/users', fn () => 'list');
        $this->router->post('/users', fn () => 'create');
        $this->router->get('/users/{id}', fn () => 'show');

        try {
            $this->send('DELETE', '/users');
            $this->fail('Expected a MethodNotAllowedHttpException.');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('Method Not Allowed', $e->getMessage());
            $this->assertSame(['Allow' => 'GET, POST'], $e->getHeaders());
        }
    }

    public function test_head_requests_use_get_routes(): void
    {
        $this->router->get('/ping', fn () => 'pong');

        $this->assertSame('pong', $this->send('HEAD', '/ping')->getContent());
    }

    public function test_method_override_reaches_put_routes(): void
    {
        $this->router->put('/posts/{id}', fn (int $id) => "updated {$id}");

        $this->assertSame('updated 3', $this->send('POST', '/posts/3', ['_method' => 'PUT'])->getContent());
    }

    public function test_first_matching_route_wins(): void
    {
        $this->router->get('/users/me', fn () => 'me');
        $this->router->get('/users/{id}', fn () => 'by id');

        $this->assertSame('me', $this->send('GET', '/users/me')->getContent());
        $this->assertSame('by id', $this->send('GET', '/users/4')->getContent());
    }

    public function test_array_and_at_sign_controller_actions(): void
    {
        $this->router->get('/a/{id}', [PostController::class, 'show']);
        $this->router->get('/b/{id}', PostController::class . '@show');

        $this->assertSame(['action' => 'show', 'id' => 1], $this->send('GET', '/a/1')->getData());
        $this->assertSame(['action' => 'show', 'id' => 2], $this->send('GET', '/b/2')->getData());
    }

    public function test_action_string_without_at_sign_is_refused(): void
    {
        $this->router->get('/x', 'PostController');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route action [PostController] must look like [Controller@method].');

        $this->send('GET', '/x');
    }

    public function test_malformed_action_array_is_refused(): void
    {
        $this->router->get('/x', [PostController::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route action arrays must look like [Controller::class, 'method'].");

        $this->send('GET', '/x');
    }

    public function test_unknown_controller_is_refused(): void
    {
        $this->router->get('/x', 'Missing\\Controller@index');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Controller [Missing\\Controller] does not exist.');

        $this->send('GET', '/x');
    }

    public function test_unknown_controller_method_is_refused(): void
    {
        $this->router->get('/x', [PostController::class, 'missing']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Method [' . PostController::class . '::missing] does not exist or is not public.');

        $this->send('GET', '/x');
    }

    public function test_private_controller_method_is_refused(): void
    {
        $this->router->get('/x', [PostController::class, 'secret']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Method [' . PostController::class . '::secret] does not exist or is not public.');

        $this->send('GET', '/x');
    }

    public function test_group_prefixes_nest(): void
    {
        $this->router->group(['prefix' => 'api'], function (Router $router): void {
            $router->get('/', fn () => 'api root');
            $router->group(['prefix' => '/v1/'], function (Router $router): void {
                $router->get('users', fn () => 'v1 users');
            });
            $router->get('status', fn () => 'status');
        });
        $this->router->get('/outside', fn () => 'outside');

        $uris = array_map(fn (Route $route) => $route->uri(), $this->router->routes());

        $this->assertSame(['/api', '/api/v1/users', '/api/status', '/outside'], $uris);
        $this->assertSame('v1 users', $this->send('GET', '/api/v1/users')->getContent());
    }

    public function test_group_closure_receives_the_router(): void
    {
        $received = null;
        $this->router->group([], function (Router $router) use (&$received): void {
            $received = $router;
        });

        $this->assertSame($this->router, $received);
    }

    public function test_group_middleware_runs_before_route_middleware(): void
    {
        $this->router->group(['middleware' => 'outer'], function (Router $router): void {
            $router->group(['middleware' => ['inner']], function (Router $router): void {
                $router->get('/x', fn () => 'x')->middleware('route');
            });
        });

        $this->assertSame(['outer', 'inner', 'route'], $this->router->routes()[0]->getMiddleware());
    }

    public function test_group_state_is_restored_after_an_exception(): void
    {
        try {
            $this->router->group(['prefix' => 'broken', 'middleware' => 'm'], function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $route = $this->router->get('/after', fn () => null);

        $this->assertSame('/after', $route->uri());
        $this->assertSame([], $route->getMiddleware());
    }

    public function test_route_middleware_aliases_are_resolved(): void
    {
        $this->router->aliasMiddleware('block', BlockMiddleware::class);
        $this->router->get('/secret', fn () => 'secret')->middleware('block');

        $response = $this->send('GET', '/secret');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getContent());
    }

    public function test_route_middleware_may_be_a_class_name_and_runs_in_order(): void
    {
        $this->router->group(['middleware' => TraceMiddleware::class], function (Router $router): void {
            $router->get('/x', function (): string {
                TraceMiddleware::$log[] = 'action';

                return 'x';
            });
        });

        $this->assertSame('x', $this->send('GET', '/x')->getContent());
        $this->assertSame(['trace:before', 'action', 'trace:after'], TraceMiddleware::$log);
    }

    public function test_unknown_middleware_alias_is_refused(): void
    {
        $this->router->get('/x', fn () => 'x')->middleware('nope');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware [nope] is not an alias or a class.');

        $this->send('GET', '/x');
    }

    public function test_api_resource_registers_five_named_routes(): void
    {
        $this->router->apiResource('posts', PostController::class);

        $summary = array_map(
            fn (Route $route) => [implode('|', $route->methods()), $route->uri(), $route->getName()],
            $this->router->routes(),
        );

        $this->assertSame([
            ['GET', '/posts', 'posts.index'],
            ['POST', '/posts', 'posts.store'],
            ['GET', '/posts/{id}', 'posts.show'],
            ['PUT|PATCH', '/posts/{id}', 'posts.update'],
            ['DELETE', '/posts/{id}', 'posts.destroy'],
        ], $summary);
    }

    public function test_api_resource_routes_dispatch_to_the_controller(): void
    {
        $this->router->apiResource('posts', PostController::class);

        $this->assertSame(['action' => 'index'], $this->send('GET', '/posts')->getData());

        $store = $this->send('POST', '/posts', ['title' => 'Hi']);
        $this->assertSame(201, $store->getStatusCode());
        $this->assertSame(['action' => 'store', 'title' => 'Hi'], $store->getData());

        $this->assertSame(['action' => 'show', 'id' => 4], $this->send('GET', '/posts/4')->getData());
        $this->assertSame(['action' => 'update', 'id' => 4, 'method' => 'PUT'], $this->send('PUT', '/posts/4')->getData());
        $this->assertSame(['action' => 'update', 'id' => 4, 'method' => 'PATCH'], $this->send('PATCH', '/posts/4')->getData());

        $destroy = $this->send('DELETE', '/posts/4');
        $this->assertSame(204, $destroy->getStatusCode());
        $this->assertSame('', $destroy->getContent());
    }

    public function test_api_resource_id_must_be_numeric(): void
    {
        $this->router->apiResource('posts', PostController::class);

        $this->expectException(NotFoundHttpException::class);

        $this->send('GET', '/posts/abc');
    }

    public function test_api_resource_only_option(): void
    {
        $this->router->apiResource('posts', PostController::class, ['only' => ['index', 'show']]);

        $names = array_map(fn (Route $route) => $route->getName(), $this->router->routes());
        $this->assertSame(['posts.index', 'posts.show'], $names);

        try {
            $this->send('POST', '/posts');
            $this->fail('Expected a MethodNotAllowedHttpException.');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(['Allow' => 'GET'], $e->getHeaders());
        }
    }

    public function test_api_resource_middleware_option_applies_to_every_route(): void
    {
        $this->router->apiResource('posts', PostController::class, ['middleware' => 'auth.token']);

        foreach ($this->router->routes() as $route) {
            $this->assertSame(['auth.token'], $route->getMiddleware());
        }
    }

    public function test_api_resource_inside_a_group_gets_prefix_and_group_middleware(): void
    {
        $this->router->group(['prefix' => 'api', 'middleware' => 'outer'], function (Router $router): void {
            $router->apiResource('posts', PostController::class, ['only' => ['store'], 'middleware' => ['inner']]);
        });

        $route = $this->router->routes()[0];
        $this->assertSame('/api/posts', $route->uri());
        $this->assertSame('posts.store', $route->getName());
        $this->assertSame(['outer', 'inner'], $route->getMiddleware());
    }

    public function test_api_resource_unknown_action_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown resource action(s) [shwo].');

        $this->router->apiResource('posts', PostController::class, ['only' => ['index', 'shwo']]);
    }

    public function test_url_builds_paths_for_named_routes(): void
    {
        $this->router->get('/', fn () => null)->name('home');
        $this->router->get('/users/{id}', fn () => null)->name('users.show');
        $this->router->get('/posts/{slug?}', fn () => null)->name('posts');

        $this->assertSame('/', $this->router->url('home'));
        $this->assertSame('/users/5', $this->router->url('users.show', ['id' => 5]));
        $this->assertSame('/posts', $this->router->url('posts'));
        $this->assertSame('/posts/intro', $this->router->url('posts', ['slug' => 'intro']));
    }

    public function test_url_encodes_values_and_appends_leftovers_as_query_string(): void
    {
        $this->router->get('/users/{name}', fn () => null)->name('users.show');

        $this->assertSame(
            '/users/Ada%20Lovelace?page=2&sort=name',
            $this->router->url('users.show', ['name' => 'Ada Lovelace', 'page' => 2, 'sort' => 'name']),
        );
    }

    public function test_url_includes_group_prefix(): void
    {
        $this->router->group(['prefix' => 'api'], function (Router $router): void {
            $router->apiResource('users', PostController::class);
        });

        $this->assertSame('/api/users/3', $this->router->url('users.show', ['id' => 3]));
        $this->assertSame('/api/users', $this->router->url('users.index'));
    }

    public function test_url_for_unknown_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route [missing] is not defined.');

        $this->router->url('missing');
    }

    public function test_url_without_a_required_parameter_is_refused(): void
    {
        $this->router->get('/users/{id}', fn () => null)->name('users.show');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter [id] for route [users.show].');

        $this->router->url('users.show');
    }

    public function test_url_with_a_non_scalar_parameter_is_refused(): void
    {
        $this->router->get('/users/{id}', fn () => null)->name('users.show');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route parameter [id] must be a string or number.');

        $this->router->url('users.show', ['id' => [1, 2]]);
    }

    public function test_to_response_returns_responses_unchanged(): void
    {
        $response = new Response('hi', 202);

        $this->assertSame($response, Router::toResponse($response));
    }

    public function test_to_response_converts_arrays_and_json_serializable_to_json(): void
    {
        $fromArray = Router::toResponse(['a' => 1]);
        $fromObject = Router::toResponse(new class () implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['b' => 2];
            }
        });

        $this->assertInstanceOf(JsonResponse::class, $fromArray);
        $this->assertSame(['a' => 1], $fromArray->getData());
        $this->assertInstanceOf(JsonResponse::class, $fromObject);
        $this->assertSame(['b' => 2], $fromObject->getData());
    }

    public function test_to_response_converts_strings_and_stringables_to_text(): void
    {
        $stringable = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'from object';
            }
        };

        $fromString = Router::toResponse('plain');
        $fromObject = Router::toResponse($stringable);

        $this->assertNotInstanceOf(JsonResponse::class, $fromString);
        $this->assertSame('plain', $fromString->getContent());
        $this->assertSame(200, $fromString->getStatusCode());
        $this->assertSame('from object', $fromObject->getContent());
    }

    public function test_to_response_converts_null_to_204(): void
    {
        $response = Router::toResponse(null);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function test_to_response_refuses_other_values(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('A route action returned an unsupported value of type [int].');

        Router::toResponse(42);
    }
}
