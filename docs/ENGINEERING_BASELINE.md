# Engineering Baseline — FinTech Hardening (Phase 0)

> **تاریخ:** 2026-11-24
> **شاخه:** `feat/hardening-phase0-9` (ساخته‌شده از `feat/fintech-audit-implementation`، کامیت `1393080`)
> **مبنای کد:** FinAegis `core-banking-prototype-laravel` نسخهٔ `v7.18.2` (کامیت `b8e521f`) + کامیت آدیت/پیاده‌سازی `1393080`
> **وضعیت:** فقط `docs/ENGINEERING_BASELINE.md` در این فاز تغییر کرده است؛ هیچ کدی ویرایش نشده و هیچ چیز پوش/کامیت نشده است.
> **اصل:** همهٔ اعداد این سند یا در همین جلسه اندازه‌گیری شده‌اند یا به‌صراحت به منبع‌شان ارجاع داده شده‌اند. هیچ ادعایی دربارهٔ آمادگی پروداکشن، PCI DSS، ISO 27001 یا SOC 2 در کار نیست.

## 1. خلاصهٔ اجرایی

- محیط اجرایی در این VM در آغاز جلسه **وجود نداشت**: نه `php` در `PATH` بود، نه Docker، نه MySQL/MariaDB، نه Composer. همهٔ این‌ها قابل بازیابی بودند **به‌جز** سرور دیتابیس (MariaDB) و Docker.
- با نصب PHP 8.4.28 از PPA ondrej/php و Composer 2.11.2 و راه‌اندازی دستی Redis، دستورهای `composer validate`، `php artisan about`، `php artisan route:list` و تست Pest روی SQLite درون‌حافظه‌ای با موفقیت اجرا شدند.
- `php artisan migrate:status` به‌علت نبود سرور MariaDB روی `127.0.0.1:3306` با `SQLSTATE[HY000] [2002] Connection refused` شکست می‌خورد — **مسدودکنندهٔ دقیق** برای هر کاری که به دیتابیس واقعی نیاز دارد.
- اجرای موازی Pest روی `tests/Unit` در این VM با `zend_mm_heap corrupted` و کد خروج 134 (SIGABRT) سقوط کرد؛ اجرای ترتیبی همان سوئیت هم پس از حدود 29 دقیقه (3٬080 قبول‌شده / 73 ناموفق، آخرین سوئیت: `RegTech`) با ری‌استارت ران‌تایم قطع شد و جمع نهایی آن نامشخص ماند — جزئیات در §4.
- فرانت‌اند در آغاز جلسه بیلد نمی‌شد (`vite: not found`، بدون `node_modules` و بدون `package-lock.json`)، اما پس از `npm install` بیلد Vite با موفقیت انجام شد و `public/build/manifest.json` ساخته شد.
- شمار واقعی دامین‌ها **62** است، نه 61؛ دامین `Meeting` در کامیت آدیت `1393080` اضافه شده و عدد 61 در `CLAUDE.md` قدیمی است.
- بدهی تحلیل استاتیک قابل‌توجه است: 21 فایل baseline در PHPStan با 10٬241 خطای سرکوب‌شده (`message:`) **به‌علاوهٔ 220 خطای جدید سطح 8** در اجرای کامل این جلسه (58 فایل) و **183 خطای PHPCS** در 23 فایل — تقریباً همه در کد جدید آدیت (KYC، Meeting، Wallet).

## 2. وضعیت محیط (Environment Status)

### 2.1 آنچه در آغاز جلسه نبود (مشاهده‌شده)

| جزء | وضعیت در آغاز | مدرک |
|---|---|---|
| PHP CLI | **نبود** — `which php php8.4 php8.3` خالی؛ فقط پکیج `php-common` نصب بود | `dpkg -l \| grep php` فقط `php-common 2:93ubuntu2` را نشان داد |
| Docker | **نبود** — `docker: command not found` | — |
| Composer | **نبود** — فقط کش `~/.cache/composer` از جلسهٔ قبل مانده بود | `~/.cache/composer/files` با تاریخ 2026-11-24 04:26 |
| MySQL / MariaDB server | **نبود و نصب هم نیست** — هیچ باینری `mysql`/`mysqld`/`mariadb` | `which mysql mysqld mariadb` خالی |
| Redis server | باینری بود (`/usr/bin/redis-server` نسخهٔ 7.0.15) اما **در حال اجرا نبود** و هیچ پورتی باز نبود | `ss -ltn` خالی |
| Node / npm | بود: Node v24.22.1، npm 10.9.4 | `node --version` |
| `vendor/` | بود: 368M، 314 پکیج نصب‌شده | `du -sh vendor` |
| `node_modules/` | **نبود**؛ `package-lock.json` هم **نبود** | `ls` هر دو را رد کرد |
| `public/build/` (Vite manifest) | **نبود** | `ls public/build` رد شد |

### 2.2 آنچه برای اجرای بیسلاین بازیابی شد (در همین جلسه)

| جزء | نسخهٔ بازیابی‌شده | روش |
|---|---|---|
| PHP | **8.4.28** (دقیقاً همان نسخهٔ جلسهٔ قبل) | افزودن PPA `ondrej/php` و `apt-get install php8.4-cli` + اکستنشن‌ها |
| اکستنشن‌های PHP | `gmp, intl, mbstring, pdo_mysql, pdo_pgsql, pdo_sqlite, sqlite3, redis, bcmath, curl, xml, zip, gd` + `opcache, sodium, pcntl, tokenizer, ...` | همان لیست جلسهٔ قبل (`docs/IMPLEMENTATION_STATUS.md` §3) |
| Composer | **2.11.2** (2026-11-10) | دانلود مستقیم `composer.phar` از `getcomposer.org/download/2.11.2/` در `/usr/local/bin/composer` |
| Redis | 7.0.15 | `redis-server --daemonize yes --port 6379 --save '' --appendonly no` — **با ریبوت VM از بین می‌رود** |
| Laravel | **12.73.0** | `php artisan --version` |
| Node / npm | v24.22.1 / 10.9.4 (از قبل بود) | — |

نکتهٔ بازیابی PPA: دستور `add-apt-repository -y ppa:ondrej/php` با `PermissionError` روی فایل `ubuntu.sources` (مالک `nobody`) ناتمام ماند؛ فایل منبع `.sources` و کلید GPG به‌صورت دستی نوشته شد (کلیدهای `4F4EA0AAE5267A6C` و `71DAEAAB4AD4CAB6` از keyserver.ubuntu.com). مشکل نهایی، دسترسی 660 فایل keyring بود که `gpgv` تحت کاربر `_apt` نمی‌توانست بخواند؛ با `chmod 644` حل شد. صحت امضا با `gpgv` به‌صورت دستی هم تأیید شد (`Good signature`).

