# Upgrade guide

## 3.2.x → 3.3.0

This release is backwards compatible: nothing below stops an existing application from
booting. The items marked **Action** need attention anyway, either because the old
behaviour was unsafe or because a compatibility shim is scheduled for removal in 4.0.

Run `composer update codectrl-sro/apollo` and work through the list.

---

### 1. Encryption format changed (`CryptHelper`)

New payloads use AES-256-GCM. Old AES-256-ECB payloads remain readable, so nothing
breaks on upgrade — but they are unauthenticated, and the sooner they are rewritten the
better.

**Action — migrate stored ciphertext at your convenience:**

```php
use CodeCTRL\Apollo\Utility\Helper\CryptHelper;

foreach ($repository->findAll() as $row) {
    if (CryptHelper::isLegacyPayload($row->getSecret())) {
        $row->setSecret(CryptHelper::encrypt(CryptHelper::decrypt($row->getSecret())));
    }
}
$em->flush();
```

**Action — if you set `CRYPT_ALGO`:** it now only accepts an authenticated cipher
(`-gcm` or `-ccm`). A value like `aes-256-cbc` throws on `encrypt()`. Unset it to use the
default, `aes-256-gcm`. Legacy decryption still honours whatever it says.

**Recommended — regenerate `CRYPT_SECRET` as a proper key.** A plain string still works
(it is stretched with SHA-256), but the supported format is now 32 bytes, base64 encoded:

```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32)), PHP_EOL;"
```

Note that changing the secret makes existing ciphertext unreadable — migrate first,
re-key second, or keep the old secret around to decrypt with.

---

### 2. CSRF field renamed `token` → `_csrf`

The form emitted `token`, verification read `_csrf`, and so `CSRF::formValidate()` never
matched. Both names are now emitted and both are accepted.

**Action — move templates and controllers to `_csrf` before 4.0.** In Twig, the new
helper does it for you:

```twig
<form method="post" action="{{ getBasepath('/login') }}">
    {{ csrf_field('login') }}
    ...
</form>
```

Controllers reading the token by hand should use `CSRF::tokenFrom($request->getParsedBody())`,
which understands both names.

**Note:** if your application relied on `CSRF::generateToken($id)` returning `null` on
first call — it now returns a real token, which is the point.

---

### 3. Authentication strategies no longer all run

`Helper::getSessionUser()` had a `switch` without `break`, so a `Session` configured
application also evaluated the JWT and Cookie branches, and a present `auth_token`
cookie overrode the session user.

**Action — check `routing.auth_method` is set to what you actually use.** If your
application depended, knowingly or not, on more than one strategy being consulted, it
will now see only the configured one. If you genuinely need several, call the relevant
resolver explicitly.

The result is also memoised per request. After a login or logout that changes who is
acting, call:

```php
$helper->forgetSessionUser();
```

---

### 4. Permission middlewares take a `Helper`

`PermissionMiddleware` and `PermissionGroupMiddleware` accept a `Helper` as their third
argument and resolve the user when the route runs. Passing an already resolved user
still works but is deprecated, and keeps the old cost: a database query per guarded
route on every request.

```php
// before
new PermissionMiddleware($options, $config, $helper->getSessionUser(), $em);
// after
new PermissionMiddleware($options, $config, $helper, $em);
```

If you use the framework's `RouteValidator`, this is already handled.

---

### 5. `Html::response()` deprecated

It sent only the headers and returned the body for the caller to echo. It now emits the
whole response and returns an empty string, so `echo Html::response($response);` keeps
working unchanged.

**Action — switch the front controller to:**

```php
CodeCTRL\Apollo\UI\Html\Html::emit($app->run());
```

If you captured the return value as a stream (`$body = Html::response($r)` followed by
`$body->getContents()`), that no longer works — use `$response->getBody()` directly.

---

### 6. Error pages hide details outside debug mode

`HtmlStrategy` used to pass the exception message and full stack trace to
`errors.html.twig` regardless of environment. They are now only present when
`route.debug` is true.

