# Changelog

All notable changes to KaninPHP are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-13

First public release. KaninPHP is rewritten from the v0.0.1 skeleton into a small,
API-first JSON framework. Nothing from v0.0.1 is kept; there is no upgrade path from it.

### Added

- Application core: `Kanin\Application` boots `.env`, `config/*.php`, the timezone,
  services and `routes/api.php` (served under `/api`), and turns every request into a
  response.
- Dependency injection container with autowiring and `call()` parameter injection.
- `.env` loading (`Kanin\Support\Env`) and dot-notation configuration (`Kanin\Support\Config`).
- HTTP layer: `Request` (JSON bodies, method override, `bearerToken()`, `validate()`),
  `Response`, `JsonResponse`, `HttpException`, `NotFoundHttpException`,
  `MethodNotAllowedHttpException` and the `Method` enum.
- Routing: verb methods, `{param}` and `{param?}` parameters, `where()` constraints,
  named routes with URL generation, groups (prefix and middleware), `apiResource()`,
  HEAD-to-GET matching, 404 versus 405 with an `Allow` header, and automatic conversion
  of return values to responses.
- Middleware pipeline with the `HandleCors` global middleware and the `auth.token`
  bearer-token middleware (`AuthenticateToken`).
- Database: PDO `Connection` for SQLite, MySQL and PostgreSQL, a safe `QueryBuilder`
  with `paginate()`, and active-record-style `Model` classes with `$fillable` and `$hidden`.
- Validation with pipe or array rules (including `unique` and `exists`) and 422 JSON
  error responses.
- JSON error handling for every exception, with debug details only when `APP_DEBUG=true`
  and logging of server errors to `storage/logs/kanin.log`.
- Global helpers: `app`, `config`, `env`, `response`, `route`, `url`, `request`, `db`,
  `abort` and `dd`.
- `kanin` command-line tool: `help`, `serve`, `routes`, `make:controller [--api]`,
  `make:model`, `make:middleware` and `db:seed`.
- Demo application: `GET /api` welcome endpoint and a `/api/users` REST resource with
  token-protected writes, backed by SQLite.
- PHPUnit 12 test suite (Unit and Feature) and a full README.

### Changed

- Requires PHP 8.5 or newer (was PHP 7.2).
- The web server document root is now `public/`; a root `.htaccess` denies everything else.

### Removed

- The v0.0.1 files: root `index.php`, `system/Rice.php`, `system/core.php`,
  `system/app.php`, `system/route.php`, `system/Exception/error.html` and `system/.htaccess`.

### Security

- Query values are always bound; identifiers, operators and sort directions are
  validated; `update()` and `delete()` without a `where` clause are refused.
- Mass assignment is limited to `$fillable`; `$hidden` attributes are never serialized.
- Token authentication fails closed (an empty `API_TOKENS` denies everything), compares
  with `hash_equals` and answers 401 with `WWW-Authenticate: Bearer`.
- Production 500 responses contain only `{"message":"Server Error"}`.
- Malformed JSON request bodies are rejected with 400.
