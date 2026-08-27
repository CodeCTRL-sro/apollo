# Changelog

All notable changes to Apollo are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries before 3.3.0 were not kept; see the git history for those releases.

---

## [3.3.1] — 2026-08-27

### Changed

- **`firebase/php-jwt` widened back to `^6.11 || ^7.1`.** 3.3.0 pinned `^7.1`, which made
  Apollo uninstallable next to `kreait/firebase-php`: the kreait releases that accept
  php-jwt 7 (`7.24.1`, `8.2+`) require `psr/cache ^2|^3`, while Apollo's
  `doctrine/doctrine-module ^6.3` pulls `laminas-cache 3.x`, which requires
  `psr/cache ^1.0` — an unresolvable set. Composer still picks 7.x wherever nothing else
  holds it back, so this is not a downgrade for most consumers; only projects with a
  6.x-bound dependency fall back, and there they trade CVE-2025-45769
  (GHSA-2x45-7fc3-mxwq, severity low, `<7.0.0`) for being installable at all. Apollo
  itself only calls `JWT::encode()`, `JWT::decode()` and `Key`, whose signatures are
  identical across 6 and 7.

---

## [3.3.0] — 2026-08-27

Backwards compatible with 3.2.x. Every behavioural change below either keeps the old
path working or is opt-in; the ones worth knowing about before upgrading are collected
in [UPGRADE.md](UPGRADE.md).

Tested on PHP 8.3 and 8.4.

### Security

- **`CryptHelper` now uses AES-256-GCM instead of AES-256-ECB.** ECB has no IV, so equal
  plaintexts produced equal ciphertexts, and no authentication tag, so stored ciphertext
  could be altered undetectably. New payloads carry a random nonce and a tag. Payloads
  written by earlier releases are still readable — `decrypt()` detects them — and
  `CryptHelper::isLegacyPayload()` exists so stored values can be migrated. `CRYPT_ALGO`
  may still override the cipher, but only with another AEAD mode.
- **CSRF verification actually works.** The hidden input was named `token` while
  `formValidate()` read `_csrf`, so the two never matched. The field is now `_csrf`, with
  `token` still emitted and accepted as a compatibility shim. Also fixed: the token is
  created on first use rather than only when `$regenerate` was true (previously the first
  call returned `null` and raised a notice), comparison uses `hash_equals()`, and the
  failure message no longer reflects the submitted token back into the response.
- **New `CsrfMiddleware`**, so protection can be applied to a whole middleware group
  rather than remembered per form. Reads the token from the body, the query, or an
  `X-CSRF-Token` header.
- **New Twig functions `csrf_field()` and `csrf_token()`.**
- **`Helper::getSessionUser()` no longer falls through its `switch`.** With
  `Auth::Session` configured, the JWT and Cookie branches also ran; an `auth_token`
  cookie could therefore override the user resolved from the session. Exactly one
  strategy runs now.
- **`StringUtils::generateRandomString()` uses `random_int()`** rather than `rand()`.
  The returned length and alphabet are unchanged. Added `StringUtils::secureToken()`
  for new code.
- **New `SecurityHeadersMiddleware`** — `X-Content-Type-Options`, `Referrer-Policy` and
  `X-Frame-Options` by default; CSP, HSTS and `Permissions-Policy` opt-in. Never
  overwrites a header the application already set. HSTS is skipped over plain HTTP.
- **New `SessionGuard`**, applied during boot: `SameSite`, `HttpOnly`, `Secure`
  (auto-detected), `session.use_strict_mode` and `use_only_cookies`. Opt out with
  `'session' => ['harden' => false]`.
- **New `ThrottleMiddleware`** — Redis-backed fixed window rate limiting, with
  `Retry-After` and `X-RateLimit-*` headers via the new `RateLimitExceededException`.
- **New `RemoteAddressHelper::fromOptions()`**, so trusted proxies can be configured
  from `routing.trusted_proxies` instead of the helper going unused.
- **`HtmlStrategy` no longer writes the exception message and stack trace into the error
  template outside debug mode.** Whether a live site exposed internals previously came
  down to what the template happened to print.
