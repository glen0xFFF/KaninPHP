# KaninPHP v1 — Framework Design

- **Date:** 2026-10-13 (revision 2: API-first, lightweight)
- **Status:** Approved
- **Audience:** Beginner PHP developers
- **Style:** Lightweight, API-first, Laravel-flavoured ergonomics with CodeIgniter-level simplicity
- **Release:** v1.0.0 public release (README is the primary documentation)

## 1. Goal

Turn the KaninPHP v0.0.1 skeleton (which currently fatals: `Rice` is never loaded, routing is empty) into a small, complete, readable **JSON API framework** that a beginner can install, read end to end, and build a REST API with.

Success means:

1. `composer install && php kanin db:seed && php kanin serve` serves `GET /api` (welcome JSON) and a `/api/users` REST resource backed by SQLite with no extra setup.
2. The full PHPUnit suite passes on PHP 8.5.
3. A beginner can read any core class in one sitting (target: no core class over ~300 lines; whole `system/` under ~3,000 lines).
4. The README fully documents installation, every feature, and the CLI for the public release.

## 2. Constraints

- **PHP:** `^8.5`. Every PHP file uses `declare(strict_types=1)`.
- **Runtime dependencies:** none beyond PHP extensions `pdo`, `mbstring`, `json`. Composer only for PSR-4 autoloading.
- **Dev dependencies:** `phpunit/phpunit` ^12.
- **Namespaces:** framework `Kanin\` → `system/`; application `App\` → `app/`; tests `Tests\` → `tests/`.
- **License:** MIT.
- **Not included (lightweight):** HTML views/templates, sessions, CSRF, cookies-based auth, ORM relationships, migrations framework, queues, events, cache, localization.
- Modern syntax (readonly, enums, `match`, promoted constructors) used freely; newest 8.4/8.5 syntax only where clearer.

## 3. Directory layout

```
KaninPHP/
├─ app/
│  ├─ Controllers/        UserController.php
│  ├─ Middleware/         (app-level middleware)
│  └─ Models/             User.php
├─ config/                app.php, database.php, cors.php, auth.php
├─ database/              seed.sql, database.sqlite (gitignored)
├─ routes/                api.php        (loaded under the /api prefix)
├─ public/                index.php, .htaccess
├─ system/                Kanin\ framework core (§4)
├─ storage/logs/          (.gitkeep; contents gitignored)
├─ tests/                 Unit/, Feature/, TestCase.php
├─ kanin                  CLI entry script
├─ .env.example, .gitignore, composer.json, phpunit.xml, README.md, LICENSE, CHANGELOG.md
```

Removed: root `index.php`, `system/Rice.php`, `system/core.php`, `system/app.php`, `system/route.php`, `system/Exception/error.html`, `system/.htaccess`. A root `.htaccess` denies all direct access; the docroot is `public/`.

## 4. Components (`system/`)

| Area | Classes | Responsibility |
|---|---|---|
| Core | `Application`, `Container` | Boot (`.env` → config → services → `routes/api.php`), handle Request → Response, DI container with autowiring and `call()`. |
| Support | `Env`, `Config`, `helpers.php` | `.env` parsing, dot-notation config, global helpers (`app config env response route url request db abort dd`). |
| HTTP | `Method` enum, `Request`, `Response`, `JsonResponse`, `HttpException` (+ `NotFound`, `MethodNotAllowed`), `Middleware` interface | Testable request wrapper with JSON body parsing, `bearerToken()`, `validate()`; JSON responses. |
| Middleware | `HandleCors`, `AuthenticateToken` | CORS (preflight short-circuit, config-driven); bearer-token auth against `API_TOKENS` (fail closed, `hash_equals`). |
| Routing | `Router`, `Route` (value object + static facade), `Pipeline` | Verbs, `{param}`/`{param?}`, `where()`, names + URL generation, groups (prefix, middleware), `apiResource()`, 404 vs 405, HEAD→GET, controller dispatch with param injection, return-value → Response conversion. |
| Database | `Connection`, `QueryBuilder`, `Model` | PDO (sqlite/mysql/pgsql), safe query builder (bound values, validated identifiers/operators, no unscoped update/delete), `paginate()`, active-record-lite models with `$fillable`/`$hidden`, `JsonSerializable`. |
| Validation | `Validator`, `ValidationException` | Pipe/array rules incl. `unique`/`exists`; 422 JSON on failure. |
| Errors | `Exceptions\Handler` | Every error rendered as JSON; debug detail only when `APP_DEBUG=true`; logs ≥500 to `storage/logs/kanin.log`. |
| CLI | `Console\Kanin` + stubs | `serve`, `routes`, `make:controller [--api]`, `make:model`, `make:middleware`, `db:seed`, `help`. |

Exact signatures: see the Interface Contract in the implementation plan.

## 5. Request lifecycle

1. Web server sends non-file requests to `public/index.php` (Apache `.htaccess` or `kanin serve`).
2. `index.php` loads `vendor/autoload.php`, builds `Application(project root)`, calls `run()`.
3. Boot: `.env` → `config/*` → timezone → service singletons → `routes/api.php` inside a `prefix => 'api'` group.
4. `Request::capture()` → global middleware (`HandleCors`) → route middleware (e.g. `auth.token`) → controller → return value converted to `Response`.
5. Any `Throwable` → `Handler::report` + `Handler::render` → JSON `Response`.
6. `Response::send()`.

## 6. Security requirements

- No SQL value concatenation; identifiers validated + quoted; operator and direction allowlists; unscoped `update()`/`delete()` refused.
- Model mass assignment limited to `$fillable`; `$hidden` fields never serialized.
- Token auth: tokens from `API_TOKENS` (comma-separated); empty list denies everything; comparison via `hash_equals`; 401 carries `WWW-Authenticate: Bearer`.
- CORS defaults: `allowed_origins` from `CORS_ALLOWED_ORIGINS` (default `*`), credentials off.
- Production (`APP_DEBUG=false`) 500 responses contain only `{"message":"Server Error"}`.
- Docroot is `public/`; root `.htaccess` denies everything else.
- Malformed JSON request bodies → 400 `{"message":"Malformed JSON body."}`.

## 7. Demo application

- `GET /api` → `{"name":"KaninPHP","version":"1.0.0"}`.
- `Route::apiResource('users', UserController::class)` → index (paginated), store, show, update, destroy, JSON throughout. Write routes (`store`, `update`, `destroy`) are behind `auth.token`.
- Validation: `name` required|string|max:100; `email` required|email|unique:users,email (ignore self on update; `sometimes` semantics handled by building the rules per action).
- `php kanin db:seed` creates `database/database.sqlite` and runs `database/seed.sql` (users table + 3 rows).

## 8. Testing

PHPUnit 12 with Unit + Feature suites; SQLite `:memory:`.

Unit: Container · Env · Config · Request (method override, JSON body, malformed JSON, bearer token, input helpers) · Response/JsonResponse · Route matching · Router (params, optional, where, names/url, groups, apiResource, 404, 405, HEAD, response conversion) · Pipeline order · HandleCors (preflight, simple request, disallowed origin) · AuthenticateToken (missing, wrong, right, empty list) · QueryBuilder (SQL + bindings per clause, rejects bad identifiers/operators/direction, refuses unscoped update/delete, paginate, real SQLite queries) · Model (table naming, find/create/save/delete/fillable/hidden/toArray/JSON) · Validator (each rule pass/fail, unique ignore) · Handler (debug vs production JSON, 404, 405, 422, logging) · Console (generators create/refuse overwrite, bad names, routes table, unknown command).

Feature: full `Application::handle()` for `GET /api`, users CRUD happy path with token, 401 without token, 422 validation, 404 unknown user, 404 unknown route, CORS preflight.

Smoke: `php kanin serve` + `curl` for `/api` and `/api/users` → 200 JSON.

## 9. Documentation

`README.md` rewritten for the public release: badges-free intro, requirements, installation, quick start, directory structure, configuration/.env, routing, controllers, requests & validation, responses, middleware (CORS, token auth, custom), database (connection, query builder, pagination), models, error handling, CLI reference, testing, deployment (Apache/Nginx docroot, `APP_DEBUG=false`), security notes, contributing, license. `CHANGELOG.md` with the 1.0.0 entry.

## 10. Risks

- PHP 8.5 installed via winget at a non-PATH location until the shell restarts — tasks call it by absolute path or via `PATH` export.
- Built-in server on Windows — `kanin serve` passes `public/index.php` as router; covered by smoke test.
