# Apollo

A PSR-7 / PSR-15 web framework for PHP 8.3+, built on League Route and League Container,
with Doctrine ORM, Twig and Laminas Form.

- **Documentation:** https://deepwiki.com/CodeCTRL-sro/apollo
- **Upgrading:** [UPGRADE.md](UPGRADE.md)
- **Changes:** [CHANGELOG.md](CHANGELOG.md)

---

## Quickstart

### 1. Install

```bash
composer require codectrl-sro/apollo
```

Some capabilities are optional and declared as `suggest` rather than `require`, so a
JSON-only service is not forced to install an image stack. Add what you use:

```bash
composer require beberlei/doctrineextensions        # extra DQL functions
composer require fullpipe/twig-webpack-extension    # webpack asset helpers in Twig
composer require --dev filp/whoops                  # interactive error page in debug
```

`ext-redis`, `ext-gd`, `ext-imagick`, `ext-exif`, `ext-soap` and `ext-simplexml` are
likewise optional — see the `suggest` block in `composer.json`.

### 2. Lay out the project

```
├── config/           # PHP files returning arrays; every one is loaded and merged
│   ├── route.php
│   ├── db.php
│   └── translations/
├── modules/          # your application modules
├── logs/             # written by the logger
├── public/
│   └── index.php
└── .env
```

### 3. Front controller

```php
<?php
// public/index.php

require __DIR__ . '/../vendor/autoload.php';

use CodeCTRL\Apollo\Core\Apollo;
use CodeCTRL\Apollo\Core\Env;
use CodeCTRL\Apollo\UI\Html\Html;

$app = new Apollo();
$app->setBaseDir(dirname(__DIR__));
$app->setHomeDir(dirname(__DIR__));

if (Env::bool('APP_DEBUG', false)) {
    $app->allowErrorReporting();
}

// Fail at boot with a message naming the key, rather than deep inside a library later.
Env::assertRequired(['CRYPT_SECRET']);

Html::emit($app->run());
```

### 4. Minimal route config

```php
<?php
// config/route.php

use App\Modules\Home\HomeContainer;
use CodeCTRL\Apollo\Http\Strategies\HtmlStrategy;

return [
    'route' => [
        'debug'   => (bool)($_ENV['APP_DEBUG'] ?? false),
        'modules' => [],
        'session' => ['samesite' => 'Lax'],
    ],
    'routing' => [
        'basepath' => '/',
        'strategy' => HtmlStrategy::class,
        'paths'    => [
            '/' => [
                'methods' => [
                    'GET' => ['callable' => [HomeContainer::class, 'index'], 'name' => 'home'],
                ],
            ],
        ],
    ],
    'middleware' => [
        'groups' => [
            'web' => ['csrf', 'headers'],
            'api' => ['headers', 'throttle:60,60'],
        ],
    ],
];
```

### 5. Environment

Copy [`.env.example`](.env.example) to `.env` and fill it in. Read values through `Env`,
never `$_ENV` directly:

```php
Env::string('CRYPT_SECRET');          // required — throws when unset
Env::string('MAIL_SMTP_HOST', null);  // optional
Env::int('MAIL_SMTP_PORT', 587);
Env::bool('APP_DEBUG', false);
```

---

## Routing

Routes are nested arrays of paths. A path may carry methods, child paths, and
requirements that child paths inherit.

```php
'/admin' => [
    'require_auth' => true,
    'middleware'   => ['web'],
    'methods' => [
        'GET' => ['callable' => [AdminContainer::class, 'dashboard'], 'name' => 'admin.dashboard'],
    ],
    'paths' => [
        '/users' => [
            'middleware' => ['can:users,read'],
            'methods' => [
                'GET'  => ['callable' => [UserContainer::class, 'index'], 'name' => 'users.index'],
                'POST' => [
                    'callable'             => [UserContainer::class, 'store'],
                    'required_ContentType' => 'application/json',
                    'required_fields'      => ['email' => ['min' => 3, 'max' => 190]],
                ],
            ],
        ],
    ],
],
```

### Middleware

Reference middleware by alias, by group, or by class name. Aliases take
`alias:arg,arg` parameters.

| Alias | Class | Parameters |
| --- | --- | --- |
| `auth` | `AuthMiddleware` | `auth_method` |
| `can` | `PermissionMiddleware` | `module,right` |
| `can_group` | `PermissionGroupMiddleware` | `group` |
| `csrf` | `CsrfMiddleware` | — |
| `headers` | `SecurityHeadersMiddleware` | — |
| `throttle` | `ThrottleMiddleware` | `limit,window_seconds` |
| `fields` | `FieldsMiddleware` | — |
| `content_type` | `ContentTypeMiddleware` | `mime` |
| `required_headers` | `HeadersMiddleware` | — |

Register your own and compose groups from them:

```php
'middleware' => [
    'aliases' => ['audit' => \App\Http\AuditMiddleware::class],
    'groups'  => ['web' => ['csrf', 'headers', 'audit']],
],
```

---

## Security defaults

| Concern | Where | Default |
| --- | --- | --- |
| Session cookie | `SessionGuard`, applied at boot | `SameSite=Lax`, `HttpOnly`, `Secure` on HTTPS, strict session ids |
| Response headers | `SecurityHeadersMiddleware` | nosniff, `Referrer-Policy`, `X-Frame-Options`; CSP/HSTS opt-in |
| CSRF | `CsrfMiddleware`, `csrf_field()` | Per-form tokens, constant-time comparison |
| Encryption | `CryptHelper` | AES-256-GCM, authenticated |
| Rate limiting | `ThrottleMiddleware` | Opt-in per route, Redis-backed |
| Open redirects | `RedirectGuard` | Local paths inside the basepath only |
| Proxies | `RemoteAddressHelper::fromOptions()` | Forwarded headers only from `routing.trusted_proxies` |

Error output is bounded by `route.debug`: outside debug mode a failure renders the
status and reason phrase, and nothing else.

---

## Development

```bash
composer test         # PHPUnit
composer stan         # PHPStan (existing findings live in phpstan-baseline.neon)
composer ci           # what CI runs: stan + test

composer cs           # coding standard report — not applied to the codebase yet
composer cs:fix       # apply it (expect a large one-off diff)
composer rector       # automated refactoring report — likewise pending
```

`composer cs` and `composer rector` currently report the whole codebase. The rules are
configured, but the sweep is intentionally left as its own commit so that feature diffs
stay reviewable; CI runs both as advisory steps.

New PHPStan findings fail the build. Existing ones are frozen in `phpstan-baseline.neon`
— shrink it with `composer stan:baseline` after fixing a batch.

---

## Used packages

Doctrine ORM · League Container / Route · Monolog · Guzzle · Twig · Laminas (Form, I18n,
ServiceManager, Diactoros, HttpHandlerRunner) · Firebase PHP-JWT · PHPMailer ·
Intervention Image · Symfony Cache · Gedmo Doctrine Extensions

## License

MIT