نکتهٔ بازیابی پس از ری‌استارت: چون `apt-get update` فرایند هم‌زمان بیش از 5 دقیقه بدون هیچ سوکت شبکه در حالت خواب ماند و قفل apt را گرفته بود، بازیابی دوم با دانلود مستقیم بسته‌های `.deb` انجام شد: فایل `Packages.xz` از PPA و آرشیو Ubuntu دانلود و پارس شد، 26 بستهٔ PHP/وابستگی (شامل `php8.4-cli`، ‏`php8.4-common`، اکستنشن‌ها، `libzip4t64`، ‏`libgd3` و وابستگی‌هایش) با `dpkg -i` نصب شد؛ سپس `php8.4-redis` + `php8.4-igbinary` و `redis-server`/`redis-tools` (7.0.15) هم به همین روش اضافه شدند. این روش کار کرد اما شکننده است — جایگزین `apt-get install` نیست و برای فازهای بعد باید اسکریپت شود. در نهایت، لیست اکستنشن‌ها دقیقاً با جلسهٔ قبل برابر شد (شامل `redis`).

### 2.3 خروجی `php artisan about` (اجرا شد)

```
Application Name .... FinAegis | Laravel 12.73.0 | PHP 8.4.28 | Composer 2.11.2
Environment .......... local | Debug Mode ENABLED | URL localhost | Maintenance OFF
Timezone ............. UTC | Locale en
Cache ................ Config NOT CACHED | Events NOT CACHED | Routes NOT CACHED | Views CACHED
Drivers .............. Broadcasting log | Cache file | Database mariadb | Logs stack/daily
                       Mail log | Queue database | Scout meilisearch | Session database
Storage .............. public/storage NOT LINKED
Filament ............. v3.3.57 | Pulse v1.7.4 | Livewire v3.8.12 | Spatie Permissions 8.0.0
```

### 2.4 ری‌استارت ران‌تایم در میانهٔ جلسه (07:2x UTC)

در میانهٔ اجرای ترتیبی سوئیت Unit، ران‌تایم/VM ری‌استارت شد و همهٔ وضعیت خارج از `~` از بین رفت: PHP 8.4.28 و Composer 2.11.2 نصب‌شده با apt، سرور Redis در حال اجرا، کلید GPG و فایل منبع PPA در `/etc/apt/`، و همهٔ فایل‌های `/tmp` (از جمله لاگ Pest با 29 دقیقه پیشرفت و فایل‌های JSON مربوط به `ops:verify-env`). آنچه در `~/workspace` بود سالم ماند: سند بیسلاین، `node_modules/`، ‏`public/build/`، و درخت گیت با تغییرات هم‌زمان. پس از ری‌استارت، نصب PHP/Composer/Redis از ابتدا تکرار شد (رجوع به §2.2). نتیجه: **وضعیت محیط در این VM پایدار نیست و پس از هر ری‌استارت باید بازسازی شود**؛ برای فازهای بعد، اسکریپت بازسازی محیط (یا Dockerfile/CI) لازم است، نه اتکا به حافظهٔ جلسه.

### 2.5 گیت پروداکشن `ops:verify-env` (اجرا شد — پیش از ری‌استارت)

| اجرا | نتیجه |
|---|---|
| `php artisan ops:verify-env --json` (غیر strict، در `APP_ENV=local`) | **status: pass**، exit 0 — 42 چک: **7 PASS، 10 FAIL، 3 WARN، 22 SKIP**. FAILها در `local` مسدودکننده نیستند. |
| `php artisan ops:verify-env --strict --json` | **status: fail**، exit 1 — 42 چک: **9 PASS، 11 FAIL، 13 WARN، 9 SKIP**. یعنی با معیار پروداکشن، این محیط **مسدود** است. |

FAILهای غیر-strict (10 مورد — همان‌هایی که در strict هم مسدود می‌کنند):
- `core/app.debug` — ‏`APP_DEBUG=true`
- 4 سکرت خالی: `IAP_RECEIPT_PEPPER`، `TRIAL_FINGERPRINT_PEPPER`، `PRICING_QUOTE_PEPPER`، `BIOMETRIC_JWT_SECRET` (هر چهار مورد در اولین درخواست مربوطه hard-throw می‌کنند)
- `bypasses/kyc.providers.bridge` — ‏Bridge ارائه‌دهندهٔ فعال ramp/KYC است اما `BRIDGE_API_KEY` و `BRIDGE_WEBHOOK_PUBLIC_KEY` خالی‌اند
- `bypasses/demo.features` — فلگ‌های دمو روشن‌اند (`instant_deposits, skip_kyc, mock_external_apis, fixed_exchange_rates, auto_approve`)
- `bypasses/keymanagement.demo_mode` — ‏`KEY_MANAGEMENT_DEMO_MODE` برابر true است (HSM شبیه‌سازی‌شده به‌جای واقعی امضا می‌کند)
- `bypasses/regtech.demo_mode` — ‏`REGTECH_DEMO_MODE` برابر true است (فایلینگ/غربالگری نظارتی در حالت دمو)
- `bypasses/ai.demo_mode` — ‏`AI_DEMO_MODE` برابر true است (سرویس‌های AI خروجی آماده برمی‌گردانند)

WARNها: `APP_ENV=local`؛ ‏`HELIUS_API_KEY` خالی (سینک وب‌هوک Solana و backfill تراکنش‌ها شکست می‌خورد)؛ مقصد بکاپ فقط دیسک محلی است.

اولین تلاش برای اجرای این فرمان با `ParseError` در `app/Domain/Ledger/Listeners/PostGlEntryListener.php:21` شکست خورد — اما `php -l` همان فایل (هم نسخهٔ working tree و هم نسخهٔ HEAD) **بدون خطا** بود؛ علت، ویرایش هم‌زمان آن فایل توسط فرایند دیگر در همان لحظه بود (رجوع به §9). اجرای مجدد چند دقیقه بعد با موفقیت انجام شد و اعداد بالا از همان اجرای مجدد است.

### 2.6 `.env` فعلی (محلی، gitignore شده)

- `APP_ENV=local`، `APP_DEBUG=true`، `DB_CONNECTION=mariadb`، `DB_HOST=127.0.0.1:3306`، `DB_DATABASE=finaegis`، `DB_USERNAME=root`
- `CACHE_STORE=file`، `QUEUE_CONNECTION=database`، `SESSION_DRIVER=database`، `REDIS_HOST=127.0.0.1`
- 484 کلید؛ دقیقاً برابر با `.env.example` (هیچ کلیدی کم/زیاد نیست — بررسی پایتون روی هر دو فایل).
- هشدار: `APP_DEBUG=true` در محیط محلی قابل‌قبول است، اما `OpsVerifyEnvCommand` در پروداکشن آن را FAIL می‌کند (‎§7).

### 2.7 Docker

- `docker-compose.yml`: سرویس‌های `app` (پورت 8000)، `mysql:8.0` (پورت 3306)، `redis:7-alpine` (پورت 6379)، `mailpit` (پورت‌های 8025/1025). وابستگی `app` به healthy بودن `mysql` و `redis`.
- `docker/Dockerfile`: چندمرحله‌ای — مرحلهٔ Composer (`composer:2.8`، بدون dev)، مرحلهٔ Node (`node:22-alpine`، `npm ci` + `npm run build`)، و مرحلهٔ نهایی PHP.
- `docker-compose.websocket.yml` جداگانه هم موجود است.
- **بلاکر:** در این VM نه Docker Engine نصب است و نه `docker` در `PATH`؛ هیچ‌کدام از دستورهای Docker قابل اجرا نیست. مسیر جایگزینِ استفاده‌شده در این جلسه، نصب مستقیم PHP روی هاست بود.

