# KaninPHP

KaninPHP is a small, readable, API-first PHP framework for beginners. It gives you
routing, controllers, middleware, JSON responses, validation, a safe query builder and
simple models, and nothing you do not need to build a JSON REST API.

The whole framework lives in the `system/` folder and is meant to be read. It has no
runtime dependencies besides PHP itself, and the classes are documented. Most core
classes are under about 300 lines; a few are around 370 and are planned to be split in
v1.0.1, so you can still open any file and follow it in one sitting.

- Laravel-flavoured ergonomics (`Route::get()`, `$request->validate()`, `User::create()`),
  CodeIgniter-level simplicity.
- JSON in, JSON out: every response and every error is JSON.
- Safe by default: bound query values, validated identifiers, mass-assignment protection,
  fail-closed token authentication and no error details in production.

Current version: **1.0.0**. See [CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP **8.5** or newer, with the `pdo`, `mbstring` and `json` extensions.
- The `pdo_sqlite` extension for the demo app and the test suite (or `pdo_mysql` /
  `pdo_pgsql` if you use MySQL or PostgreSQL).
- [Composer](https://getcomposer.org/) 2 (used only for autoloading and PHPUnit).

Check your setup with:

```bash
php --version
php -m
composer --version
```

## Installation

```bash
git clone https://github.com/glen0xFFF/KaninPHP.git my-api
cd my-api
composer install
cp .env.example .env
```

Open `.env` and replace `API_TOKENS=change-me` with a strong secret. You can generate one with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

## Quick Start

Create the SQLite database with the demo `users` table, then start the development server:

```bash
php kanin db:seed
php kanin serve
```

`db:seed` creates `database/database.sqlite` and runs `database/seed.sql` (a `users`
table with three people). `serve` starts PHP's built-in web server on
`http://127.0.0.1:8000` with `public/` as the document root.

Try the API from a second terminal:

```bash
curl http://127.0.0.1:8000/api
```

```json
{"name":"KaninPHP","version":"1.0.0"}
```

```bash
curl "http://127.0.0.1:8000/api/users?per_page=2"
```

```json
{"data":[{"id":1,"name":"Ada Lovelace","email":"ada@example.com","created_at":"2026-10-13 09:00:00"},{"id":2,"name":"Alan Turing","email":"alan@example.com","created_at":"2026-10-13 09:00:00"}],"meta":{"current_page":1,"per_page":2,"total":3,"last_page":2}}
```

(`created_at` shows the moment you ran `db:seed`.)

Creating, updating and deleting users needs a bearer token from `API_TOKENS`:

```bash
curl -X POST http://127.0.0.1:8000/api/users \
  -H "Authorization: Bearer change-me" \
  -H "Content-Type: application/json" \
  -d '{"name":"Linus Torvalds","email":"linus@example.com"}'
```

This answers `201 Created` with the new user as JSON. Without the token you get
`401` and `{"message":"Unauthenticated."}`.

The demo endpoints are listed below. `PATCH` uses the same rules as `PUT`, so both
need `name` **and** `email`: KaninPHP v1 has no partial updates.

| Method | URI | Action | Token needed |
|---|---|---|---|
| GET | `/api` | Welcome message | no |
| GET | `/api/users` | List users (paginated, `?page=` and `?per_page=`) | no |
| GET | `/api/users/{id}` | Show one user | no |
| POST | `/api/users` | Create a user | yes |
| PUT / PATCH | `/api/users/{id}` | Update a user (send both `name` and `email`) | yes |
| DELETE | `/api/users/{id}` | Delete a user (`204 No Content`) | yes |

## Directory Structure

```text
KaninPHP/
├─ app/
│  ├─ Controllers/      your controllers (UserController.php in the demo)
│  ├─ Middleware/       your own middleware
│  └─ Models/           your models (User.php in the demo)
├─ config/              app.php, database.php, cors.php, auth.php
├─ database/            seed.sql and database.sqlite (the .sqlite file is gitignored)
├─ public/              index.php and .htaccess: the ONLY folder the web server exposes
├─ routes/              api.php: every route here is served under /api
├─ storage/logs/        kanin.log (errors are written here)
├─ system/              the framework itself (namespace Kanin\)
├─ tests/               Unit/, Feature/ and TestCase.php
├─ kanin                the command-line tool (php kanin help)
├─ .env.example         copy to .env
└─ composer.json, phpunit.xml, README.md, CHANGELOG.md, LICENSE
```

Namespaces: `Kanin\` maps to `system/`, `App\` maps to `app/` and `Tests\` maps to `tests/`.

How a request flows through the framework:

1. The web server sends every request that is not a real file to `public/index.php`.
2. `index.php` creates `Kanin\Application` and calls `run()`.
3. The application boots: it loads `.env`, the files in `config/`, the timezone, the
   services (router, database connection, error handler) and `routes/api.php` under the
   `/api` prefix.
4. The request passes through the global middleware (CORS), then the route middleware
   (for example `auth.token`), then reaches your controller.
5. Whatever your controller returns is turned into a response. Any exception is turned
   into a JSON error response.

## Configuration

Settings come from two places:

- **`.env`** holds values that change per machine (debug mode, database, tokens). It is
  gitignored, so secrets never end up in your repository. Real environment variables
  always win over values in `.env`.
- **`config/*.php`** files return arrays and read `.env` values with `env()`.

| Key | Default | Meaning |
|---|---|---|
| `APP_NAME` | `KaninPHP` | Name shown by `GET /api`. |
| `APP_ENV` | `production` | `local`, `testing` or `production`. |
| `APP_DEBUG` | `false` | `true` shows exception details in error responses. Never enable it in production. |
| `APP_URL` | `http://localhost:8000` | Base URL used by the `url()` helper. |
| `APP_TIMEZONE` | `UTC` | Default timezone for dates. |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql`. |
| `DB_DATABASE` | `database/database.sqlite` | SQLite file path, or the database name for MySQL / PostgreSQL. |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` (`5432` for pgsql) | MySQL / PostgreSQL server. |
| `DB_USERNAME`, `DB_PASSWORD` | `root` (`postgres` for pgsql), empty | MySQL / PostgreSQL credentials. |
| `CORS_ALLOWED_ORIGINS` | `*` | Comma-separated list of origins allowed to call the API. |
| `API_TOKENS` | empty | Comma-separated bearer tokens accepted by `auth.token`. Empty means nobody is allowed. |

The defaults above are what `config/*.php` falls back to when a key is missing. The
`.env.example` file sets `APP_ENV=local` and `APP_DEBUG=true` for development. For MySQL
the default `DB_DATABASE` is `kanin`.

In `.env`, the words `true`, `false`, `null` and `empty` become the real values `true`,
`false`, `null` and `''`. Lines starting with `#` are comments.

Read any setting with the `config()` helper and dot notation (`file.key.subkey`):

```php
$name = config('app.name');
$driver = config('database.connections.sqlite.driver');
$perPage = config('app.missing_key', 15); // a missing key returns the default (15)
```

Prefer `config()` in your code and keep `env()` inside the files in `config/`.

## Routing

Routes live in `routes/api.php`. The file receives a `$router` variable, and every route
in it is automatically prefixed with `/api`.

```php
use App\Controllers\UserController;
use Kanin\Http\Request;
use Kanin\Routing\Router;

$router->get('/hello', fn () => ['message' => 'Hello!']);           // GET /api/hello

$router->post('/echo', fn (Request $request) => $request->all());     // POST /api/echo

$router->get('/users/{id}', [UserController::class, 'show']);         // controller action

$router->get('/posts/{id}', 'App\Controllers\PostController@show');   // "Class@method" string

$router->match(['GET', 'POST'], '/search', fn () => ['results' => []]);

$router->any('/ping', fn () => 'pong');                               // GET, POST, PUT, PATCH, DELETE
```

Available methods: `get`, `post`, `put`, `patch`, `delete`, `match` and `any`. `HEAD`
requests are answered by the matching `GET` route automatically.

You can also use the static `Route` facade, which talks to the same router:

```php
use Kanin\Routing\Route;

Route::get('/status', fn () => ['ok' => true]);
```

### Route parameters

Parameters are written in braces. They are passed to your closure or controller method
by **name**, and numeric values are cast to `int` when you type-hint `int`:

```php
$router->get('/users/{id}', fn (int $id) => ['id' => $id]);

// An optional parameter ends with "?" and needs a default value.
$router->get('/archive/{year?}', fn (int $year = 2026) => ['year' => $year]);

// Constrain a parameter with a regular expression.
$router->get('/orders/{code}', fn (string $code) => ['code' => $code])
    ->where('code', '[A-Z]{3}[0-9]+');
```

By default a parameter matches anything except `/`. Inside a request you can also read
route parameters with `$request->route('id')`.

### Named routes and URLs

```php
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.profile');

$path = route('users.profile', ['id' => 7]);                 // "/api/users/7"
$withQuery = route('users.profile', ['id' => 7, 'tab' => 'a']); // "/api/users/7?tab=a"
$full = url('/api/users/7');                                 // APP_URL + "/api/users/7"
```

`route()` throws an `InvalidArgumentException` if the name does not exist or a required
parameter is missing. Extra parameters become the query string.

### Route groups

Groups share a URI prefix and/or middleware. Groups can be nested:

```php
use Kanin\Routing\Router;

$router->group(['prefix' => 'admin', 'middleware' => 'auth.token'], function (Router $router) {
    $router->get('/stats', fn () => ['users' => db('users')->count()]); // GET /api/admin/stats

    $router->group(['prefix' => 'reports'], function (Router $router) {
        $router->get('/daily', fn () => ['report' => 'daily']);        // GET /api/admin/reports/daily
    });
});
```

### API resources

`apiResource()` registers the five standard REST routes for a controller in one line:

```php
use App\Controllers\PostController;

$router->apiResource('posts', PostController::class);
```

| Method | URI | Controller method | Route name |
|---|---|---|---|
| GET | `/api/posts` | `index` | `posts.index` |
| POST | `/api/posts` | `store` | `posts.store` |
| GET | `/api/posts/{id}` | `show` | `posts.show` |
| PUT / PATCH | `/api/posts/{id}` | `update` | `posts.update` |
| DELETE | `/api/posts/{id}` | `destroy` | `posts.destroy` |

`{id}` only matches digits. Use the options to register a subset or to add middleware:

```php
use App\Controllers\PostController;

$router->apiResource('posts', PostController::class, ['only' => ['index', 'show']]);
$router->apiResource('posts', PostController::class, [
    'only' => ['store', 'update', 'destroy'],
    'middleware' => 'auth.token',
]);
```

### 404 and 405

An unknown path answers `404` with `{"message":"Not Found"}`. A known path with the wrong
HTTP method answers `405` with `{"message":"Method Not Allowed"}` and an `Allow` header
listing the methods that do work (for example `Allow: GET, POST`).

HTML forms cannot send `PUT`, `PATCH` or `DELETE`. Send a `POST` with a `_method` field or
an `X-HTTP-Method-Override` header instead and KaninPHP treats it as that method.

## Controllers

Controllers are plain classes in `app/Controllers/`. Create one with:

```bash
php kanin make:controller PostController --api
```

Each method receives what it type-hints: the `Request`, route parameters (by name) and
any class the container can build.

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Post;
use Kanin\Http\JsonResponse;
use Kanin\Http\Request;

final class PostController
{
    /**
     * GET /api/posts
     */
    public function index(Request $request): array
    {
        return Post::query()
            ->orderBy('id')
            ->paginate((int) $request->query('per_page', 15), (int) $request->query('page', 1));
    }

    /**
     * POST /api/posts
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
        ]);

        return response(Post::create($data), 201);
    }

    /**
     * GET /api/posts/{id}
     */
    public function show(int $id): Post
    {
        return Post::findOrFail($id);
    }

    /**
     * PUT/PATCH /api/posts/{id}
     */
    public function update(Request $request, int $id): Post
    {
        $post = Post::findOrFail($id);
        $post->fill($request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
        ]))->save();

        return $post;
    }

    /**
     * DELETE /api/posts/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Post::findOrFail($id)->delete();

        return response(null, 204);
    }
}
```

Controllers are created by the container, so you can also type-hint dependencies in the
constructor (for example `Kanin\Database\Connection` or `Kanin\Support\Config`).

## Requests and Validation

Type-hint `Kanin\Http\Request` to get the current request (or call `request()` anywhere).
JSON bodies are decoded automatically when the `Content-Type` is JSON.

| Method | Returns |
|---|---|
| `$request->input('name', 'default')` | A body or query value (body wins). |
| `$request->all()` | Body merged over the query string. |
| `$request->only('name', 'email')` | Just those keys (if present). |
| `$request->except('password')` | Everything except those keys. |
| `$request->has('email')` | Whether the key is present. |
| `$request->query('page', 1)` | A query-string value only. |
| `$request->header('Accept')` | A header (case-insensitive) or `null`. |
| `$request->bearerToken()` | The token from `Authorization: Bearer <token>`, or `null`. |
| `$request->route('id')` | A route parameter. |
| `$request->method()` | A `Kanin\Http\Method` enum, for example `Method::POST`. |
| `$request->path()` | The path, for example `/api/users/1`. |
| `$request->isJson()` | Whether the body is JSON. |

A request with a broken JSON body is answered with `400` and
`{"message":"Malformed JSON body."}`.

### Validating input

`$request->validate()` checks the input and returns **only the validated fields**. If
anything fails, KaninPHP stops and answers `422`.

```php
use Kanin\Http\Request;

$router->post('/register', function (Request $request) {
    $data = $request->validate([
        'name' => 'required|string|max:100',
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => 'required|string|min:12|confirmed',
    ], [
        'email.unique' => 'That email is already registered.',
    ]);

    return response(['registered' => $data['email']], 201);
});
```

Posting `{"email":"not-an-email"}` to that route answers `422` (the `password` rules fail
too, because the field is missing):

```json
{"message":"The given data was invalid.","errors":{"name":["The name field is required."],"email":["The email must be a valid email address."],"password":["The password field is required."]}}
```

Rules can be a pipe string (`'required|max:100'`) or an array (`['required', 'max:100']`).
Custom messages are keyed `field.rule` or just `rule`; `:attribute` is replaced by the
field name.

| Rule | Passes when |
|---|---|
| `required` | The field is present and not empty. |
| `nullable` | The field may be `null` (other rules are skipped then). |
| `string` | The value is a string. |
| `email` | The value is a valid email address. |
| `numeric` | The value is a number or numeric string. |
| `integer` | The value is an integer. |
| `min:n` | At least `n` characters (or value `n` for numbers, `n` items for arrays). |
| `max:n` | At most `n` characters (or value `n` for numbers, `n` items for arrays). |
| `between:a,b` | Size between `a` and `b`, measured like `min` / `max`. |
| `confirmed` | A matching `<field>_confirmation` field exists. |
| `in:a,b,c` | The value is one of the listed values. |
| `same:other` | The value equals the `other` field. |
| `alpha_num` | Only letters and digits. |
| `url` | The value is a valid URL. |
| `unique:table,column` | No row in `table` has this value in `column`. Add `,id` to ignore one row (`unique:users,email,5`) and `,idColumn` if the key is not `id`. |
| `exists:table,column` | A row in `table` has this value in `column`. |

A field that is missing or empty and is not `required` skips its other rules. Numbers are
compared by value when the field also has `numeric` or `integer`. An unknown rule name
throws an `InvalidArgumentException`, so typos are caught immediately.

You can also validate any array yourself:

```php
use Kanin\Validation\Validator;

$validator = Validator::make(['age' => '17'], ['age' => 'required|integer|min:18']);

if ($validator->fails()) {
    return response(['errors' => $validator->errors()], 422);
}
```

## Responses

Return whatever is most natural; KaninPHP converts it:

| You return | Response |
|---|---|
| an `array`, a model or any `JsonSerializable` | `200` JSON |
| a `string` (or `Stringable`) | `200` plain text |
| `null` | `204 No Content` |
| a `Kanin\Http\Response` | sent as it is |

Anything else (for example an `int`) is a programming error and becomes a `500`.

Use the `response()` helper to choose the status code or add headers:

```php
use Kanin\Http\Response;

$router->post('/things', fn () => response(['created' => true], 201));

$router->delete('/things/{id}', fn (int $id) => response(null, 204));

$router->get('/cached', fn () => response(['ok' => true], 200, ['Cache-Control' => 'max-age=60']));

$router->get('/robots.txt', fn () => new Response("User-agent: *\nDisallow: /\n", 200, [
    'Content-Type' => 'text/plain; charset=UTF-8',
]));
```

`response()` returns a `Kanin\Http\JsonResponse`. You can change a response afterwards
with `->header('X-Name', 'value')` and `->setStatusCode(202)`.

To stop with an error from anywhere, call `abort()`:

```php
abort(403, 'You cannot edit this post.');   // {"message":"You cannot edit this post."}
abort(404);                                  // {"message":"Not Found"}
```

`abort(404)` throws a `Kanin\Http\NotFoundHttpException`. `abort(404, $message, $headers)`
with a non-empty `$headers` array throws a plain `HttpException` with status `404`
instead, and keeps those headers on the response. `Model::findOrFail()` also answers `404`,
with a message such as `{"message":"Post not found."}`.

## Middleware

Middleware runs before (and after) your route. KaninPHP ships with two:

| Middleware | Where it runs | What it does |
|---|---|---|
| `Kanin\Middleware\HandleCors` | on every request (global) | Adds CORS headers and answers browser preflight requests. |
| `Kanin\Middleware\AuthenticateToken` | routes with the `auth.token` alias | Requires a valid bearer token. |

Global middleware is listed in `config/app.php` under `middleware`; route middleware
aliases are listed under `middleware_aliases`.

### CORS

`config/cors.php` controls which websites may call your API from a browser:

| Key | Default |
|---|---|
| `allowed_origins` | from `CORS_ALLOWED_ORIGINS`, default `['*']` |
| `allowed_methods` | `GET, POST, PUT, PATCH, DELETE, OPTIONS` |
| `allowed_headers` | `Content-Type, Authorization, Accept, X-Requested-With` |
| `exposed_headers` | none |
| `max_age` | `600` seconds |
| `supports_credentials` | `false` |

A preflight request (`OPTIONS` with `Access-Control-Request-Method`) is answered right
away with `204` and the `Access-Control-Allow-*` headers. Requests from origins that are
not allowed get no CORS headers, so the browser blocks them. In production, list your
real front-end origins:

```dotenv
CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
```

Keep `supports_credentials` set to `false` unless you really need cookies. Allowing every
origin (`*`) **and** setting `supports_credentials` to `true` makes the API reflect any
website's origin together with credentials, which is unsafe for production (the same
warning is written in `config/cors.php`). If you turn credentials on, list explicit origins.

### Token authentication

`auth.token` compares the request's `Authorization: Bearer <token>` header against the
comma-separated tokens in `API_TOKENS`, using a timing-safe comparison. If the token is
missing or wrong (or `API_TOKENS` is empty), the request is refused with `401`,
`{"message":"Unauthenticated."}` and a `WWW-Authenticate: Bearer` header.

```php
use App\Controllers\UserController;

$router->get('/me', fn () => ['ok' => true])->middleware('auth.token');

$router->apiResource('users', UserController::class, [
    'only' => ['store', 'update', 'destroy'],
    'middleware' => 'auth.token',
]);
```

Use several tokens to rotate them without downtime: add the new one, update your
clients, then remove the old one.

```dotenv
API_TOKENS=7f3c0d6e8a0b4c1d9e2f5a6b7c8d9e0f,1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d
```

### Writing your own middleware

```bash
php kanin make:middleware AddRequestId
```

A middleware implements `Kanin\Http\Middleware`. Call `$next($request)` to continue to
the route and get its response back; return a response without calling `$next` to stop
the request early.

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

final class AddRequestId implements Middleware
{
    /**
     * Give every response a random X-Request-Id header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->header('X-Request-Id', bin2hex(random_bytes(8)));
    }
}
```

Use it on a route by class name, or give it a short alias in `config/app.php`:

```php
use App\Middleware\AddRequestId;

$router->get('/traced', fn () => ['ok' => true])->middleware(AddRequestId::class);
```

```php
use App\Middleware\AddRequestId;
use Kanin\Middleware\AuthenticateToken;
use Kanin\Middleware\HandleCors;

return [
    // config/app.php (other keys omitted)
    'middleware' => [HandleCors::class, AddRequestId::class],   // runs on every request
    'middleware_aliases' => [
        'auth.token' => AuthenticateToken::class,
        'request.id' => AddRequestId::class,                    // ->middleware('request.id')
    ],
];
```

Middleware listed first runs first (outermost). Middleware is created by the container,
so its constructor can type-hint `Kanin\Support\Config` or other services.

## Database

### Connection

`config/database.php` defines the `sqlite`, `mysql` and `pgsql` connections; `DB_CONNECTION`
picks one. SQLite needs no server: the default file is `database/database.sqlite`.

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kanin
DB_USERNAME=root
DB_PASSWORD=secret
```

`db()` returns the `Kanin\Database\Connection`. Use it for raw SQL with `?` placeholders.
**Always pass values as bindings, never by concatenating strings:**

```php
use Kanin\Database\Connection;

$users = db()->select('select * from users where email like ?', ['%@example.com']);
$ada = db()->selectOne('select * from users where id = ?', [1]);
db()->insert('insert into users (name, email) values (?, ?)', ['Mary', 'mary@example.com']);
$changed = db()->update('update users set name = ? where id = ?', ['Mary Shelley', 4]);
$removed = db()->delete('delete from users where id = ?', [4]);

// All-or-nothing: an exception rolls everything back.
db()->transaction(function (Connection $db) {
    $db->insert('insert into users (name, email) values (?, ?)', ['Ann', 'ann@example.com']);
    $db->insert('insert into users (name, email) values (?, ?)', ['Bob', 'bob@example.com']);
});
```

### Query builder

`db('table')` starts a query builder. Every value is bound, and table names, column names,
operators and sort directions are checked, so user input cannot inject SQL.

```php
$users = db('users')
    ->select('id', 'name', 'email as contact')
    ->where('name', 'like', 'A%')
    ->orWhere('id', '>', 2)
    ->orderBy('id', 'desc')
    ->limit(10)
    ->offset(0)
    ->get();

$ada = db('users')->where('email', 'ada@example.com')->first();   // two arguments mean "="
$one = db('users')->find(1);
$count = db('users')->whereIn('id', [1, 2, 3])->count();
$anyEmail = db('users')->whereNotNull('email')->exists();

$id = db('users')->insert(['name' => 'Katherine Johnson', 'email' => 'katherine@example.com']);
db('users')->where('id', $id)->update(['name' => 'Katherine G. Johnson']);
db('users')->where('id', $id)->delete();

$sql = db('users')->where('id', 1)->toSql();    // select * from "users" where "id" = ?
```

Two things to watch out for:

- `where('col', null)` compares with `= NULL`, which never matches any row in SQL. To
  test for missing values use `whereNull('col')` or `whereNotNull('col')`.
- `orWhere()` conditions are **not** wrapped in parentheses. The query
  `where(a)->orWhere(b)` means `a OR b`, so `where('id', 1)->orWhere('name', 'Ada')->delete()`
  removes row 1 **and** every other row named Ada, wherever it is. Be careful when you
  combine `orWhere()` with `update()` or `delete()`, and prefer a single `where()` for
  writes.

Allowed operators: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `like` and `not like`. Sort
directions: `asc` and `desc`. Anything else throws an `InvalidArgumentException`.

`update()` and `delete()` **refuse to run without a `where`** (they throw a
`LogicException`), so you cannot wipe a whole table by accident.

### Pagination

```php
$page = db('users')->orderBy('id')->paginate(15, 2);   // 15 per page, page 2
```

```json
{"data":[],"meta":{"current_page":2,"per_page":15,"total":3,"last_page":1}}
```

`per_page` is clamped to 1–100 and `page` starts at 1. A very large page number is
capped to a safe maximum (no error), so you simply get an empty `data` list.

## Models

A model is a class in `app/Models/` that represents one table. Create one with
`php kanin make:model Post`.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Kanin\Database\Model;

final class Post extends Model
{
    /** Columns that create() and fill() may set. Everything else is ignored. */
    protected array $fillable = ['title', 'body'];

    /** Columns that never appear in JSON output. */
    protected array $hidden = ['internal_notes'];
}
```

The table name is the plural snake_case class name (`Post` uses `posts`, `BlogPost` uses
`blog_posts`, `Category` uses `categories`). Override it with
`protected string $table = 'my_posts';` and the key with `protected string $primaryKey`.

```php
use App\Models\Post;