**Action — if your `errors.html.twig` prints `block.message` or `block.trace`, expect
them to be empty in production.** That is the intended behaviour. Set `route.debug` in
your development config, and install the pretty error page:

```bash
composer require --dev filp/whoops
```

With debug on and Whoops installed, uncaught throwables render an interactive page with
source context instead of the Twig template.

---

### 7. Composer requirements narrowed

These moved from `require` to `suggest` because `src/` never referenced them:

| Package | If your application uses it |
| --- | --- |
| `cherif/inertia-psr15` | `composer require cherif/inertia-psr15` |
| `php-curl-class/php-curl-class` | `composer require php-curl-class/php-curl-class` |
| `doctrine/cache` | `composer require doctrine/cache` (or move to PSR-6) |
| `fullpipe/twig-webpack-extension` | `composer require fullpipe/twig-webpack-extension` |
| `beberlei/doctrineextensions` | `composer require beberlei/doctrineextensions` |

The same applies to the extensions `ext-redis`, `ext-gd`, `ext-exif`, `ext-soap` and
`ext-simplexml`.

**Action — run `composer update` and check for a missing class at boot.** The most likely
one is `beberlei/doctrineextensions`, referenced from the doctrine `functions` config
dimension, and `fullpipe/twig-webpack-extension` from the twig `extensions` dimension.

`firebase/php-jwt` was raised to `^7.1` (closing CVE-2025-45769). `JWT::encode()` and
`JWT::decode()` keep the signatures Apollo uses; if you call php-jwt directly, review
[its 7.0 release notes](https://github.com/firebase/php-jwt/releases).

---

### 8. Image uploads are bounded

`ImageHelper::uploadFile()` no longer sets `memory_limit` to `-1`, and rejects images
over a pixel budget before decoding them.

**Action — set these if your application handles large images:**

```dotenv
IMAGE_MEMORY_LIMIT=512M
IMAGE_MAX_PIXELS=40000000
IMAGE_JPEG_QUALITY=98
```

An oversized upload now throws a `RuntimeException` rather than exhausting the worker,
so wrap the call if you were relying on it always succeeding.

---

### 9. Session cookie hardening is applied at boot

`Apollo::run()` now calls `SessionGuard::harden()` before anything starts a session:
`SameSite=Lax`, `HttpOnly`, `Secure` when HTTPS is detected, and strict session ids.

**Action — if you are behind a TLS terminating proxy**, make sure it sets
`X-Forwarded-Proto`, or set `secure` explicitly:

```php
'session' => [
    'samesite' => 'Lax',
    'secure'   => true,
],
```

To keep your php.ini settings instead: `'session' => ['harden' => false]`.

If your application posts to a third-party site that then redirects back with a cookie
expected to survive, `SameSite=Lax` may drop it — use `'samesite' => 'None'` (which
forces `Secure`) for that case.

---

## New in 3.3.0 you may want to adopt

None of this is required.

**Middleware groups** instead of repeating the same keys on every route:

```php
'middleware' => [
    'aliases' => ['audit' => \App\Http\AuditMiddleware::class],
    'groups'  => [
        'web' => ['csrf', 'headers'],
        'api' => ['headers', 'throttle:60,60'],
    ],
],
```

```php
'/users' => [
    'middleware' => ['web', 'auth', 'can:users,read'],
    'methods' => ['GET' => ['callable' => [UserContainer::class, 'index']]],
],
```

**Rate limiting** on the endpoints that need it:

```php
'/login' => [
    'middleware' => ['throttle:5,300'],   // 5 attempts per 5 minutes
    'methods' => ['POST' => ['callable' => [AuthContainer::class, 'login']]],
],
```

**Typed environment access** instead of `$_ENV`:

```php
use CodeCTRL\Apollo\Core\Env;

Env::assertRequired(['CRYPT_SECRET', 'MAIL_ADDRESS']);   // during boot
$port = Env::int('MAIL_SMTP_PORT', 587);
```

**A Redis key prefix**, if several applications share one database:

```php
new RedisClient($redis, $logger, 'myapp');
```