## 3. وضعیت وابستگی‌ها (Dependency Status)

- `composer validate`: **معتبر** (`./composer.json is valid`) — با Composer 2.11.2 و PHP 8.4.28 اجرا شد.
- `composer.json`: پکیج `finaegis/core-banking-prototype-laravel`، نیازمند `php ^8.4` و `laravel/framework ^12.0`؛ ‏`require` شامل 49 پکیج، `require-dev` شامل 18 پکیج؛ `platform.php = 8.4.0`؛ `minimum-stability: stable`.
- `composer.lock`: هش `bc6ea39ae0b754350572516b98a94fbe`؛ 235 پکیج اصلی + 79 پکیج dev؛ ‏`vendor/composer/installed.json` هم 314 پکیج را تأیید می‌کند (235 + 79).
- اکستنشن‌های الزامی در `composer.json`: `ext-gmp`، `ext-intl`، `ext-mbstring`، `ext-pdo` — هر چهار مورد در PHP نصب‌شدهٔ این جلسه فعال‌اند (`php -m`).
- وابستگی‌های کلیدی (از `composer.json` / `composer.lock`):
  - `spatie/laravel-event-sourcing ^7.7` (Event Sourcing)
  - `pestphp/pest 3.8.5`، `phpstan/phpstan ^2.1` (در lock: 2.3.7)، `larastan/larastan ^3.0` (در lock: 3.15)، `squizlabs/php_codesniffer ^4.0` (در lock: 4.0.4)، `laravel/pint ^1.27`، `laravel/dusk ^8.3`
- `package.json`: فقط دو اسکریپت — `dev: vite` و `build: vite build`؛ 8 وابستگی dev (از جمله Vite 8.3.8، مشاهده‌شده در خروجی بیلد).
- **یافته:** `package-lock.json` در ریپو وجود ندارد، پس `npm ci` (که Dockerfile هم از آن استفاده می‌کند) بدون lockfile شکست می‌خورد؛ در این جلسه از `npm install --no-save --no-package-lock` استفاده شد تا lockfile ساخته نشود و فقط `node_modules/` (gitignore شده) پر شود. نتیجه: 200 پکیج در 2 دقیقه نصب شد.
- `vendor/` با `composer install` دوباره نصب **نشد** (از قبل کامل بود و `composer validate` هم معتبر است)؛ در صورت نیاز به نصب تمیز، دستور `composer install` با همین PHP/Composer قابل اجراست.

### 3.1 فرانت‌اند (Vite) — اجرا شد

- در آغاز جلسه بیلد ناممکن بود: `npm run build` با `sh: 1: vite: not found` شکست خورد (بدون `node_modules` و بدون `package-lock.json`).
- پس از `npm install --no-save --no-package-lock` (200 پکیج در 2 دقیقه؛ بدون ساختن `package-lock.json` تا درخت تمیز بماند)، بیلد **با موفقیت** انجام شد: ‏Vite v8.3.8، ‏70 ماژول transform شده، زمان 50.70 ثانیه، با یک هشدار chunk بزرگ‌تر از 200KB پس از minify (`vendor-charts` 206.43KB).
- خروجی: `public/build/manifest.json` با **6 ورودی** ساخته شد (پیش از آن وجود نداشت — همان «Vite manifest missing» ثبت‌شده در جلسهٔ قبل). `node_modules/` و `public/build/` هر دو gitignore شده‌اند و در `git status` دیده نمی‌شوند.

## 4. بیسلاین تست (Test Baseline)

> این بخش پس از تکمیل اجراهای در حال انجام، با اعداد دقیق به‌روز می‌شود. اعداد زیر تا اینجا قطعی‌اند.

### 4.1 پیکربندی تست

- فایل اصلی: `phpunit.xml` با 6 سوئیت: `Unit` (tests/Unit)، `Feature` (tests/Feature)، `Integration` (tests/Domain + tests/Console + tests/Integration)، `Components` (Actions/Filament/Http/Infrastructure/Models/Providers/Values/View/Smoke)، `Security` (tests/Security)، `MultiConnection` (tests/MultiConnection).
- فایل‌های جایگزین: `phpunit.ci.xml` (تایم‌اوت پیش‌فرض 120 به‌جای 10؛ بدون سوئیت‌های Components و MultiConnection)، `phpunit.ci-optimized.xml`، `phpunit.coverage.xml`، `phpunit.unit.xml`، `phpunit.security.xml`.
- محیط تست (`phpunit.xml` بخش `<php>`): `APP_ENV=testing`، `DB_CONNECTION=sqlite`، `DB_DATABASE=:memory:`، `CACHE_STORE=array`، `QUEUE_CONNECTION=sync`، `SESSION_DRIVER=array`، `MAIL_MAILER=array`، `BCRYPT_ROUNDS=4`، `memory_limit=1G`، `defaultTimeLimit=10`.
- `tests/Pest.php`: سوئیت‌های Feature/Domain/Console روی `Tests\TestCase`، سوئیت MultiConnection روی `MultiConnectionTestCase`، تست‌های Browser روی `DuskTestCase`؛ `PEST_PARALLEL=false` در `phpunit.xml`.
- `.pestignore`: ‏`vendor/ node_modules/ storage/ public/ coverage-html/ bootstrap/cache/ .git/ .github/ docs/ resources/ database/migrations/ database/factories/ work/ .venv/ sdks/`

### 4.2 شمار فایل‌های تست (شمارش ایستا)

| مسیر | فایل‌های PHP |
|---|---|
| `tests/Unit` | 534 |
| `tests/Feature` | 504 |
| `tests/Domain` | 209 |
| `tests/Integration` | 39 |
| `tests/Filament` | 13 |
| `tests/Security` | 12 |
| `tests/Console` | 10 |
| `tests/MultiConnection` | 9 |
| `tests/Actions` | 6 |
| `tests/Smoke` | 5 |
| `tests/Browser` | 4 |
| `tests/Traits` | 4 |
| `tests/Fixtures` | 3 |
| `tests/Models` | 2 |
| `tests/Http` / `tests/Infrastructure` / `tests/Providers` / `tests/Values` / `tests/View` | 1 هر کدام |
| فایل‌های سطح ریشهٔ `tests/` (مثل `Pest.php`، `TestCase.php`) | ~10 |
| **جمع کل** | **1٬371 فایل** |

### 4.3 نتایج اجرا (مشاهده‌شده در این جلسه)

