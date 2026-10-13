# KaninPHP v1 — Framework Design

- **Date:** 2026-10-13
- **Status:** Approved in brainstorming, pending spec review
- **Audience:** Beginner PHP developers
- **Style:** CodeIgniter simplicity + Laravel ergonomics

## 1. Goal

Turn the KaninPHP v0.0.1 skeleton (which currently fatals: `Rice` is never loaded, routing is empty) into a small, complete, readable MVC framework that a beginner can install, understand end to end, and build a CRUD app with.

Success means:

1. `composer install && php kanin serve` serves a working welcome page and a Users CRUD demo backed by SQLite with no extra setup.
2. The full PHPUnit suite passes on PHP 8.5.
3. A beginner can read any core class in one sitting (target: no core class over ~300 lines).

## 2. Constraints

- **PHP:** `^8.5` (latest stable; 8.5.10 at time of writing). Every PHP file uses `declare(strict_types=1)`.
- **Runtime dependencies:** none beyond PHP extensions `pdo`, `mbstring`, `json`. Composer is used for PSR-4 autoloading only.
- **Dev dependencies:** `phpunit/phpunit` ^12.
- **Namespaces:** framework `Kanin\` → `system/`; application `App\` → `app/`; tests `Tests\` → `tests/`.
- **License:** MIT (unchanged).
- Modern syntax (readonly, enums, `match`, promoted constructors) is used freely; newest 8.4/8.5 syntax (property hooks, pipe operator) only where it is clearer than the alternative.

## 3. Directory layout

```
KaninPHP/
├─ app/
│  ├─ Controllers/        HomeController.php, UserController.php
│  ├─ Models/             User.php
│  ├─ Middleware/         (empty, app-level middleware goes here)
│  └─ Views/              layouts/main.php, home.php, users/{index,create,edit,show}.php,
│                         errors/{404,419,500}.php
├─ config/                app.php, database.php, session.php
├─ database/              database.sqlite (gitignored), seed.sql
├─ routes/                web.php
├─ public/                index.php, .htaccess, assets/app.css
├─ system/                Kanin\ framework core (see §4)
├─ storage/               logs/, cache/   (gitignored contents, .gitkeep kept)
├─ tests/                 Unit/, Feature/, TestCase.php
├─ kanin                  CLI entry script
├─ .env.example, .gitignore, composer.json, phpunit.xml, README.md, LICENSE
```

Removed: root `index.php`, `system/Rice.php`, `system/core.php`, `system/app.php`, `system/route.php`, `system/Exception/error.html`, `system/.htaccess` (replaced by a project-root `.htaccess` that denies everything, plus `public/` as the document root).

## 4. Components (`system/`)

| Path | Class | Responsibility |
|---|---|---|
| `Application.php` | `Kanin\Application` | Extends `Container`. Holds base path, loads `.env` + `config/*`, registers core services, `handle(Request): Response`, `run()` (capture → handle → send). |
| `Container.php` | `Kanin\Container` | `bind`, `singleton`, `instance`, `make` with reflection autowiring of class-typed constructor params; static `getInstance()`. Throws `ContainerException` on unresolvable params. |
| `Support/Env.php` | `Kanin\Support\Env` | Parse `.env` (`KEY=value`, quotes, `#` comments, `true/false/null` casting). Does not overwrite existing real env vars. |
| `Support/Config.php` | `Kanin\Support\Config` | Load `config/*.php` arrays; `get('app.debug', default)` dot notation; `set`. |
| `Support/helpers.php` | functions | `app() config() env() view() redirect() back() route() url() asset() e() csrf_token() csrf_field() method_field() old() session() request() db() abort() dd()` — all guarded by `function_exists`. Autoloaded via composer `files`. |
| `Http/Method.php` | `enum Method: string` | GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS. |
| `Http/Request.php` | `Kanin\Http\Request` | Built via `capture()` from superglobals or `create(uri, method, params)` for tests. `method()` honors `_method` override on POST. `path()`, `input(key, default)`, `all()`, `only()`, `query()`, `header()`, `isJson()/wantsJson()`, `route(param)`. |
| `Http/Response.php` | `Kanin\Http\Response` | content, status, headers, `send()`. Subclasses `RedirectResponse` (`with()`, `withErrors()`, `withInput()` flash) and `JsonResponse`. |
| `Http/HttpException.php` | `Kanin\Http\HttpException` | Status code + message; `NotFoundHttpException`, `MethodNotAllowedHttpException`. |
| `Routing/Router.php` | `Kanin\Routing\Router` | Register `get/post/put/patch/delete/match/any`; `{param}` and `{param?}` segments with optional `where()` regex; `name()`; `group(['prefix','middleware'], closure)`; `dispatch(Request)`; `url(name, params)`; 404 vs 405 distinction; HEAD falls back to GET. |
| `Routing/Route.php` | `Kanin\Routing\Route` | Single route value object + static facade (`Route::get(...)` proxies to the container's Router). |
| `Routing/ControllerDispatcher` (inside Router) | — | Action is `Closure` or `[Controller::class, 'method']`. Controller built via container; method params resolved by: route param name match → `Request` type → container type → default value. Return value converted: `Response` as-is, `View`/string → HTML response, array/`JsonSerializable` → `JsonResponse`, `null` → 204. |
| `Routing/Pipeline.php` | `Kanin\Routing\Pipeline` | Onion middleware: each middleware has `handle(Request $request, Closure $next): Response`. Global middleware from `config/app.php`; route/group middleware by class name or alias. |
| `Controller.php` | `Kanin\Controller` | Optional base: `view()`, `redirect()`, `validate(Request, rules)`. |
| `View/View.php` | `Kanin\View\View` | `make(name, data)` resolves `app/Views/{dot.path}.php`; renders in isolated scope with output buffering; `extend(layout)`, `section/endSection`, `yield(name, default)`, `include(name, data)`. Implements `Stringable`. Missing view → `ViewNotFoundException`. |
| `Database/Connection.php` | `Kanin\Database\Connection` | Lazy PDO from config (sqlite, mysql, pgsql drivers); `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, emulated prepares off; `select/insert/update/delete/statement` with bindings; `transaction(Closure)`; `table(name): QueryBuilder`. |
| `Database/QueryBuilder.php` | `Kanin\Database\QueryBuilder` | `select, where (=,!=,<,>,<=,>=,like), orWhere, whereIn, whereNull, orderBy, limit, offset, get, first, find, count, exists, insert (returns id), update, delete, toSql, getBindings`. Identifiers validated with `/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/` (plus `*` and `as` alias) and quoted; operators checked against an allowlist; direction must be `asc|desc`. `update()`/`delete()` without a `where` throw. All values bound. |
| `Database/Model.php` | `Kanin\Database\Model` | Active-record lite: `$table` (default: snake plural of class), `$primaryKey='id'`, `$fillable`, `$hidden`; static `all, find, findOrFail (→404), where, create, query`; instance `fill, save (insert or update), delete, toArray`; magic `__get/__set` attributes; `JsonSerializable`. No relationships. |
| `Validation/Validator.php` | `Kanin\Validation\Validator` | Rules (pipe string or array): `required, nullable, string, email, numeric, integer, min, max, between, confirmed, in, same, alpha_num, url, unique:table,column[,ignoreId], exists:table,column`. `min/max` are length for strings, value for numerics. `validate()` returns validated data or throws `ValidationException`; default English messages, overridable. |
| `Validation/ValidationException.php` | — | Handler converts to redirect-back with errors + old input (HTML) or 422 JSON. |
| `Session/Session.php` | `Kanin\Session\Session` | Wraps native session; `get/put/has/forget/pull/flash/regenerate/invalidate/token`; flash aging (new→old→removed per request). `ArraySession` driver for tests/CLI. |
| `Session/StartSession.php` | middleware | Starts session with cookie params `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS; ages flash data after response. |
| `Security/VerifyCsrfToken.php` | middleware | For POST/PUT/PATCH/DELETE compares `_token` input or `X-CSRF-TOKEN` header to session token with `hash_equals`; mismatch → 419. `$except` path list. |
| `Exceptions/Handler.php` | `Kanin\Exceptions\Handler` | `report()` logs to `storage/logs/kanin.log`; `render()`: `HttpException` → `errors/{code}.php` view if present, else plain page; `ValidationException` as above; any other `Throwable` → debug page (class, message, file:line, trace, all escaped) when `app.debug`, else `errors/500`. Also registers `set_error_handler` to convert warnings to `ErrorException`. |
| `Console/Kanin.php` | `Kanin\Console\Kanin` | Commands: `serve [--host=127.0.0.1] [--port=8000]` (runs `php -S` with `public/` docroot), `make:controller Name [--resource]`, `make:model Name`, `make:middleware Name`, `routes` (table of method/uri/name/action), `db:seed` (runs `database/seed.sql`), `help`. Generators refuse to overwrite existing files. |

## 5. Request lifecycle

1. Web server sends every non-file request to `public/index.php` (Apache `.htaccess`, or `kanin serve` router).
2. `index.php` requires `vendor/autoload.php`, builds `Application` with the project root, calls `run()`.
3. `Application` boot: load `.env` → config → set timezone, error reporting from `app.debug` → register singletons (`Router`, `Connection`, `Session`, `Handler`, `View` factory) → require `routes/web.php`.
4. `Request::capture()` → `Pipeline` through global middleware (`StartSession`, `VerifyCsrfToken`) → route-specific middleware → `Router::dispatch` → controller → return value converted to `Response`.
5. Any `Throwable` in steps 3–4 → `Handler::report` + `Handler::render` → `Response`.
6. `Response::send()`; session saved.

## 6. Security requirements

- No SQL string concatenation of values anywhere; identifiers validated + quoted (§4 QueryBuilder).
- View data is never auto-escaped by magic; templates use `<?= e($x) ?>` and every shipped template/stub does so. `e()` = `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- CSRF on all state-changing verbs; `csrf_field()` in every shipped form.
- Session cookie flags as in §4; session id regenerated on `Session::regenerate()` (documented for login flows).
- Debug page only when `APP_DEBUG=true`; `.env.example` ships with `APP_ENV=local`, `APP_DEBUG=true`, and README warns to set false in production.
- Root `.htaccess` denies all; `public/.htaccess` routes to `index.php`. README instructs to point the docroot at `public/`.
- Model mass assignment limited to `$fillable`.
- Redirect `back()` uses the `Referer` only if same host, else `/`.

## 7. Demo application

- `/` → welcome page (version, links to docs and the demo).
- `/users` resource: index (list), create/store (validated: name required|max:100, email required|email|unique:users,email), show, edit/update (unique ignores self), destroy (form with `method_field('DELETE')`).
- SQLite at `database/database.sqlite`, created by `php kanin db:seed` from `database/seed.sql` (users table + 3 rows). README quick start includes this step.

## 8. Testing

PHPUnit 12, `phpunit.xml` with Unit + Feature suites, SQLite `:memory:` for DB tests, `ArraySession` for session.

Unit: Container (autowire, singleton, unresolvable error) · Env parsing · Config dot access · Router (static, params, optional params, `where`, names/url generation, groups, 404, 405, HEAD) · Pipeline order · Request method override and input · Response conversions · View (render, layout/sections, include, escaping via `e`, missing view) · QueryBuilder (`toSql`+bindings for each clause, rejects bad identifiers/operators/direction, refuses unscoped update/delete, real queries on SQLite) · Model (find/create/save/delete/fillable/hidden/toArray) · Validator (each rule pass+fail, `unique` ignore) · Session flash aging · CSRF (missing, wrong, right token; header; except) · Handler (debug vs production output, 404 view, validation redirect vs 422 JSON) · Console generators (create file, refuse overwrite).

Feature: full `Application::handle()` for welcome page, Users CRUD happy path, validation failure round-trip with old input, CSRF rejection 419, unknown route 404.

Smoke: `php kanin serve` + `curl` for `/` and `/users` returns 200.

## 9. Out of scope (v1)

ORM relationships, migrations framework, auth scaffolding, queues, events, cache layer, template compiler, file uploads helper, localization.

## 10. Risks

- **PHP 8.5 not yet installed locally** — install via `winget install PHP.PHP.8.5` and Composer via its official installer before implementation; all proof depends on it.
- **Native session in tests** — mitigated by `ArraySession` and never calling `session_start` under CLI tests.
- **Built-in server routing on Windows** — `kanin serve` passes a router script; covered by smoke test.