$post = Post::create(['title' => 'Hello', 'body' => 'First post']);  // INSERT, returns the model
$post->title = 'Hello, world';                                       // set an attribute
$post->save();                                                       // UPDATE

$same = Post::find($post->getKey());          // the model, or null
$sure = Post::findOrFail(1);                  // the model, or throws NotFoundHttpException (a 404 JSON response)
$all = Post::all();                           // array of models
$recent = Post::where('id', '>', 10)->orderBy('id', 'desc')->get();
$paged = Post::query()->paginate(10, 1);      // models inside "data"

$post->fill(['title' => 'Edited', 'id' => 999]);  // "id" is not fillable, so it is ignored
$array = $post->toArray();                        // attributes without $hidden ones
$post->delete();
```

Returning a model (or an array of models) from a route sends it as JSON, without the
`$hidden` columns. Models do not include relationships or migrations; keep your schema
in an SQL file like `database/seed.sql`.

## Error Handling

Every error becomes a JSON response:

| Situation | Status | Body |
|---|---|---|
| Validation failed | `422` | `{"message":"The given data was invalid.","errors":{...}}` |
| `abort()` / `HttpException` | its status | `{"message":"..."}` (plus its headers) |
| Unknown route | `404` | `{"message":"Not Found"}` |
| Wrong HTTP method | `405` | `{"message":"Method Not Allowed"}` + `Allow` header |
| Malformed JSON body | `400` | `{"message":"Malformed JSON body."}` |
| Missing / wrong token | `401` | `{"message":"Unauthenticated."}` |
| Any other exception | `500` | `{"message":"Server Error"}` |

With `APP_DEBUG=true`, a `500` instead shows `message`, `exception` (the class), `file`,
`line` and `trace` to help you debug. With `APP_DEBUG=false` none of that leaks.

Errors with status `500` and above are written to `storage/logs/kanin.log`. PHP warnings
and notices are turned into exceptions, so they are never silently ignored.

Throw an HTTP error with any status yourself:

```php
use Kanin\Http\HttpException;