- **`firebase/php-jwt` raised to `^7.1`**, closing advisory CVE-2025-45769
  (GHSA-2x45-7fc3-mxwq) which affected all versions below 7.0.

### Fixed

- **Both HTTP strategies catch `Throwable`, not `Exception`.** `TypeError`, `ValueError`
  and every other `Error` bypassed the handler entirely and surfaced from the shutdown
  function, long after a proper response could still be produced.
- **HTTP exception headers are no longer dropped**; `getHeaders()` is applied to the
  response in both strategies.
- **`HtmlStrategy` decoded its error payload with `strtok("\n")` and no first argument**,
  which continues a previous tokenisation rather than starting one. Middleware field
  errors now decode correctly.
- **`Config::set()` could not create intermediate levels.** Setting a path whose parents
  did not exist raised `array_key_exists(): Argument #2 must be of type array, null
  given`.
- **`ApolloKernel::_fatal_handler()`** rewritten: it no longer appends the rendered
  template to a body that already held the message, guards `ob_end_clean()`, and emits
  through the response emitter.
- **`ErrorLogger::myShutdownFunction()`** no longer reads `$error['type']` without
  checking that `error_get_last()` returned anything. Deprecated — it was never
  registered anywhere.
- **`Twig::render()` rethrows in debug mode** instead of silently returning an empty
  string, which turned a broken template into a blank page with nothing to explain it.
- **`AuthMiddleware`** passes `''` rather than `null` to `setcookie()`, deprecated
  since PHP 8.1.
- **`CryptHelper::decrypt()`** handles an empty-string payload.

### Performance

- **Permission middlewares resolve the user when the route runs, not when routes are
  registered.** `RouteValidator` called `getSessionUser()` at registration time, so every
  permission-guarded route queried the database on every request — including requests to
  entirely different URLs. `Helper::getSessionUser()` also memoises per request; use
  `Helper::forgetSessionUser()` after a login or logout.
- **`RedisClient::clearByPattern()` uses `SCAN` and `UNLINK` instead of `KEYS` and
  `DEL`.** `KEYS` walks the whole keyspace in one blocking pass, stalling every other
  client on the server.
- **`ImageHelper::uploadFile()` decodes and encodes once.** It used to write the image
  with Intervention, read it back with GD to apply the EXIF rotation, write it again,
  then read it a third time to scale. Intervention's `orient()` does it in one pass — and
  covers the mirrored orientations (2, 4, 5, 7) the old switch ignored.
- **`Html::emit()`** uses `laminas/laminas-httphandlerrunner`'s `SapiStreamEmitter`,
  which has been a dependency all along without being used. Streams the body, sends
  `Set-Cookie` as repeated headers instead of folding them into one comma separated
  line, and honours `Content-Range`.

### Added

- **`CodeCTRL\Apollo\Core\Env`** — typed, validated environment access with a fail-fast
  message naming the missing key, plus `Env::assertRequired()` for boot-time checks and
  a test seam. `.env.example` documents every variable the framework reads.
- **Middleware aliases and groups** (`MiddlewareResolver`). Routes can now say
  `'middleware' => ['web', 'auth', 'can:users,read']`; applications register their own
  under `middleware.aliases` and compose them under `middleware.groups`, without editing
  the framework. The previous single-class `middleware` key still works.
- **`filp/whoops` integration** through `Utility\Debug\ErrorRenderer` — an interactive
  error page with source context in debug mode, falling back to `errors.html.twig` when
  the package is absent (it is a dev dependency).
- **`RedisClient` key prefix** (`setPrefix()`), so several applications can share a Redis
  database without `clearByPattern()` reaching into a neighbour's keys. Empty by default.
- **`RedisClient::expire()`.**
- **A test suite**: PHPUnit 11 with 111 tests over `Config`, `ArrayUtils`,
  `RedirectGuard`, `CSRF`, `CryptHelper`, `Env`, `StringUtils`, `PasswordHelper`,
  `Router::mergePaths`, `SecurityHeadersMiddleware` and `MiddlewareResolver`.
- **GitHub Actions CI** — PHPStan and PHPUnit on PHP 8.3 and 8.4 (blocking), coding
  standard and Rector (advisory), and a `composer audit` job.