| اجرا | دستور | نتیجه |
|---|---|---|
| Smoke: Enums | `./vendor/bin/pest tests/Unit/Values/EnumsTest.php` | **6 passed (16 assertions) در 6.72 ثانیه** — همان نتیجهٔ جلسهٔ قبل |
| Unit — موازی | `XDEBUG_MODE=off ./vendor/bin/pest --parallel tests/Unit` | **سقوط کرد — exit 1**: یک worker پاراست با `zend_mm_heap corrupted` و کد خروج 134 (SIGABRT) abort شد؛ هیچ خلاصهٔ Tests در لاگ ثبت نشد. این شکست محیطی است، نه لزوماً شکست تست. |
| Unit — ترتیبی (کامل) | `XDEBUG_MODE=off ./vendor/bin/pest tests/Unit` | **ناتمام ماند — قطع شد**: پس از حدود 29 دقیقه به **3٬080 مورد قبول‌شده (✓) و 73 مورد ناموفق (⨯)** رسیده بود (آخرین سوئیتِ در حال اجرا: `RegTech`) که ری‌استارت ران‌تایم فرایند را کشت و لاگ آن (`/tmp/pest-unit-seq.log`) همراه با کل `/tmp` پاک شد؛ بنابراین جمع نهایی این اجرا **نامشخص** است. هر 73 شکست تا آن لحظه در دامین‌های `Compliance` (بخش عمده — مثل `ChainalysisAdapterTest`، `OndatoServiceTest`، `AmlScreeningServiceTest`)، ‏`Ledger` (‏`TigerBeetleDriverTest`) و `Monitoring` (‏`HealthCheckerTest`) متمرکز بود. چون این شکست‌ها دقیقاً در بازهٔ ویرایش هم‌زمان فایل‌های همان دامین‌ها توسط فرایند دیگر رخ دادند، نمی‌توان آن‌ها را بدون اجرای مجدد، «شکست از پیش موجود» دانست. |
| Unit — موازی (تکرار) | — | پس از ری‌استارت، اجرای کامل موازی/ترتیبی سوئیت Unit تکرار نشد (زمان‌بر و وابسته به درخت در حال تغییر)؛ به‌جای آن زیرمجموعهٔ هدفمند §4.4 اجرا شد. |
| **تست‌های جدید کامیت آدیت (34 فایل)** | `./vendor/bin/pest <34 فایل>` | **نخست: 7 failed, 288 passed (978 assertions) در 81.32 ثانیه** — هر 7 شکست در تست‌های Exchange بود با خطای `Class "Redis" not found` در `PhpRedisConnector.php:80`؛ علت، نبود اکستنشن `phpredis` در PHP نصب‌شده با `dpkg` بود (محیطی، نه کد). پس از نصب `php8.4-redis` + `php8.4-igbinary` و راه‌اندازی Redis 7.0.15، **اجرای مجدد: 295 passed (1023 assertions)، 0 failed، exit 0، در 80.61 ثانیه**. |
| **Unit دامین‌های Ledger + Compliance** | `./vendor/bin/pest tests/Unit/Domain/Ledger tests/Unit/Domain/Compliance` | **210 passed (590 assertions)، 0 failed، exit 0، در 52.53 ثانیه** — توجه: در اجرای ترتیبیِ ناتمامِ قبلی، تست‌های Compliance شکست خورده بودند؛ در این اجرا (روی درخت پایدارتر) همه قبول شدند. این نوسان، اثر مستقیم ویرایش هم‌زمان درخت است (رجوع به §9). |

> مقایسه با ادعای جلسهٔ قبل: جلسهٔ قبل برای همین 34 فایل **243 passed / 0 failed (904 assertions)** گزارش داده بود؛ اجرای فعلی **295 passed (1023 assertions)** نشان می‌دهد. تفاوت (52 تست و 119 assertion بیشتر) به‌علت تغییر فایل‌های تست پس از آن کامیت توسط فرایند هم‌زمان است، نه خطا در شمارش. دو هشدار PHP هم در خروجی دیده شد (بی‌اثر بودن `use Mockery` و `use RuntimeException` در دو فایل تست Feature) — آرایشی است، نه شکست.

### 4.4 چرا سوئیت کامل Unit تکرار نشد

پس از ری‌استارت VM و بازیابی PHP، تکرار سوئیت کامل Unit (حدود 35 تا 40 دقیقه به‌صورت ترتیبی، روی درختی که هم‌زمان در حال تغییر است) به‌صرفه نبود؛ به‌جای آن دو زیرمجموعهٔ مرتبط با فاز سخت‌سازی در §4.3 اجرا شد (34 فایل تست جدید کامیت آدیت + Unit دامین‌های Ledger و Compliance). اجرای کامل سوئیت Unit و سپس Feature/Domain باید پس از تثبیت درخت (کامیت‌شدن کار فرایند هم‌زمان) و در CI یا روی ماشین پایدار انجام شود — عدد 3٬080/73 از اجرای ناتمام، فقط یک مشاهدهٔ جزئی است، نه بیسلاین نهایی.

## 5. بیسلاین تحلیل استاتیک (Static-Analysis Baseline)

### 5.1 پیکربندی (مشاهده‌شده)

| ابزار | نسخه | پیکربندی |
|---|---|---|
| PHPStan | 2.3.7 (از `composer.lock`) + Larastan 3.15 | `phpstan.neon` — **Level 8**؛ مسیرها: `app` و `tests`؛ مستثنی: `tests/Browser`، `tests/CreatesApplication.php`، `tests/DuskTestCase.php`؛ ‏`checkModelProperties: true`، `checkUnionTypes: true` |
| PHPStan baselines | 21 فایل `phpstan-baseline*.neon` | مجموع **10٬241 خط `message:`** در فایل‌های baseline = خطاهای سرکوب‌شدهٔ انباشته (بدهی تحلیل استاتیک). بزرگ‌ترین فایل‌ها مربوط به `level6/7/8`، `v61`، `pre-existing` و `phpstan22` هستند |
| PHPCS | 4.0.4 (از `composer.lock`؛ همان نسخهٔ CI) | `phpcs.xml` — استاندارد **PSR-12** (بدون قانون طول خط `Generic.Files.LineLength`)؛ مسیرها: `app` و `tests`؛ مستثنی: `vendor/`، `storage/`؛ استثناهای PSR-1 برای فایل‌های تست |
| PHP-CS-Fixer | (در `vendor/bin`) | `.php-cs-fixer.php`؛ دستور اجباری قبل از کامیت طبق `AGENTS.md` |
| Pint | ^1.27 | در `require-dev` |
| اسکریپت‌های Composer | `phpstan`، `phpstan:baseline`، `phpcs`، `phpcs:fix`، `test`، `test:coverage`، `quality` | `quality` = phpcs + phpstan + تست (ترتیب دقیق در `composer.json`) |

### 5.2 نتایج اجرا (در این جلسه — با محیط کامل §2.2)

