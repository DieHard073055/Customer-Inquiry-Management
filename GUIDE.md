# Codebase Guide

This document explains how the project is built, what every file does, and why each
decision was made. It is written for someone who has not used Laravel before, so that
you can read through it and answer questions about this codebase confidently.

---

## Table of contents

1. [What Laravel is](#1-what-laravel-is)
2. [Project structure](#2-project-structure)
3. [The database](#3-the-database)
4. [How a request travels through the code](#4-how-a-request-travels-through-the-code)
5. [File-by-file walkthrough](#5-file-by-file-walkthrough)
   - [routes/api.php](#routesapiphp)
   - [Enums](#enums)
   - [Migration files](#migration-files)
   - [Models](#models)
   - [StoreInquiryRequest](#storeinquiryrequestphp)
   - [InquiryService](#inquiryservicephp)
   - [InquiryController](#inquirycontrollerphp)
   - [InquiryResource and InquiryCollection](#inquiryresource-and-inquirycollection)
6. [The Docker setup](#6-the-docker-setup)
7. [The test suite](#7-the-test-suite)
8. [Conventions reference](#8-conventions-reference)

---

## 1. What Laravel is

Laravel is a PHP framework that gives you a structured, opinionated way to build web
applications and APIs. Rather than writing everything from scratch, you use the
framework's built-in tools: routing, validation, database access, logging, and
testing.

Laravel enforces a pattern called **MVC** — Model, View, Controller:

- **Model** — a PHP class that represents a database table and its rules
- **View** — the presentation layer (this project has no HTML views; it returns JSON instead)
- **Controller** — receives an HTTP request, coordinates the work, and returns a response

The framework is built around the idea that each class should have one clear
responsibility. The routing file decides which controller handles a request. The
controller decides what to do. The service contains the business logic. The model
talks to the database. The resource shapes the output. Each piece is small and
focused.

---

## 2. Project structure

The files that make up this project, with a brief note on each:

```
app/
  Enums/
    InquiryCategory.php       The four allowed inquiry categories
    InquiryStatus.php         The four allowed inquiry statuses

  Http/
    Controllers/
      Api/
        InquiryController.php Receives HTTP requests, returns HTTP responses
    Requests/
      StoreInquiryRequest.php Validates the body of a POST request
    Resources/
      InquiryResource.php     Shapes one inquiry into JSON
      InquiryCollection.php   Shapes a paginated list of inquiries into JSON

  Models/
    Inquiry.php               Represents the inquiries database table
    InquiryLog.php            Represents the inquiry_logs database table

  Services/
    InquiryService.php        Business logic: creates inquiries and audit logs

database/
  migrations/
    ..._create_inquiries_table.php      Defines the inquiries table schema
    ..._create_inquiry_logs_table.php   Defines the inquiry_logs table schema

routes/
  api.php                     Registers the three API endpoints

tests/
  Feature/
    InquiryApiTest.php        32 integration tests covering all endpoints

docker/
  nginx/default.conf          Nginx web server configuration
  php/local.ini               PHP runtime settings (memory limit, upload size)
  php/entrypoint.sh           Script that runs when the app container starts

Dockerfile                    Instructions to build the PHP application image
docker-compose.yml            Defines and connects the three Docker services
.env.docker                   Environment variables used inside Docker
phpunit.xml                   Test runner configuration
```

---

## 3. The database

There are two tables.

### `inquiries`

The main table. One row per submitted inquiry.

| Column | Type | Notes |
|--------|------|-------|
| `id` | integer | Auto-incrementing primary key, set by MySQL |
| `reference_number` | string | Human-facing ID like `MSE-A3BF92CK`, always unique |
| `name` | string | Full name of the person submitting |
| `email` | string | Email address |
| `phone` | string | Optional — can be null |
| `category` | enum | One of: `trading`, `market_data`, `technical_issues`, `general_questions` |
| `subject` | string | One-line summary of the inquiry |
| `message` | text | Full message body |
| `status` | enum | One of: `open`, `in_progress`, `resolved`, `closed` — always starts as `open` |
| `ip_address` | string | IP address of the submitter, stored for compliance, never returned by the API |
| `created_at` | timestamp | Set automatically by Laravel when the row is created |
| `updated_at` | timestamp | Updated automatically by Laravel on every save |

### `inquiry_logs`

An audit trail. Every time something meaningful happens to an inquiry, a row is
written here. Currently this happens once — when an inquiry is first created.

| Column | Type | Notes |
|--------|------|-------|
| `id` | integer | Auto-incrementing primary key |
| `inquiry_id` | integer | Foreign key — points to a row in the `inquiries` table |
| `event` | string | A label describing what happened, e.g. `inquiry_created` |
| `context` | JSON | Extra data about the event — category and subject at time of creation |
| `ip_address` | string | IP address at the time of the event |
| `created_at` / `updated_at` | timestamps | Set automatically |

The foreign key is defined with `cascadeOnDelete` — if an inquiry is ever deleted,
all of its log rows are automatically deleted too. This prevents orphaned log records.

### What is a migration?

Laravel never asks you to write SQL by hand. Instead, you write a **migration** — a
PHP file that describes a change to the database schema using a readable builder
syntax. When you run `php artisan migrate`, Laravel reads all migration files in
order and executes the ones it has not run yet.

Each migration has two methods:
- `up()` — applies the change (creates a table, adds a column, etc.)
- `down()` — reverses it, used when rolling back during development

---

## 4. How a request travels through the code

Here is the complete journey of a `POST /api/inquiries` request, from the moment it
arrives to the moment a response is sent.

```
Incoming HTTP request
        │
        ▼
┌───────────────────┐
│   routes/api.php  │  Laravel matches the URL and HTTP method to a controller
└────────┬──────────┘
         │
         ▼
┌────────────────────────┐
│  StoreInquiryRequest   │  Laravel validates the request body against the rules
│  (FormRequest)         │  defined in this class. If any rule fails, Laravel
│                        │  returns a 422 response automatically — the controller
│                        │  is never called.
└────────┬───────────────┘
         │  (validation passed)
         ▼
┌────────────────────────┐
│  InquiryController     │  Receives the pre-validated data. Calls the service.
│  @store()              │  Wraps the call in a try/catch for safety.
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  InquiryService        │  Opens a database transaction. Creates the inquiry
│  @store()              │  row. Creates the audit log row. Writes to the daily
│                        │  log file. Commits the transaction. Returns the model.
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  InquiryResource       │  Takes the Inquiry model and builds the exact JSON
│                        │  structure to return — exposing only safe fields.
└────────┬───────────────┘
         │
         ▼
  HTTP 201 Created
  { "data": { ... } }
```

If anything inside the service throws an exception:
- The database transaction is automatically rolled back (no partial data saved)
- The controller's `catch` block calls `report($e)` to log the real error internally
- A safe, generic 500 message is returned to the caller

---

## 5. File-by-file walkthrough

### `routes/api.php`

```php
Route::apiResource('inquiries', InquiryController::class)
    ->only(['index', 'store', 'show']);
```

`Route::apiResource()` is a Laravel shortcut that registers a standard set of REST
routes for a resource in a single line. The `->only()` call limits it to just the
three we need:

| Method | URL | Controller method |
|--------|-----|-------------------|
| `GET` | `/api/inquiries` | `index()` |
| `POST` | `/api/inquiries` | `store()` |
| `GET` | `/api/inquiries/{inquiry}` | `show()` |

Without `->only()`, it would also register routes for `update` and `destroy`, which
are not part of this project.

---

### Enums

**`app/Enums/InquiryCategory.php`**

```php
enum InquiryCategory: string
{
    case Trading          = 'trading';
    case MarketData       = 'market_data';
    case TechnicalIssues  = 'technical_issues';
    case GeneralQuestions = 'general_questions';
}
```

**`app/Enums/InquiryStatus.php`**

```php
enum InquiryStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Resolved   = 'resolved';
    case Closed     = 'closed';
}
```

Enums are a PHP 8.1 feature that define a closed set of allowed values. Without them,
you would write raw strings throughout the code (`"trading"`, `"open"`) with no
enforcement — a typo would silently save bad data. With enums, if you write
`InquiryCategory::Tradign`, PHP throws an error immediately at the point of the
mistake.

The `: string` declaration makes these **backed enums** — each case has an explicit
string value that is what gets stored in the database. You access the raw string via
`.value` (e.g. `InquiryCategory::Trading->value` gives you `"trading"`).

`InquiryCategory` also has a `label()` method that returns a presentable string
(e.g. `"Market Data"` instead of `"market_data"`), useful for any future display
requirements.

---

### Migration files

**`create_inquiries_table`**

```php
Schema::create('inquiries', function (Blueprint $table) {
    $table->id();
    $table->string('reference_number')->unique();
    $table->string('name');
    $table->string('email');
    $table->string('phone')->nullable();
    $table->enum('category', ['trading', 'market_data', 'technical_issues', 'general_questions']);
    $table->string('subject');
    $table->text('message');
    $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
    $table->string('ip_address', 45)->nullable();
    $table->timestamps();
});
```

Each method call on `$table` adds a column. Notable ones:
- `->unique()` — adds a database-level uniqueness constraint on `reference_number`
- `->nullable()` — allows the column to store NULL (phone and ip_address are optional)
- `->enum(...)` — restricts the column to a fixed set of values at the database level
- `->default('open')` — sets the default value if none is provided
- `->timestamps()` — shortcut that adds both `created_at` and `updated_at` columns
- `$table->id()` — shortcut for an auto-incrementing unsigned big integer primary key

**`create_inquiry_logs_table`**

```php
$table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
$table->json('context')->nullable();
```

- `foreignId('inquiry_id')->constrained('inquiries')` — creates the column and a
  foreign key constraint pointing at `inquiries.id` in one step
- `cascadeOnDelete()` — instructs MySQL to delete related log rows automatically
  when the parent inquiry is deleted
- `json('context')` — stores structured data as a JSON string in the database

---

### Models

**`app/Models/Inquiry.php`**

```php
class Inquiry extends Model
{
    protected $fillable = [
        'reference_number', 'name', 'email', 'phone',
        'category', 'subject', 'message', 'status', 'ip_address',
    ];

    protected $casts = [
        'category' => InquiryCategory::class,
        'status'   => InquiryStatus::class,
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(InquiryLog::class);
    }
}
```

A **Model** in Laravel is a PHP class that represents one database table. The
framework's ORM (Object Relational Mapper) is called Eloquent. It maps each row in
the table to an instance of the class, and each column to a property — so
`$inquiry->name` gives you the name stored in that row.

**`$fillable`** is a whitelist of columns that are allowed to be set in bulk via
`Inquiry::create([...])`. Any field not in this list is silently ignored. This
prevents an attacker from submitting extra fields (like `status` or `id`) to
manipulate data they shouldn't be able to touch.

**`$casts`** tells Eloquent to automatically convert a column's raw value when reading
from or writing to the database. Here, `category` and `status` are cast to their
respective enum classes. This means:
- Reading: `$inquiry->category` returns an `InquiryCategory` enum instance, not a
  plain string
- Writing: you can assign `InquiryStatus::Open` and Eloquent stores `"open"`
  automatically

**`logs()`** defines a relationship. `hasMany(InquiryLog::class)` tells Eloquent
that one inquiry can have many log rows, linked by the `inquiry_id` column. You can
access them with `$inquiry->logs` — Eloquent writes the SQL join for you.

**`app/Models/InquiryLog.php`**

```php
class InquiryLog extends Model
{
    protected $fillable = ['inquiry_id', 'event', 'context', 'ip_address'];

    protected $casts = [
        'context' => 'array',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }
}
```

The `context` column is cast to `'array'` — when you read it, PHP automatically
decodes the JSON string back into an array. When you write an array to it, Eloquent
automatically encodes it to JSON before saving.

`belongsTo(Inquiry::class)` is the inverse of the `hasMany` relationship — from a
log row you can access the parent inquiry via `$log->inquiry`.

---

### `StoreInquiryRequest.php`

```php
class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'category' => ['required', Rule::enum(InquiryCategory::class)],
            'subject'  => ['required', 'string', 'max:255'],
            'message'  => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.enum' => 'Category must be one of: trading, market_data, technical_issues, general_questions.',
            'message.min'   => 'The message must be at least 10 characters.',
        ];
    }
}
```

A **Form Request** is a dedicated Laravel class for input validation. When a
controller method type-hints a Form Request instead of a plain `Request`, Laravel
automatically validates the incoming data against `rules()` before the controller
code runs. If anything fails, it returns a `422 Unprocessable Entity` response with
a structured list of errors — no code in the controller needed.

`authorize()` returning `true` means any caller may use this endpoint. In a system
with authentication, this is where you would check whether the logged-in user has
permission.

`rules()` returns an array where each key is a field name and each value is a list
of rules that all must pass. `Rule::enum(InquiryCategory::class)` delegates the
allowed-values check to the enum class — if new categories are ever added to the
enum, the validation updates automatically.

`messages()` overrides the default Laravel error text for specific rule failures,
using the format `fieldName.ruleName`. This gives the API consumer clearer,
actionable error messages rather than generic ones.

---

### `InquiryService.php`

```php
class InquiryService
{
    public function store(array $data, string $ipAddress): Inquiry
    {
        return DB::transaction(function () use ($data, $ipAddress) {

            $inquiry = Inquiry::create([...]);

            $inquiry->logs()->create([
                'event'   => 'inquiry_created',
                'context' => [
                    'category' => $inquiry->category->value,
                    'subject'  => $inquiry->subject,
                ],
                'ip_address' => $ipAddress,
            ]);

            Log::channel('daily')->info('Inquiry created', [...]);

            return $inquiry;
        });
    }

    private function generateReferenceNumber(): string
    {
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ref = 'MSE-' . strtoupper(Str::random(8));

            if (! Inquiry::where('reference_number', $ref)->exists()) {
                return $ref;
            }
        }

        throw new RuntimeException("Unable to generate a unique reference number after {$maxAttempts} attempts.");
    }
}
```

The **Service** class holds the business logic for creating an inquiry. The reason
this code lives here rather than in the controller is separation of concerns — the
controller's only job is to deal with HTTP (reading the request, returning a
response). The service knows *what to do* with the data.

**`DB::transaction()`** wraps everything inside a single atomic unit. This means
either all three things happen — the inquiry row is saved, the audit log row is
saved, the transaction commits — or none of them do. If anything throws an exception,
Laravel automatically rolls back both writes and the database is left in its original
state. This is important for compliance: you should never have an inquiry without an
audit record, or a log record pointing at an inquiry that does not exist.

**Dual logging** — the service writes to two places. The `inquiry_logs` database table
provides a structured, queryable audit trail. The `Log::channel('daily')` call writes
to a rotating daily log file (`storage/logs/laravel-YYYY-MM-DD.log`). The file log
remains useful even if the database is unavailable or under investigation.

**`generateReferenceNumber()`** loops up to 10 times, each time generating a random
8-character alphanumeric string and checking whether it already exists. With a
character space of 36^8 (over 2.8 trillion combinations), a collision in practice is
essentially impossible. The 10-attempt cap exists to prevent the theoretical
worst-case of an infinite loop — after 10 failures it throws a `RuntimeException`
which the controller catches and converts to a safe 500 response.

---

### `InquiryController.php`

```php
class InquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiryService) {}

    public function index(Request $request): InquiryCollection { ... }
    public function store(StoreInquiryRequest $request): JsonResponse { ... }
    public function show(Inquiry $inquiry): InquiryResource { ... }
}
```

The controller is intentionally thin — it only handles HTTP concerns.

**Constructor injection** — the `InquiryService` is declared in the constructor
rather than instantiated with `new InquiryService()`. Laravel's **service container**
sees the type hint, constructs the dependency automatically, and injects it. This
pattern is called dependency injection. It makes the controller easier to test because
you can swap in a fake service during tests.

**`index()`** builds a database query dynamically:

```php
Inquiry::query()
    ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
    ->when($request->filled('status'),   fn ($q) => $q->where('status',   $request->status))
    ->latest()
    ->paginate($request->integer('per_page', 15));
```

- `->when(condition, callback)` — only adds the `WHERE` clause if the condition is
  true. If `?category=` is not in the URL, the filter is simply not applied.
- `->latest()` — orders by `created_at` descending (newest first)
- `->paginate(15)` — returns 15 results per page and automatically handles the
  `?page=` query parameter

**`store()`** wraps the service call in a `try/catch (Throwable $e)`. `Throwable`
catches both PHP `Error` and `Exception` — it is broader than `Exception` alone and
is the correct thing to catch at a boundary like this. On failure, `report($e)` logs
the full exception internally (stack trace and all), while the caller only ever sees a
generic 500 message.

**`show(Inquiry $inquiry)`** — the parameter being typed as `Inquiry` instead of a
plain `int` is called **route model binding**. Laravel sees the `{inquiry}` placeholder
in the URL, queries the `inquiries` table for that ID, and passes the fully-loaded
model to the method. If no record is found, Laravel returns a `404 Not Found`
response automatically — no `findOrFail()` call needed.

---

### `InquiryResource` and `InquiryCollection`

**`InquiryResource`** controls what a single inquiry looks like in JSON:

```php
public function toArray(Request $request): array
{
    return [
        'id'               => $this->id,
        'reference_number' => $this->reference_number,
        'name'             => $this->name,
        'email'            => $this->email,
        'phone'            => $this->phone,
        'category'         => $this->category->value,
        'subject'          => $this->subject,
        'message'          => $this->message,
        'status'           => $this->status->value,
        'created_at'       => $this->created_at->toISOString(),
        'updated_at'       => $this->updated_at->toISOString(),
    ];
}
```

Without a resource, Laravel would serialize every column on the model, including
`ip_address` — which must never be returned. The resource is also where:
- `->value` is called on the enum properties so the API returns plain strings
  (`"trading"`) rather than PHP enum objects
- `->toISOString()` formats timestamps consistently (e.g. `2026-03-28T08:00:00.000000Z`)

**`InquiryCollection`** wraps a paginated list:

```php
public $collects = InquiryResource::class;

public function paginationInformation(...): array
{
    return [
        'meta'  => ['current_page', 'last_page', 'per_page', 'total'],
        'links' => ['first', 'last', 'prev', 'next'],
    ];
}
```

`$collects` tells Laravel which resource class to use for each item. The
`paginationInformation()` override reshapes Laravel's default pagination envelope
into a cleaner structure. Without the override, Laravel wraps pagination data in a
way that mixes `links` and `meta` in non-standard positions.

---

## 6. The Docker setup

The project runs as three Docker containers that communicate over a shared private
network.

```
Browser
   │  HTTP :8080
   ▼
┌──────────┐   PHP-FPM   ┌──────────┐   SQL   ┌──────────┐
│  Nginx   │ ──────────► │  app     │ ───────► │  MySQL   │
│  :80     │             │  :9000   │          │  :3306   │
└──────────┘             └──────────┘          └──────────┘
```

### `docker-compose.yml`

Defines the three services and how they connect:

**`db`** (MySQL 8.4)
- Stores all data in a named volume (`mse_db_data`) that persists between restarts
- Has a healthcheck that pings MySQL every 5 seconds
- The other services use `depends_on: condition: service_healthy` so they wait until
  MySQL is actually ready before starting, not just when the container starts

**`app`** (built from `Dockerfile`)
- Runs PHP-FPM — PHP's FastCGI Process Manager, which handles PHP execution
- Does not expose any port publicly; Nginx connects to it over the internal network
- Mounts `./storage` as a volume so log files written inside the container are
  accessible on your machine and survive container restarts
- Reads environment variables from `.env.docker`

**`nginx`** (Nginx 1.27-alpine)
- The only service exposed to your machine, on port `8080`
- Serves static files (like `test-ui.html`) directly from `./public`
- Forwards all `.php` requests to the `app` container via FastCGI

### `Dockerfile`

Describes how to build the `app` container image step by step:

```dockerfile
FROM php:8.4-fpm-alpine
```
Starts from the official PHP 8.4 image. Alpine is a minimal Linux distribution —
the resulting image is much smaller than a full Debian or Ubuntu base.

```dockerfile
RUN apk add --no-cache ... && docker-php-ext-install pdo pdo_mysql ...
```
Installs system libraries and PHP extensions. `pdo_mysql` is what allows Laravel to
connect to MySQL. `opcache` caches compiled PHP bytecode for performance.

```dockerfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```
Copies the Composer binary from its own official image — a clean way to get Composer
without installing it separately.

```dockerfile
COPY composer.json composer.lock ./
RUN composer install --no-dev ...
COPY . .
```
Dependencies are installed before the application code is copied. This is intentional:
Docker caches each step. If you only change application code (not `composer.json`),
Docker reuses the cached `composer install` layer and the rebuild is fast.

```dockerfile
RUN chown -R www-data:www-data /var/www/storage ...
```
PHP-FPM runs as the `www-data` user. This gives it write permission to the `storage`
folder, which is where Laravel writes log files and cached files.

### `docker/php/entrypoint.sh`

Runs every time the `app` container starts, before PHP-FPM begins accepting requests:

```sh
php artisan migrate --force   # Apply any pending database migrations
php artisan config:cache      # Pre-compile config into a single cached file
php artisan route:cache       # Pre-compile routes into a single cached file
exec php-fpm                  # Hand off to PHP-FPM
```

`--force` is required for `migrate` in production mode (`APP_ENV=production`) —
without it, Laravel asks for confirmation interactively, which would hang the container.

Caching config and routes means Laravel does not re-parse those files on every
request — a meaningful performance gain in production.

### `.env.docker`

A separate environment file that overrides the local `.env` inside Docker:

```
APP_ENV=production
APP_DEBUG=false          ← never expose stack traces to callers
DB_HOST=db               ← 'db' is the service name in docker-compose.yml,
DB_CONNECTION=mysql        Docker's internal DNS resolves it to the container
```

---

## 7. The test suite

The tests live in `tests/Feature/InquiryApiTest.php` and use Laravel's built-in
testing tools, which sit on top of PHPUnit.

### How tests are isolated

The class uses `RefreshDatabase` — a Laravel trait that wraps each test in a database
transaction and rolls it back after the test finishes. Every test therefore starts
with a completely empty database, and tests can never affect each other.

### Database used for tests

`phpunit.xml` overrides the database connection before any test runs:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE"   value=":memory:"/>
```

This switches to an in-memory SQLite database — a lightweight engine that runs
entirely in RAM. No MySQL server is needed. The suite is fast and works on any
machine with PHP installed.

This is separate from the Docker setup, which uses MySQL. The two never interfere.

### How HTTP requests are made in tests

```php
$this->postJson('/api/inquiries', [...]);
$this->getJson('/api/inquiries');
$this->getJson('/api/inquiries/1');
```

These helpers make a fake HTTP request that travels through the full Laravel stack —
routing, validation, controller, service, database. These are true **integration
tests**: they test the whole chain together, not individual functions in isolation.

### What the 32 tests cover

**Submitting an inquiry (store)**
- Returns 201 with the correct JSON structure
- All fields are correctly persisted to the database
- A unique `MSE-XXXXXXXX` reference number is generated
- Status defaults to `open`
- An audit log row is created with the correct event and context
- The daily log file is written with the right data
- Phone field is optional
- All four valid categories are accepted
- Controller returns a safe 500 when the service throws an exception
- Transaction rolls back if the audit log insert fails — no partial data saved

**Validation**
- Each required field is individually tested as missing
- Invalid email format rejected
- Invalid category rejected with the custom error message
- Message too short (under 10 chars) rejected with custom error message
- Message too long (over 5000 chars) rejected
- Name too long (over 255 chars) rejected
- All fields missing at once returns all errors together

**Listing inquiries (index)**
- Empty list returned when no inquiries exist
- All inquiries returned
- Correct pagination structure (`meta` and `links` keys)
- `per_page` parameter respected
- `?category=` filter works
- `?status=` filter works
- Results are ordered newest first

**Fetching a single inquiry (show)**
- Correct inquiry returned by ID
- 404 returned for a non-existent ID
- `ip_address` is never present in the response

---

## 8. Conventions reference

A summary of every Laravel convention used, and what it means:

| Convention | Where used | What it does |
|---|---|---|
| `Route::apiResource()->only()` | `routes/api.php` | Registers multiple REST routes in one line, limited to the ones you need |
| Form Request | `StoreInquiryRequest` | Moves validation into its own class; controller only runs if validation passes |
| Route model binding | `show(Inquiry $inquiry)` | Laravel auto-fetches the model by ID from the URL; returns 404 if not found |
| Eloquent ORM | Models | Database access through PHP objects — no raw SQL |
| `$fillable` | Models | Whitelist of columns safe for mass assignment; protects against over-posting |
| `$casts` | `Inquiry` model | Automatically converts columns to PHP types (enums, arrays) on read and write |
| `hasMany` / `belongsTo` | Models | Defines relationships between tables; Eloquent writes the SQL joins for you |
| API Resource | `InquiryResource` | Controls exactly what JSON is returned; decoupled from the model |
| `paginationInformation()` | `InquiryCollection` | Overrides the default pagination envelope shape |
| Service class | `InquiryService` | Business logic separated from HTTP handling |
| Dependency injection | `InquiryController` | Dependencies declared in the constructor; Laravel provides them automatically |
| `DB::transaction()` | `InquiryService` | Atomic writes — either all succeed or all roll back |
| Backed enums (`: string`) | `InquiryCategory`, `InquiryStatus` | Fixed allowed values with an explicit stored string; `.value` gives the string |
| `->when()` | `InquiryController@index` | Conditionally adds query clauses only when a parameter is present |
| `->latest()` | `InquiryController@index` | Orders by `created_at` descending |
| `->paginate()` | `InquiryController@index` | Handles page offset and returns pagination metadata automatically |
| `catch (Throwable $e)` | `InquiryController@store` | Catches both Errors and Exceptions — the correct broad catch at a boundary |
| `report($e)` | `InquiryController@store` | Logs the full exception internally without exposing it to the caller |
| `RefreshDatabase` | Test class | Rolls back all database changes after each test — full isolation |
| In-memory SQLite | `phpunit.xml` | Fast, serverless database for tests; independent of Docker/MySQL |
