<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Kanin\Routing\Route;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    public function test_it_normalizes_the_uri_and_uppercases_methods(): void
    {
        $this->assertSame('/users', (new Route(['get'], 'users/', fn () => null))->uri());
        $this->assertSame('/', (new Route(['GET'], '', fn () => null))->uri());
        $this->assertSame('/', (new Route(['GET'], '/', fn () => null))->uri());
        $this->assertSame(['PUT', 'PATCH'], (new Route(['put', 'PATCH', 'put'], '/x', fn () => null))->methods());
    }

    public function test_it_keeps_the_action(): void
    {
        $closure = fn () => 'hi';

        $this->assertSame($closure, (new Route(['GET'], '/', $closure))->getAction());
        $this->assertSame(['A', 'b'], (new Route(['GET'], '/', ['A', 'b']))->getAction());
        $this->assertSame('A@b', (new Route(['GET'], '/', 'A@b'))->getAction());
    }

    public function test_static_paths_match_exactly(): void
    {
        $route = new Route(['GET'], '/users', fn () => null);

        $this->assertSame([], $route->matches('/users'));
        $this->assertSame([], $route->matches('/users/'));
        $this->assertNull($route->matches('/users/5'));
        $this->assertNull($route->matches('/user'));
        $this->assertNull($route->matches('/'));
    }

    public function test_the_root_route_matches_only_the_root(): void
    {
        $route = new Route(['GET'], '/', fn () => null);

        $this->assertSame([], $route->matches('/'));
        $this->assertNull($route->matches('/users'));
    }

    public function test_it_extracts_parameters(): void
    {
        $route = new Route(['GET'], '/users/{user}/posts/{post_id}', fn () => null);

        $this->assertSame(['user' => '7', 'post_id' => 'hello'], $route->matches('/users/7/posts/hello'));
    }

    public function test_default_parameter_pattern_does_not_cross_slashes(): void
    {
        $route = new Route(['GET'], '/files/{name}', fn () => null);

        $this->assertNull($route->matches('/files/a/b'));
        $this->assertNull($route->matches('/files'));
    }

    public function test_regex_characters_in_the_uri_are_literal(): void
    {
        $route = new Route(['GET'], '/v1.0/items', fn () => null);

        $this->assertSame([], $route->matches('/v1.0/items'));
        $this->assertNull($route->matches('/v1x0/items'));
    }

    public function test_optional_trailing_parameter_may_be_missing(): void
    {
        $route = new Route(['GET'], '/posts/{slug?}', fn () => null);

        $this->assertSame(['slug' => 'intro'], $route->matches('/posts/intro'));
        $this->assertSame([], $route->matches('/posts'));
        $this->assertNull($route->matches('/posts/a/b'));
    }

    public function test_optional_parameter_on_the_root(): void
    {
        $route = new Route(['GET'], '/{page?}', fn () => null);

        $this->assertSame([], $route->matches('/'));
        $this->assertSame(['page' => 'about'], $route->matches('/about'));
    }

    public function test_where_constrains_a_parameter(): void
    {
        $route = (new Route(['GET'], '/users/{id}', fn () => null))->where('id', '[0-9]+');

        $this->assertSame(['id' => '42'], $route->matches('/users/42'));
        $this->assertNull($route->matches('/users/abc'));
    }

    public function test_where_accepts_an_array_of_patterns(): void
    {
        $route = (new Route(['GET'], '/{year}/{slug}', fn () => null))
            ->where(['year' => '[0-9]{4}', 'slug' => '[a-z-]+']);

        $this->assertSame(['year' => '2026', 'slug' => 'hello-world'], $route->matches('/2026/hello-world'));
        $this->assertNull($route->matches('/26/hello-world'));
        $this->assertNull($route->matches('/2026/Hello'));
    }

    public function test_where_patterns_may_contain_groups_and_hashes(): void
    {
        $route = (new Route(['GET'], '/tags/{tag}', fn () => null))->where('tag', '(c#|php)');

        $this->assertSame(['tag' => 'c#'], $route->matches('/tags/c#'));
        $this->assertSame(['tag' => 'php'], $route->matches('/tags/php'));
        $this->assertNull($route->matches('/tags/go'));
    }

    public function test_where_without_a_pattern_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pattern is required for route parameter [id].');

        (new Route(['GET'], '/users/{id}', fn () => null))->where('id');
    }

    public function test_name_is_stored(): void
    {
        $route = new Route(['GET'], '/', fn () => null);

        $this->assertNull($route->getName());
        $this->assertSame($route, $route->name('home'));
        $this->assertSame('home', $route->getName());
    }

    public function test_middleware_is_appended_in_order(): void
    {
        $route = new Route(['GET'], '/', fn () => null);

        $this->assertSame([], $route->getMiddleware());
        $this->assertSame($route, $route->middleware('first'));
        $route->middleware(['second', 'third']);

        $this->assertSame(['first', 'second', 'third'], $route->getMiddleware());
    }
}