| ابزار | دستور | نتیجه |
|---|---|---|
| PHPStan | `XDEBUG_MODE=off TMPDIR=/tmp/phpstan-run vendor/bin/phpstan analyse --memory-limit=2G --no-progress` | **exit 1 — 220 خطا در 58 فایل** (34 فایل در `app/` و 24 فایل در `tests/`)؛ زمان اجرا حدود 4.6 دقیقه (کش سرد). تفکیک شناسه‌ها: `argument.type` 55، `property.nonObject` 43، `missingType.iterableValue` 34، `missingType.generics` 21، `offsetAccess.notFound` 13، `return.type` 10، `method.nonObject` 7، `property.notFound` 6، `nullsafe.neverNull` 6، بقیه 25 مورد. تمرکز خطاها روی کد جدید آدیت است: دامین‌های `Compliance` (KYC — ‏`KycVerificationGate`، `KycManualReviewService`، `SarWorkflowService`، ...) ، ‏`Meeting` (دامین جدید — کنترلر، سرویس، مدل‌ها، پرووایدرها)، ‏`Wallet` (‏`ConversionService`، `FiatWalletService`، `FeeCalculator`، ...) و `Banking`/`Ramp`/`KeyManagement`. این خطاها **علاوه بر** 10٬241 خطای سرکوب‌شده در baselineها هستند. |
| PHPCS | `./vendor/bin/phpcs` (استاندارد PSR-12 از `phpcs.xml`) | **exit 3 — 183 خطا، 0 هشدار، در 23 فایل**؛ زمان 1 دقیقه و 16.77 ثانیه، حافظه 102MB. همهٔ 23 فایل تقریباً کد جدید آدیت‌اند (آداپتورهای سندباکس Banking، دامین Meeting، ‏`PostGlEntryListener`، تست‌های `KycHardeningPhase41Test` و `LedgerHardeningPhase42Test` و ...). پرتکرارترین sniffها: 130 مورد `Expected...`، ‏18 مورد `Only...`، ‏15 مورد `Parentheses...` — تقریباً همه با علامت `[x]` یعنی **قابل‌رفع خودکار با `phpcbf`** هستند. در این فاز هیچ اصلاح خودکاری اعمال نشد (دستور: فقط سند بیسلاین). |

> توجه: اجرای کامل PHPStan روی `app` + `tests` (سطح 8، بدون کشِ از قبل) در این VM حدود 4.6 دقیقه طول کشید و با موفقیت کامل شد — نیازی به اسکوپ‌کردن نبود.

## 6. وضعیت مایگریشن و ساختار (Migration & Structure Status)

### 6.1 مایگریشن‌ها

- **413 فایل مایگریشن** در `database/migrations` (شمارش ایستا) + 19 سیدر در `database/seeders`.
- `php artisan migrate:status`: **اجرا نشد** — بلوکه‌شده با خطای دقیق زیر (عدم وجود سرور MariaDB):
  `SQLSTATE[HY000] [2002] Connection refused (Connection: mariadb, Host: 127.0.0.1, Port: 3306, Database: finaegis, SQL: select exists (select 1 from information_schema.tables ...))`
- پیامد: هیچ‌کدام از 413 مایگریشن در این جلسه اعمال نشده و وضعیت `migrations` در دیتابیس (ran/pending) **نامشخص** است؛ برای گرفتن وضعیت واقعی، نصب و راه‌اندازی MariaDB/MySQL 8 و ساخت دیتابیس `finaegis` لازم است.
- تست‌ها به این بلوکه وابسته نیستند: `phpunit.xml` تست‌ها را روی SQLite درون‌حافظه‌ای (`:memory:`) اجرا می‌کند.

### 6.2 ساختار کلی ریپو (شمارش ایستا)

| مورد | عدد |
|---|---|
| فایل‌های PHP در `app/` | 3٬536 |
| فایل‌های PHP در `tests/` | 1٬371 |
| دامین‌ها در `app/Domain/` | **62** (نه 61 — رجوع به §6.3) |
| فایل‌های Provider در `app/Providers/` | 47 (+ پوشهٔ `Filament`) |
| فایل‌های پیکربندی در `config/` | 98 |
| فایل‌های روت در `routes/` | 9 فایل + 2 فایل در `routes/api/`؛ جمعاً 2٬858 خط |
| روت‌های ثبت‌شده (اجرا شد) | **1٬576** — ‏`GET|HEAD` 936، `POST` 533، `DELETE` 53، `PUT` 42، `PATCH` 6، `GET|HEAD|POST` 5، `PATCH|PUT` 1 |
| `#import` در `graphql/schema.graphql` | 45 (43 اسکیمای دامین + `directives` + `subscriptions`) |
| فایل‌های شامل `ShouldBeStored` (ایونت‌ها) | 341 |
| فایل‌های `AggregateRoot` | 46 |
| فایل‌های `Projector` | 26 |
| فایل‌های `Reactor` | 10 |
| فایل‌های مرتبط با Event Sourcing (ترکیبی) | 403 |
| کامیت آدیت `1393080` | 277 فایل تغییرکرده؛ 22٬999 افزوده / 688 حذف‌شده؛ شامل 137 فایل جدید در `app/Domain/` و 34 فایل تست جدید |

### 6.3 فهرست کامل 62 دامین (`app/Domain/`)

> **تصحیح عدد 61:** در کامیت `b8e521f` (v7.18.2) دقیقاً 61 دامین بود. کامیت آدیت `1393080` دامین **`Meeting`** (23 فایل PHP) را اضافه کرد و شمار را به 62 رساند. عدد 61 در `CLAUDE.md` و در دستور این فاز قدیمی است و باید به‌روز شود. شمار با سه روش مستقل تأیید شد: `ls -1 app/Domain | wc -l` = 63 (62 پوشه + `AGENTS.md`)، `ls -1d app/Domain/*/ | wc -l` = 62، و `git ls-tree` روی هر دو کامیت (61 در مقابل 62).

| # | دامین | فایل‌های PHP | # | دامین | فایل‌های PHP |
|---|---|---|---|---|---|
| 1 | Account | 111 | 32 | Meeting | 23 |
| 2 | AccountProvisioning | 14 | 33 | Microfinance | 21 |
| 3 | Activity | 2 | 34 | Mobile | 42 |
| 4 | AgentProtocol | 208 | 35 | MobilePayment | 29 |
| 5 | AI | 107 | 36 | Monitoring | 33 |
| 6 | Asset | 38 | 37 | Newsletter | 10 |
| 7 | Auth | 6 | 38 | OpenBanking | 14 |
| 8 | Banking | 53 | 39 | Payment | 71 |
| 9 | Basket | 24 | 40 | PaymentRails | 15 |
| 10 | Batch | 25 | 41 | Performance | 11 |
| 11 | CardIssuance | 93 | 42 | Pricing | 16 |
| 12 | Cgo | 36 | 43 | Privacy | 48 |
| 13 | Commerce | 27 | 44 | Product | 15 |
| 14 | Compliance | 162 | 45 | Ramp | 13 |
| 15 | Contact | 2 | 46 | Referral | 2 |
| 16 | CrossChain | 33 | 47 | RegTech | 16 |
| 17 | Custodian | 45 | 48 | Regulatory | 13 |
| 18 | DeFi | 28 | 49 | Relayer | 32 |
| 19 | Exchange | 161 | 50 | Rewards | 14 |
| 20 | FinancialInstitution | 25 | 51 | SMS | 12 |
| 21 | Fraud | 26 | 52 | Security | 27 |
| 22 | Governance | 31 | 53 | Shared | 40 |
| 23 | Interledger | 7 | 54 | Stablecoin | 100 |
| 24 | ISO20022 | 16 | 55 | Subscription | 49 |
| 25 | ISO8583 | 12 | 56 | Treasury | 66 |
| 26 | KeyManagement | 25 | 57 | TrustCert | 27 |
| 27 | Ledger | 24 | 58 | User | 26 |
| 28 | Lending | 50 | 59 | VirtualsAgent | 15 |
| 29 | MCP | 31 | 60 | VisaCli | 26 |
| 30 | MachinePay | 36 | 61 | Wallet | 148 |
| 31 | X402 | 38 | 62 | Webhook | 9 |