throw new HttpException(409, 'This email is already being processed.');
```

## Helpers

These global functions are available everywhere in your application:

| Helper | Returns |
|---|---|
| `app()` / `app(Some::class)` | The application container, or a service built by it. |
| `config('app.name', $default)` | A configuration value (dot notation). |
| `env('APP_DEBUG', $default)` | A value from the environment or `.env`. |
| `request()` / `request('email')` | The current request, or one input value. |
| `response($data, 201, $headers)` | A `JsonResponse`. |
| `route('users.show', ['id' => 1])` | The path of a named route. |
| `url('/api/users')` | A full URL built from `APP_URL`. |
| `db()` / `db('users')` | The database connection, or a query builder for a table. |
| `abort(404, 'message')` | Never returns: stops with an HTTP error response. |
| `dd($value)` | Never returns: dumps the values and stops (for debugging only). |

## CLI Reference

Run `php kanin help` to see every command.

| Command | What it does |
|---|---|
| `php kanin help` | Lists the commands (also `--help` and `-h`). |
| `php kanin serve [--host=127.0.0.1] [--port=8000]` | Starts the PHP development server with `public/` as the docroot. |
| `php kanin routes` | Prints every route as a table: method, URI, name and action. |
| `php kanin make:controller Name [--api]` | Creates `app/Controllers/Name.php` (`--api` adds the five resource methods). |
| `php kanin make:model Name` | Creates `app/Models/Name.php`. |
| `php kanin make:middleware Name` | Creates `app/Middleware/Name.php`. |
| `php kanin db:seed` | Runs `database/seed.sql` (and creates the SQLite file if it is missing). |

Class names must start with a capital letter and contain only letters and digits
(`PostController`, not `post-controller`). The generators never overwrite an existing
file. Errors (an invalid name, an existing file, an invalid host or port, an unknown
command) end with exit code `1`.

`db:seed` **drops and recreates** the demo `users` table. The bundled `seed.sql` uses
SQLite syntax; write your own for MySQL or PostgreSQL.

## Testing

KaninPHP uses PHPUnit 12. Tests run against an in-memory SQLite database, so they never
touch `database/database.sqlite`.

```bash
composer test
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature
vendor/bin/phpunit --filter RouterTest
```

Feature tests extend `Tests\TestCase`, which boots a fresh application with the seeded
demo data for every test and gives you JSON request helpers: `getJson()`, `postJson()`,
`putJson()`, `patchJson()`, `deleteJson()`, `json()`, `withToken()` (uses the test token
`test-token` from `phpunit.xml`) and `decode()`.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class UsersExampleTest extends TestCase
{
    public function test_lists_the_seeded_users(): void
    {
        $response = $this->getJson('/api/users');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $this->decode($response)['meta']['total']);
    }

    public function test_creating_a_user_requires_a_token(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'Ann', 'email' => 'ann@example.com']);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_creates_a_user_with_a_token(): void
    {
        $response = $this->withToken()->postJson('/api/users', [
            'name' => 'Ann',
            'email' => 'ann@example.com',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('ann@example.com', $this->decode($response)['email']);
    }
}
```

