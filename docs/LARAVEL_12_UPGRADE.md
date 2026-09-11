# Laravel 12 upgrade

Branch: upgrade/laravel-12. No merge or deployment is part of this change.

## Versions and dependencies

Laravel 10.50.3 -> 12.69.2, constrained to ^12.61.1 (Laravel 12 only).
PHP requirement remains ^8.2. Composer platform.php is 8.2.0 to keep resolution compatible with shared hosts running PHP 8.2, even when updating from a newer workstation.
Shopify SDK remains 1.0.2, explicitly pinned to avoid an unrelated SDK update. Composer validate reports this deliberate exact constraint as a general warning.

Sanctum is retained and upgraded from 3.3.3 to 4.3.3: routes/api.php exposes auth:sanctum /api/user, User uses HasApiTokens, config/sanctum.php registers its guards/middleware and the personal_access_tokens migration already exists. Removing it would remove an existing authentication path. No token table or other migration is removed or republished.

Collision becomes 8.9.5, PHPUnit 11.5.56 and Ignition 2.12.0. Existing compatible Tinker 2.11.1, Mockery 1.6.15, Faker 1.24.1, Pint 1.30.4 and Sail 1.67.0 stay at their locked versions. composer update -W resolved 45 updates and six additions without ignored requirements or advisory suppression.

The first Windows archive extraction encountered a PHPUnit file lock. composer install --prefer-source was used for recovery. This is a local installation issue, not a package compatibility bypass.

## Compatibility review