دامین‌های کوچک (2 فایل: `Activity`، `Contact`، `Referral`) در برابر بزرگ‌ترین‌ها (`AgentProtocol` 208، `Compliance` 162، `Exchange` 161، `Wallet` 148) نشان می‌دهد عمق پیاده‌سازی بین دامین‌ها بسیار ناهموار است — این خودش یک یافتهٔ بیسلاین است، نه قضاوت ارزشی.

### 6.4 کشف ایونت‌ها (Event Discovery)

- `config/event-sourcing.php`: ‏`auto_discover_projectors_and_reactors` فعال است و این مسیرها را اسکن می‌کند: `app/Domain/*/Projectors`، `app/Domain/*/Reactors`، `app/Domain/*/Sagas`، `app/Domain/*/Listeners`، `app/Domain/*/*/Reactors`؛ ‏`auto_discover_base_path = base_path()`؛ ‏`stored_event_model` و `stored_event_repository` از نوع Eloquent؛ ‏`dispatch_events_from_aggregate_roots = true`.
- `app/Providers/EventServiceProvider.php`: مپینگ صریح listener (9 ایونت: `Registered`، `Login`، `MoneyTransferred`، `KycVerificationCompleted`، `CardProvisioned`، `ProofOfInnocenceGenerated`، `UserProfileUpdated`، `VisaCliCardEnrolled`، `NotificationSending`) + دو subscriber (`SendSecurityAlertListener`، `LogMobileAuditEventListener`).
- نسخهٔ Spatie Event Sourcing: `^7.7` (v7.7+ طبق `CLAUDE.md`).

### 6.5 ارائه‌دهندگان خدمات مالی (Financial Service Providers)

Providerهای مرتبط با پول/بانک در `app/Providers/`: ‏`BankIntegrationServiceProvider`، `CardIssuanceServiceProvider`، `CustodianServiceProvider`، `ExchangeRateProviderServiceProvider`، `ExchangeServiceProvider`، `ExternalExchangeServiceProvider`، `KycServiceProvider`، `LendingServiceProvider`، `MobilePaymentServiceProvider`، `RampServiceProvider`، `StablecoinServiceProvider`، `TreasuryServiceProvider`، `WalletServiceProvider`.

مسیرهای پرداخت پیاده‌شده/سندشده (از کد و `config/payment_rails.php` و `CLAUDE.md`):