## Deployment

1. Install production dependencies only:
   `composer install --no-dev --optimize-autoloader`.
2. Create `.env` on the server with `APP_ENV=production`, **`APP_DEBUG=false`**, a strong
   `API_TOKENS` value and your real `CORS_ALLOWED_ORIGINS`.
3. Make `storage/logs/` (and `database/` when you use SQLite) writable by the web server.
4. Point the web server's **document root at `public/`**, never at the project root.

### Apache

Set `DocumentRoot` to the `public/` folder and allow `.htaccess` overrides with
`mod_rewrite` enabled. The bundled `public/.htaccess` sends every request to
`index.php` and passes the `Authorization` header through to PHP.

```apache
<VirtualHost *:80>
    ServerName api.example.com
    DocumentRoot /var/www/my-api/public

    <Directory /var/www/my-api/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The root `.htaccess` denies all access, as a safety net if the document root is ever
pointed at the project folder by mistake.

### Nginx

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/my-api/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

`php kanin serve` uses PHP's built-in server, which is for development only. Do not use
it in production.

## Security Notes

- **Docroot is `public/`.** `.env`, `config/`, `database/` and `storage/` must never be
  reachable over HTTP.
- **Never run production with `APP_DEBUG=true`.** In production a `500` only says
  `{"message":"Server Error"}`; details go to `storage/logs/kanin.log`.
- **SQL injection:** the query builder binds every value and validates every identifier,
  operator and direction. With raw SQL, always use `?` bindings.
- **Unscoped writes are refused:** `update()` and `delete()` without `where` throw.
- **Mass assignment:** `create()` and `fill()` only set `$fillable` columns; `$hidden`
  columns are never serialized. Add passwords and secrets to `$hidden`.
- **Tokens:** use long random tokens (`php -r "echo bin2hex(random_bytes(32));"`), keep
  them in `.env`, and only send them over HTTPS. An empty `API_TOKENS` denies every
  protected request. Tokens are compared with `hash_equals`.
- **CORS:** `*` is convenient in development; list your real origins in production.
  Credentials are off by default.
- **Validate all input** with `$request->validate()` and use the returned array, not
  `$request->all()`.
- Report security issues privately to the maintainers (for example with a GitHub security
  advisory) instead of opening a public issue.

## Contributing

Contributions are welcome:

1. Fork the repository and create a branch (`git checkout -b feat/my-change`).
2. Write a failing test first, then the code that makes it pass.
3. Run `composer test`; the whole suite must pass.
4. Follow the code style: PSR-12, 4-space indentation, `declare(strict_types=1);` in every
   PHP file, short methods with a docblock written for beginners.
5. Keep the framework small: no runtime dependencies, and keep core classes short (most are
   under about 300 lines; the few larger ones are slated for splitting in v1.0.1).
6. Use conventional commit messages (`feat(routing): ...`, `fix(database): ...`) and open
   a pull request describing what changed and why.

## License

KaninPHP is open-source software licensed under the [MIT license](LICENSE).