The original bootstrap/app.php, HTTP/console kernels, provider registration and exception handler are retained. This follows the [Laravel 11 upgrade guide](https://laravel.com/framework/docs/11.x/upgrade), which supports the older application structure.

Sanctum 4 names the CSRF middleware config key validate_csrf_token; it still points to the existing strict application middleware. Shopify embedded bearer authentication, origin/JSON protections, webhook HMAC, rate limit factories, trusted proxies and SameSite configuration remain unchanged. A regression verifies the existing Sanctum token route and hidden fields.

The [Laravel 12 upgrade guide](https://laravel.com/framework/docs/12.x/upgrade) requires Carbon 3. The app uses explicit date parsing/comparisons, not changed diffIn* rounding or default createFromTimestamp behavior. UUIDv7, new Concurrency result mapping, SVG validation and optional class dependency changes do not affect these code paths. The local filesystem root is already explicitly configured. No custom authentication contract, doctrine schema access, float/double schema declaration or column change operation needs adaptation.

Database queues, transaction boundaries, row/cache locks, scheduler registration, encrypted casts and model structure remain in place. Existing migrations are unchanged; this upgrade adds no migration. A synthetic fixture captured with Laravel 10.50.3 tests decryption of Shopify token arrays, EmailLog subject/body and privacy-export arrays without using an environment key.

The Postmark transport still extends Symfony AbstractTransport, uses SentMessage and returns the provider message ID. No transport or sender behavior is changed. Live/test mail still requires the exact verified merchant sender, per-shop ownership and identity snapshots. There is no application-sender fallback. Quota reservations, releases, uncertain delivery consumption and no-retry behavior remain covered by the existing suite.

PHPUnit metadata for the dashboard data provider moves from a docblock to a PHP attribute. The MySQL engine unit test now supplies the connection to Blueprint's constructor and calls toSql without arguments, matching the new framework API. Its InnoDB and charset assertions and all dashboard scenarios are unchanged.

## Validation

- Normal suite: **241 passed, 0 failed, 2 skipped; 1,321 assertions**. The two MySQL-specific tests run in the dedicated MySQL suite.
- MySQL suite: **243 passed, 0 failed, 0 skipped; 1,335 assertions**, including concurrent quota reservation and interrupted-migration recovery.
- Laravel Pint: passed.
- composer audit --no-dev: no security vulnerability advisories found; no advisory suppression.
- composer install --prefer-source --no-interaction: passed after the Windows extraction lock described above. Package discovery and the post-update asset script succeeded (no publishable Laravel assets).
- composer validate --no-check-publish: passed with the deliberate exact Shopify SDK constraint warning.
- composer check-platform-reqs --no-dev: passed on PHP 8.2.12.
- optimize:clear and about environment/cache/driver inspection: passed. Scheduler listing succeeded.
- config:cache, route:cache and view:cache: all passed. Config and route checks used temporary cache paths, which were removed; compiled views were cleared afterward.
- No migrations, production environment files, application keys or business-logic files changed. Production deployment and live-provider validation were not performed.

## Files changed

- composer.json and composer.lock: framework/dependency upgrade and PHP 8.2 resolution target.
- config/sanctum.php: Sanctum 4 middleware key compatibility.
- tests/Feature/DashboardAutomationStatusTest.php: PHPUnit attribute compatibility.
- tests/Unit/MySqlEngineTest.php: Blueprint API compatibility.
- tests/Feature/LaravelUpgradeCompatibilityTest.php and tests/Fixtures/laravel10-encryption.json: synthetic Laravel 10 encrypted-data and existing Sanctum API regressions.
- README.md and docs/LARAVEL_12_UPGRADE.md: versions, audit status, package changes and deployment guidance.

## Later production deployment (not executed)

Do not deploy until this branch has been reviewed and separately approved. Back up the database and existing APP_KEY, pause scheduler/queue cron, and allow in-flight workers to finish. Use a PHP 8.2+ CLI matching the web runtime. Deploy the reviewed release files outside public_html; preserve the real .env and all data.

Run from the production application directory, stopping on any failure:

    php artisan down
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    composer check-platform-reqs --no-dev
    composer audit --no-dev
    php artisan config:clear
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan chargeguard:health-check
    php artisan queue:restart
    php artisan up

Resume existing cron only after successful checks. Preserve APP_KEY, encrypted records, delivery claims, sender proofs and quota periods. Do not run key:generate, migrate:fresh, refresh/reset or production seeders. Do not clear cache locks while workers are active. Keep global customer safety mode enabled and billing disabled during controlled validation; deployment does not enable automation.

The upgrade changes no schema. If the previously reported interrupted quota migration has not already been repaired, follow the existing chargeguard:repair-quota-migration procedure first; do not treat an interrupted older migration as a Laravel 12 schema change.

## Complete lock-file package changes

| Package | Laravel 10 lock | Laravel 12 lock |
| --- | --- | --- |
| brick/math | 0.12.3 | 0.14.8 |
| carbonphp/carbon-doctrine-types | 2.1.0 | 3.2.1 |
| laravel/framework | v10.50.3 | v12.69.2 |
| laravel/prompts | v0.1.25 | v0.3.24 |
| laravel/sanctum | v3.3.3 | v4.3.3 |
| laravel/serializable-closure | v1.3.7 | v2.0.16 |
| league/commonmark | 2.10.0 | 2.10.1 |
| league/uri | Added | 7.8.1 |
| league/uri-interfaces | Added | 7.8.1 |
| monolog/monolog | 3.11.0 | 3.12.0 |
| nesbot/carbon | 2.73.0 | 3.13.2 |
| nunomaduro/collision | v7.12.0 | v8.9.5 |
| nunomaduro/termwind | v1.17.0 | v2.4.0 |
| phpunit/php-code-coverage | 10.1.16 | 11.0.12 |
| phpunit/php-file-iterator | 4.1.0 | 5.1.1 |
| phpunit/php-invoker | 4.0.0 | 5.0.1 |
| phpunit/php-text-template | 3.0.1 | 4.0.1 |
| phpunit/php-timer | 6.0.0 | 7.0.1 |
| phpunit/phpunit | 10.5.64 | 11.5.56 |
| sebastian/cli-parser | 2.0.1 | 3.0.2 |
| sebastian/code-unit | 2.0.0 | 3.0.3 |
| sebastian/code-unit-reverse-lookup | 3.0.0 | 4.0.1 |
| sebastian/comparator | 5.0.5 | 6.3.3 |
| sebastian/complexity | 3.2.0 | 4.0.1 |
| sebastian/diff | 5.1.1 | 6.0.2 |
| sebastian/environment | 6.1.0 | 7.2.1 |
| sebastian/exporter | 5.1.4 | 6.3.2 |
| sebastian/global-state | 6.0.2 | 7.0.2 |
| sebastian/lines-of-code | 2.0.2 | 3.0.1 |
| sebastian/object-enumerator | 5.0.0 | 6.0.1 |
| sebastian/object-reflector | 3.0.0 | 4.0.1 |
| sebastian/recursion-context | 5.0.2 | 6.0.3 |
| sebastian/type | 4.0.0 | 5.1.3 |
| sebastian/version | 4.0.1 | 5.0.2 |
| spatie/laravel-ignition | 2.9.1 | 2.12.0 |
| staabm/side-effects-detector | Added | 1.0.5 |
| symfony/clock | Added | v7.4.8 |
| symfony/console | v6.4.45 | v7.4.18 |
| symfony/error-handler | v6.4.44 | v7.4.17 |
| symfony/finder | v6.4.44 | v7.4.17 |
| symfony/http-foundation | v6.4.45 | v7.4.18 |
| symfony/http-kernel | v6.4.45 | v7.4.18 |
| symfony/mailer | v6.4.44 | v7.4.17 |
| symfony/mime | v6.4.45 | v7.4.18 |
| symfony/polyfill-php84 | Added | v1.38.1 |
| symfony/polyfill-php85 | Added | v1.41.0 |
| symfony/process | v6.4.45 | v7.4.18 |
| symfony/routing | v6.4.45 | v7.4.18 |
| symfony/translation | v6.4.44 | v7.4.17 |
| symfony/uid | v6.4.32 | v7.4.17 |
| symfony/var-dumper | v6.4.45 | v7.4.18 |