- **`phpstan-baseline.neon`.** The previous config carried blanket ignore patterns such
  as `'#PHPDoc tag @var#'`, which silenced whole categories of error including ones not
  yet written — and PHPStan reported most of them as never matching anything. Current
  findings are frozen in the baseline; new ones fail the build.
- **`.php-cs-fixer.dist.php` and `rector.php`** — configured but deliberately not applied
  as a sweep, so this release's diff stays reviewable. `composer cs` / `composer rector`
  show what is pending. The one exception is Rector's `ExplicitNullableParamTypeRector`,
  run on its own for the PHP 8.4 fix described under Changed.

### Changed

- **Nullable parameter types are now explicit, for PHP 8.4.** PHP 8.4 deprecates the
  implicit `Type $x = null`; 24 files under `src/` now declare `?Type $x = null`. The
  parameters already accepted null, so behaviour and the set of accepted arguments are
  unchanged — nothing that called these methods needs editing.

  It does touch public signatures, so it is worth knowing where: the constructors of
  `Auth`, `Helper`, `ApolloContainer`, `Router`, `ServiceProvider`, `Language`,
  `PdoFactory`, `RedisClient`, `RedisFactory`, `RouteValidator`, `APIResponseBuilder`,
  every middleware in `Http\Middlewares`, both HTTP strategies, plus
  `ArrayUtils::filter_recursive()`, `Config`, `ServiceManager`, and the `FormRow` /
  `FormPlainText` view helpers.

  If your application subclasses one of these and overrides such a method, PHP will not
  reject the override — an implicitly nullable parameter is the same type — but the
  subclass will emit its own 8.4 deprecation until you add the `?` there too.

- **Line endings are normalised to LF** through a new `.gitattributes`
  (`* text=auto eol=lf`). A Windows checkout previously produced CRLF working copies
  that disagreed with CI about file contents; that is what broke static analysis on
  Linux while it passed locally. Expect your next checkout or `git pull` to rewrite the
  line endings of tracked text files.

- **PHPStan analyses against a pinned PHP range** (`phpVersion: min 80300, max 80400`)
  rather than whatever PHP runs it, matching the `>=8.3` requirement. Without it the
  results differed per runner — 8.4 reported 45 errors that 8.3 did not — and a single
  baseline could not satisfy both.

- `composer.json`: `cherif/inertia-psr15`, `php-curl-class/php-curl-class`,
  `doctrine/cache`, `fullpipe/twig-webpack-extension` and `beberlei/doctrineextensions`
  moved from `require` to `suggest` — none of them is referenced from `src/`, and a
  library should not choose them for its consumers. `ext-redis`, `ext-gd`, `ext-exif`,
  `ext-soap` and `ext-simplexml` moved to `suggest` for the same reason; a JSON-only
  application could not be installed without them before.
- `gedmo/doctrine-extensions` unpinned from the exact version `3.19` to `^3.19`, so
  patch releases can be picked up.
- `Html::response()` is deprecated in favour of `Html::emit()`. It still sends the
  response, but returns an empty string rather than the body stream.

### Deprecated

Removed in 4.0:

- `CSRF::LEGACY_FIELD` and the second hidden `token` input.
- Passing a resolved user (rather than a `Helper`) to `PermissionMiddleware` and
  `PermissionGroupMiddleware`.
- `Html::response()` — use `Html::emit()`.
- `Helper::getDefaultUrl()` — always returned an empty string and has no callers.
- `ErrorLogger::myShutdownFunction()` — never registered.
- `ImageHelper::exifRotationCheck()` — `uploadFile()` uses Intervention's `orient()`.

### Known limitations

- **`symfony/cache` stays at `^5.4`**, although 5.4 LTS reached end of life. It cannot be
  raised while `doctrine/doctrine-module ^6.3` is required: that pulls
  `laminas/laminas-cache 3.x`, which requires `psr/cache ^1.0`, while `symfony/cache 6+`
  requires `psr/cache ^2|^3`. Resolving it means either waiting for a doctrine-module
  release that accepts `laminas-cache ^4`, or dropping `doctrine/doctrine-module` from
  `require` — which would break any application using `DoctrineModule\Form\Element\*`.
