<?php

declare(strict_types=1);

/*
 * API routes. Everything in this file is loaded under the /api prefix,
 * so get('/') answers GET /api and apiResource('users', ...) answers /api/users.
 *
 * The $router variable is provided by the application while it boots.
 */

use App\Controllers\UserController;
use Kanin\Application;
use Kanin\Routing\Router;

/** @var Router $router */

$router->get('/', fn () => ['name' => config('app.name'), 'version' => Application::VERSION])->name('home');

// Anyone may read users: GET /api/users and GET /api/users/{id}.
$router->apiResource('users', UserController::class, ['only' => ['index', 'show']]);

// Changing users needs a valid "Authorization: Bearer <token>" header (see API_TOKENS in .env).
$router->group(['middleware' => 'auth.token'], fn (Router $router) => $router->apiResource('users', UserController::class, ['only' => ['store', 'update', 'destroy']]));