| مسیر | وضعیت مشاهده‌شده |
|---|---|
| Stripe (سپردهٔ کارت) | پیش‌فرض؛ `ProductionPaymentService` / `ProcessStripeDepositWorkflow`؛ سند HyperSwitch می‌گوید Stripe ریل پیش‌فرض سپرده است |
| HyperSwitch | **آزمایشی اما وصل‌شده** (PR #1118)؛ فقط با `HYPERSWITCH_ENABLED` (پیش‌فرض خاموش)؛ ‏`DepositController` از `HyperSwitchPaymentService::startDeposit` استفاده می‌کند |
| Lepo Finance (صدور کارت مجازی) | ارائه‌دهندهٔ اول کارت؛ بدون سندباکس — «Calling the production Lepo API: Read-only checks only; there is no sandbox»؛ وب‌هوک `POST /api/v1/webhooks/lepo`؛ توپ‌آپ با `CARD_TOPUPS_ENABLED` (پیش‌فرض خاموش) |
| Bridge.xyz (Ramp) | ریل اصلی v1 فیات ↔ استیبل‌کوین (فقط onramp؛ offramp در v1.1)؛ وب‌هوک `POST /api/v1/webhooks/bridge`؛ مارک‌آپ `developer_fee_bps` (Free=75، Pro=0) |
| Paysera | `PayseraDepositService` + `DemoPayseraDepositService` (نسخهٔ دمو) |
| ریل‌های ایرانی SATNA / PAYA / PEL / کارت‌به‌کارت | **فقط enum رزروشده** (`IranianPaymentRail`) + آداپتور سندباکس (`SandboxIranianRailAdapter` — «Simulates ONLY the local state machine»)؛ هیچ endpoint، credential یا فرمت پیام واقعی در ریپو نیست؛ هر چهار ریل در `config/payment_rails.php` با `env(..., false)` **خاموش**‌اند؛ اجرای پروداکشن تا زمان مستندات رسمی CBI، شراکت بانکی و بازبینی تحریمی **غیرفعال** می‌ماند |
| ریل‌های آمریکایی ACH / Fedwire / RTP / FedNow | همه در `config/payment_rails.php` پیش‌فرض خاموش (`false`)، به‌جز `ACH_SAME_DAY` |
| x402 / MPP / AP2 | پروتکل‌های پرداخت در دامین‌های `X402`، `MachinePay` و اسناد مربوطه |

## 7. پیکربندی‌های env و پروداکشن

- فایل‌های env در ریپو: `.env` (محلی، 484 کلید)، `.env.example`، `.env.ci`، `.env.demo`، `.env.dev`، `.env.performance`، `.env.production.example`، `.env.test`، `.env.testing`، `.env.zelta.example`.
- `.env.production.example`: ‏`APP_ENV=production`، `APP_DEBUG=false`، `DEMO_SKIP_KYC=false`، `DEMO_AUTO_APPROVE=false`، `DB_CONNECTION=mariadb`، `QUEUE_CONNECTION=redis`، `CACHE_STORE=redis` — یعنی در نمونهٔ پروداکشن، فلگ‌های دمو **خاموش**‌اند.
- اما در `config/demo.php` پیش‌فرض‌ها **روشن**‌اند: `DEMO_INSTANT_DEPOSITS=true`، `DEMO_SKIP_KYC=true`، `DEMO_MOCK_EXTERNAL_APIS=true`، `DEMO_FIXED_EXCHANGE_RATES=true`، `DEMO_AUTO_APPROVE=true` — هر محیطی که این متغیرها را صریحاً `false` نکند، در حالت دمو اجرا می‌شود. این دقیقاً همان ریسک ثبت‌شده در `docs/IMPLEMENTATION_STATUS.md` است.
- `OpsVerifyEnvCommand` (`php artisan ops:verify-env --json`): گیت پیش‌از دیپلوی؛ exit 1 = مسدود؛ FAILها فقط در پروداکشن (یا با `--strict`) مسدودکننده‌اند. موارد FAIL شامل: `APP_DEBUG=true`، `APP_KEY` خالی، فلگ‌های دمو روشن، `CUSTODIAN_MOCK_ENABLED`، و سوئیچ‌های پروداکشن مربوط به ابزارهای دمو/MCP است. این فرمان در این جلسه اجرا نشد (نیاز به تصمیم‌گیری دربارهٔ `--strict` دارد و خروجی‌اش به `.env` فعلی وابسته است) — اجرای آن با `--strict` برای فاز بعد پیشنهاد می‌شود.
- `config/demo.php` مقادیر دمو را هم سخت‌کد کرده است: نرخ‌های ثابت ارز (`EUR/USD 1.10`، `BTC/USD 45000`، `ETH/USD 2500`)، امتیاز اعتباری پیش‌فرض 750، نرخ تأیید وام 80٪، کاربر دمو با موجودی پیش‌فرض 100٬000 (1٬000 دلار) و `kyc_verified: true`.

## 8. TODOها / Placeholderها / Mockها / کد مرده

### 8.1 شمارش ایستا (grep روی `app/`)

| الگو | تعداد خط |
|---|---|
| `TODO` / `FIXME` / `XXX` / `HACK` | 15 خط (شامل چند مورد که در واقع کلمهٔ `XXX` در داده‌های نمونه مثل BIC است) |
| `DEMO_SKIP` / `DEMO_AUTO_APPROVE` / `Mock[A-Z]` / `Fake[A-Z]` / `fake liveness` | 108 خط |
| `dd(` / `var_dump(` / `die(` / `exit(` | 0 خط |
| فراخوانی `env(` در کد `app/` | 1 خط (باقی موارد در `config/` متمرکز شده‌اند — الگوی درست لاراول) |

### 8.2 TODO/FIXMEهای واقعی (مهم‌ترین‌ها)

- `app/Domain/Basket/Services/BasketService.php:109` — ‏`TODO: Consider moving this to a dedicated query service`
- `app/Domain/Mobile/Services/BiometricJWTService.php:278` — ‏`FIXME: empty challenge — see method docblock; must wire mobile-supplied challenge before flag flip` (**مسئلهٔ امنیتی باز** — چالش بیومتریک خالی است)
- `app/Domain/TrustCert/Services/PresentationService.php:29` — ‏`TODO: Inject VerifiableCredentialService when full W3C VC integration is needed`
- `app/Domain/Ramp/Services/StripeCryptoOnrampService.php:301` — ‏`TODO (v1.1 enablement): this hardcodes ethereum for USDC/USDT/ETH`
- `app/Infrastructure/Bridge/BridgeClient.php:22,203` — ‏`TODO` دربارهٔ تأیید نام دقیق هدر در سندباکس Bridge پیش از go-live
- `app/Helpers/SchemaHelper.php:260` — ‏`TODO(regulatory): stopgap — images/og-default.png shows card artwork`
- `app/Domain/Compliance/Services/GdprService.php:1375` — ‏TODO ساخت‌یافته (لاگ + ردیف audit) برای داده‌های مشترک IAP
- بقیهٔ موارد، داده‌های نمونه‌اند (BICهای `FINAEGISXXX`، `SSKMDEMMXXX`، `EVPULT22XXX` در سرویس‌های SEPA و OpenBanking) نه TODO واقعی.

### 8.3 Mockها و سرویس‌های شبیه‌سازی در کد اصلی (نه تست)

| کلاس | نقش |
|---|---|
| `Custodian/Connectors/MockBankConnector` | کانکتور بانک ساختگی (کاستودین) |
| `Exchange/Providers/MockExchangeRateProvider` | نرخ ارز ساختگی |
| `Lending/Services/MockCreditScoringService` | امتیازدهی اعتباری ساختگی |
| `Ramp/Providers/MockRampProvider` | ارائه‌دهندهٔ Ramp ساختگی |
| `Wallet/Services/HardwareWallet/MockHardwareWalletService` | امضاکنندهٔ سخت‌افزاری ساختگی |
| `Payment/Services/DemoPaymentGatewayService`، `DemoPaymentService`، `DemoPayseraDepositService`، `SandboxPaymentService` | مسیرهای پرداخت دمو/سندباکس |
| `Banking/Connectors/SandboxIranianRailAdapter`، `SandboxBankAdapter` | آداپتورهای سندباکس بانکی/ریل ایرانی |

این Mockها فی‌نفسه بد نیستند، اما **باید در پروداکشن غیرقابل‌دسترس باشند**؛ گیت `ops:verify-env` دقیقاً همین را چک می‌کند (`CUSTODIAN_MOCK_ENABLED` و سوئیچ‌های دمو).

### 8.4 کد مرده / نیمه‌کاره (مشاهده‌شده، نیازمند تأیید با تحلیل استاتیک)

- بدون اجرای PHPStan/PHPCS نمی‌توان «کد مرده» را با اطمینان فهرست کرد؛ موارد زیر فقط نشانه‌های ایستا هستند:
- `app/Domain/AgentProtocol/Workflows/Activities/VerifyIdentityDocumentActivity.php:32` — شمارهٔ سند نمونهٔ `XXXXXXXX` در فعالیت وریفای هویت.
- دامین‌های 2فایلی (`Activity`، `Contact`، `Referral`) احتمالاً اسکلت/رزرو هستند، نه پیاده‌سازی کامل.
- `docs/TODO.md` (23٬612 بایت) و `CHANGELOG.md` (161٬879 بایت) در ریپو موجود‌اند و باید با این بیسلاین تطبیق داده شوند، نه اینکه دوباره نوشته شوند.
- هیچ `dd`/`var_dump`/`die` در `app/` نیست (0 مورد) — نشانهٔ خوب بهداشت کد.

## 9. یافته‌های بحرانی و محدودیت‌های موجود (Critical Findings & Existing Limitations)

### 9.1 درخت در حال تغییر هم‌زمان — مهم‌ترین قید این بیسلاین

- در آغاز جلسه، درخت روی کامیت `1393080` تمیز بود (`git status` خالی). از حدود 06:51 UTC به بعد، فرایند/ایجنت دیگری شروع به ویرایش درخت کرد: تا پایان جلسه **58 ورودی در `git status`** (41 فایل تغییریافته + 17 فایل untracked که یکی از آن‌ها همین سند است) و 53 فایل با mtime در بازهٔ 15 دقیقه‌ای در اوج ویرایش.
- محتوای تغییرات هم‌زمان: کار سخت‌سازی فاز 4.1/4.2 روی دامین‌های KYC و Ledger (مثل `KycStatusMachine`، `SandboxIranianKycProvider`، `LedgerPrecision`، `LedgerDriverSync`، `LedgerPostingFailure`، مایگریشن `2026_11_24_000002_ledger_posting_failures_and_driver_sync.php`، تست‌های `LedgerHardeningPhase42Test`، `TransferFeeLedgerTest`، `KycHardeningPhase41Test`) — یعنی دقیقاً همان حوزه‌هایی که این بیسلاین اندازه می‌گیرد.
- پیامدهای مشاهده‌شده:
  - اولین اجرای `ops:verify-env` با `ParseError` گذرا در `PostGlEntryListener.php` شکست خورد چون فایل در همان لحظه نیمه‌کاره روی دیسک بود؛ `php -l` بلافاصله بعد، هم برای نسخهٔ working tree و هم HEAD، بدون خطا بود.
  - شکست‌های Compliance در اجرای ترتیبی ناتمام Unit (70 مورد) در اجرای مجدد روی درخت پایدارتر به صفر رسید (210/210 در Ledger + Compliance).
- نتیجه: **همهٔ اعداد این سند، عکس فوری از یک هدف متحرک‌اند.** هر عدد تست/تحلیل باید با هش کامیت + وضعیت `git status` در لحظهٔ اجرا خوانده شود. پیش از شروع فاز 1، تثبیت درخت (کامیت یا stash کردن کار هم‌زمان) و اجرای مجدد بیسلاین روی درخت تمیز، الزامی است.

### 9.2 محیط ناپایدار

- PHP/Composer/Redis/کلید PPA پس از ری‌استارت VM کاملاً از بین رفتند و از ابتدا بازسازی شدند (§2.6). برای فازهای بعد، یک اسکریپت بازسازی محیط (یا اجرای بیسلاین در CI) لازم است.
- `apt-get update` فرایند هم‌زمان بیش از 5 دقیقه در حالت خواب بدون هیچ سوکت شبکه باقی ماند؛ بازیابی PHP در این جلسه در نهایت با دانلود مستقیم بسته‌های `.deb` از PPA و آرشیو Ubuntu و نصب با `dpkg -i` انجام شد — بدون استفاده از قفل apt. این روش کار کرد اما شکننده است و جایگزین `apt-get install` نمی‌شود.
- Docker در این VM وجود ندارد؛ `migrate:status` به‌علت نبود MariaDB مسدود است؛ سرور Redis فقط با راه‌اندازی دستی بالا می‌آید و با ریبوت از بین می‌رود.

### 9.3 یافته‌های امنیتی و محصولی (از کد و پیکربندی — مشاهده‌شده، نه حدس)

1. **فلگ‌های دمو به‌صورت پیش‌فرض روشن‌اند** (`config/demo.php`): ‏`DEMO_SKIP_KYC`، `DEMO_AUTO_APPROVE`، `DEMO_MOCK_EXTERNAL_APIS`، `DEMO_FIXED_EXCHANGE_RATES`، `DEMO_INSTANT_DEPOSITS` همه `true` هستند مگر اینکه محیط صریحاً `false` کند. نمونهٔ پروداکشن (`.env.production.example`) آن‌ها را `false` می‌کند، اما هر محیطی که از نمونه پیروی نکند در حالت دمو اجرا می‌شود. `ops:verify-env --strict` همین را FAIL می‌کند.
2. **چهار سکرت خالی** در `.env` فعلی: `IAP_RECEIPT_PEPPER`، `TRIAL_FINGERPRINT_PEPPER`، `PRICING_QUOTE_PEPPER`، `BIOMETRIC_JWT_SECRET` — هر چهار مورد در اولین استفاده hard-throw می‌کنند (رجوع به §2.5).
3. **`UserResource` هنوز 2FA را نمایش می‌دهد**: ‏`two_factor_secret` و `two_factor_recovery_codes` در فرم Filament (`app/Filament/Admin/Resources/UserResource.php:45,47`) وجود دارند — همان مورد بازِ ثبت‌شده در جلسهٔ قبل.
4. **چالش بیومتریک خالی**: ‏`BiometricJWTService.php:278` — ‏FIXME صریح: «must wire mobile-supplied challenge before flag flip».
5. **حالت‌های دمو در سه دامین فعال‌اند**: ‏`KEY_MANAGEMENT_DEMO_MODE`، `REGTECH_DEMO_MODE`، `AI_DEMO_MODE` همه به `true` resolve می‌شوند (پیش‌فرض پیکربندی) — HSM شبیه‌سازی‌شده امضا می‌کند و فایلینگ نظارتی در حالت دمو اجرا می‌شود.
6. **ریل‌های ایرانی فقط سندباکس‌اند** و `config/payment_rails.php` هر چهار ریل را خاموش نگه می‌دارد؛ هیچ endpoint یا credential واقعی در ریپو نیست (رجوع به §6.5).
7. **Lepo بدون سندباکس است** — فراخوانی API پروداکشن Lepo فقط برای بررسی‌های read-only مجاز است (رجوع به `CLAUDE.md`).
8. **`key:generate` در بوت خراب است** (ثبت‌شده در جلسهٔ قبل): ‏`BlockchainServiceProvider.php:51` در زمان بوت encrypter را resolve می‌کند — دست‌نخورده باقی مانده است.
9. **`public/storage` لینک نشده است** (`php artisan about`: ‏`NOT LINKED`) و `APP_DEBUG=true` در `.env` محلی فعال است.
10. **بدهی PHPStan**: ‏10٬241 خطای سرکوب‌شده در 21 فایل baseline سطح 8 (§5.1) — عدد بزرگی است و پیش از سخت‌سازی واقعی باید روند کاهشی آن مشخص شود.

### 9.4 محدودیت‌های این بیسلاین (آنچه اندازه گرفته نشد)

- سوئیت کامل Unit (و Feature/Domain/Integration) به‌صورت کامل اجرا نشد؛ فقط زیرمجموعه‌های §4.3 با عدد نهایی گزارش شده‌اند.
- `migrate:status` / `migrate:fresh` اجرا نشد (نبود MariaDB)؛ وضعیت واقعی 413 مایگریشن روی دیتابیس واقعی نامشخص است.
- `php artisan l5-swagger:generate` و تست‌های Browser/Dusk اجرا نشدند.
- PHPCS/PHPStan: نتیجهٔ نهایی در §5.2 درج می‌شود؛ در صورت ناتمام‌ماندن، اسکوپ و دلیل صریحاً نوشته می‌شود.
- هیچ‌کدام از ارائه‌دهندگان خارجی (Stripe، Lepo، Bridge، Helius، KYC vendorها) با credential واقعی تست نشدند؛ همهٔ مسیرهای پروداکشن وابسته به آن‌ها sandbox-only یا غیرفعال‌اند.

### 9.5 کارهای بعدی پیشنهادی (برای فاز 1)

1. تثبیت درخت (کامیت کار هم‌زمان) و اجرای مجدد بیسلاین کامل روی درخت تمیز — شامل سوئیت کامل Pest، ‏PHPStan کامل، PHPCS، و `ops:verify-env --strict`.
2. نصب MariaDB/MySQL 8 (یا اجرای `migrate:status` در CI) برای وضعیت واقعی مایگریشن‌ها.
3. اسکریپت بازسازی محیط (نصب PHP 8.4 + Composer + Redis) برای بقا پس از ری‌استارت VM.
4. خاموش‌کردن فلگ‌های دمو و پرکردن 4 سکرت خالی پیش از هر استقرار آزمایشی؛ حذف نمایش 2FA secrets از `UserResource`؛ وصل‌کردن چالش بیومتریک (FIXME در §8.2).
5. به‌روزرسانی عدد دامین‌ها در `CLAUDE.md` از 61 به 62 (دامین `Meeting`).

---

*پایان بیسلاین فاز صفر — 2026-11-24. تنها فایل تغییریافته در این فاز همین سند است.*
