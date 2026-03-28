# Maldives Stock Exchange — Customer Inquiry Management API

A backend-only REST API built with Laravel 13 that lets visitors submit customer
inquiries and lets staff retrieve them. Built for compliance, with full audit
logging and database transaction handling.

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)

That's it. PHP, Composer, and MySQL all run inside containers.

---

## Running the project

```bash
# 1. Clone the repo
git clone <repo-url>
cd maldives_stock_exchange

# 2. Start all services (builds on first run)
docker compose up --build -d

# 3. Check everything is up
docker compose ps
```

The API is now live at **http://localhost:8080**.

On first start the app container automatically runs migrations before accepting
requests. You can watch the logs with:

```bash
docker compose logs -f app
```

To stop:

```bash
docker compose down          # stops containers, keeps database
docker compose down -v       # stops containers and wipes the database
```

---

## Manual testing

A browser-based test UI is included. Open it at:

```
http://localhost:8080/test-ui.html
```

It covers all three endpoints with forms, filters, pagination, and raw response
inspection.

---

## API reference

Base URL: `http://localhost:8080/api`

All requests must include the header:
```
Accept: application/json
```

---

### POST /api/inquiries

Submit a new inquiry.

**Request body**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `name` | string | Yes | Max 255 characters |
| `email` | string | Yes | Must be a valid email |
| `phone` | string | No | Max 20 characters |
| `category` | string | Yes | See allowed values below |
| `subject` | string | Yes | Max 255 characters |
| `message` | string | Yes | Between 10 and 5000 characters |

Allowed `category` values: `trading`, `market_data`, `technical_issues`, `general_questions`

**Example request**

```bash
curl -X POST http://localhost:8080/api/inquiries \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Ahmed Rasheed",
    "email": "ahmed@mse.mv",
    "phone": "+960 300 0000",
    "category": "trading",
    "subject": "Order not executing",
    "message": "My buy order for 1000 shares has been pending for over an hour."
  }'
```

**Response — 201 Created**

```json
{
  "data": {
    "id": 1,
    "reference_number": "MSE-A3BF92CK",
    "name": "Ahmed Rasheed",
    "email": "ahmed@mse.mv",
    "phone": "+960 300 0000",
    "category": "trading",
    "subject": "Order not executing",
    "message": "My buy order for 1000 shares has been pending for over an hour.",
    "status": "open",
    "created_at": "2026-03-28T08:00:00.000000Z",
    "updated_at": "2026-03-28T08:00:00.000000Z"
  }
}
```

**Response — 422 Unprocessable Entity** (validation failure)

```json
{
  "message": "The email field must be a valid email address. (and 1 more error)",
  "errors": {
    "email": ["The email field must be a valid email address."],
    "category": ["Category must be one of: trading, market_data, technical_issues, general_questions."]
  }
}
```

---

### GET /api/inquiries

Retrieve a paginated list of inquiries.

**Query parameters**

| Parameter | Type | Notes |
|-----------|------|-------|
| `category` | string | Filter by category |
| `status` | string | Filter by status (`open`, `in_progress`, `resolved`, `closed`) |
| `per_page` | integer | Results per page, default `15` |
| `page` | integer | Page number, default `1` |

**Example request**

```bash
curl "http://localhost:8080/api/inquiries?category=trading&status=open&per_page=5" \
  -H "Accept: application/json"
```

**Response — 200 OK**

```json
{
  "data": [
    {
      "id": 1,
      "reference_number": "MSE-A3BF92CK",
      "name": "Ahmed Rasheed",
      "email": "ahmed@mse.mv",
      "phone": "+960 300 0000",
      "category": "trading",
      "subject": "Order not executing",
      "message": "My buy order for 1000 shares has been pending for over an hour.",
      "status": "open",
      "created_at": "2026-03-28T08:00:00.000000Z",
      "updated_at": "2026-03-28T08:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 5,
    "total": 1
  },
  "links": {
    "first": "http://localhost:8080/api/inquiries?page=1",
    "last": "http://localhost:8080/api/inquiries?page=1",
    "prev": null,
    "next": null
  }
}
```

---

### GET /api/inquiries/{id}

Retrieve a single inquiry by its ID.

**Example request**

```bash
curl http://localhost:8080/api/inquiries/1 \
  -H "Accept: application/json"
```

**Response — 200 OK**

```json
{
  "data": {
    "id": 1,
    "reference_number": "MSE-A3BF92CK",
    "name": "Ahmed Rasheed",
    "email": "ahmed@mse.mv",
    "phone": "+960 300 0000",
    "category": "trading",
    "subject": "Order not executing",
    "message": "My buy order for 1000 shares has been pending for over an hour.",
    "status": "open",
    "created_at": "2026-03-28T08:00:00.000000Z",
    "updated_at": "2026-03-28T08:00:00.000000Z"
  }
}
```

**Response — 404 Not Found**

```json
{
  "message": "No query results for model [App\\Models\\Inquiry] 999"
}
```

---

## Running the tests

Tests run locally against an in-memory SQLite database — no Docker required.

```bash
# Install dependencies (if not already done)
composer install

# Run the full test suite
php artisan test
```

Expected output:

```
PASS  Tests\Feature\InquiryApiTest
✓ store creates inquiry and returns 201
✓ store persists all fields correctly
...

Tests: 32 passed (123 assertions)
```

---

## Infrastructure

| Container | Image | Exposed port |
|-----------|-------|-------------|
| `mse_nginx` | nginx:1.27-alpine | `8080` |
| `mse_app` | php:8.4-fpm-alpine | internal only |
| `mse_db` | mysql:8.4 | `3307` (host) |

The MySQL database is also accessible directly on `localhost:3307` if you need
to inspect it with a database client.

---

## Further reading

See [`GUIDE.md`](GUIDE.md) for a full walkthrough of how the code is structured,
what every file does, and what Laravel conventions were used.
