# Hardware Shop Management

A billing, stock and customer-dues app for a single hardware shop. The shop owner logs in as the
admin to make bills, take payments, track what each customer owes, manage products and stock,
and see daily sales and profit. It works on a laptop, a tablet or a phone.

It is built for shops in India: amounts are in rupees (₹), payments can be cash, UPI or bank
transfer, bills go to customers on WhatsApp, and products can carry HSN codes and GST rates.

The project has two parts:

- **`backend/`**: a Laravel 13 API (PHP).
- **`frontend/`**: a Vue 3 single-page app that talks to the API.

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Project structure](#project-structure)
- [Running it locally](#running-it-locally)
- [Configuration](#configuration)
- [Tests and code checks](#tests-and-code-checks)
- [Importing products from Excel](#importing-products-from-excel)
- [Deploying](#deploying)
- [API overview](#api-overview)

## Features

**Bills**

- Bills are numbered by year: `INV-2026-0001`, `INV-2026-0002`, and so on.
- Customers can pay in full, in part or later, by cash, UPI or bank transfer. Anything unpaid
  is kept as a due on that bill.
- A bill can be edited or cancelled, but never deleted. Every change is recorded in the
  activity log, and any money handed back is recorded as a refund.
- Returned items go back into stock and get their own number (`RET-2026-0001`).
- Every bill can be downloaded as a PDF. It can also be sent to the customer on WhatsApp as a
  private link that opens without a login.
- The bill list can be filtered by date (with quick picks such as today and this month),
  payment method, product, old dues and edited or cancelled bills. It shows totals for the
  bills listed.

**Customers**

- Customers are identified by their mobile number and are looked up while making a bill.
- Each customer page shows their bills and what they still owe.
- A payment towards a customer's dues clears their oldest bills first.

**Products**

- A product can come in several sizes or variants, each with its own item code (SKU), purchase
  price, selling price, MRP, stock and minimum stock.
- Units can allow decimals (kg, litre, metre) or only whole numbers (piece, box).
- A product can have up to 10 photos.
- Each product records where it is kept: shop or godown, floor, rack and shelf. This is shown
  as a short code such as `SHOP-GF-R3-S2`.
- Products can also carry an HSN code, a GST rate and a description.
- Sizes that fall below their minimum stock are counted on the dashboard and the Products page,
  and the Products page has a low-stock filter.
- Each product has a stock history listing every delivery and every correction.
- Products can be added in bulk from an Excel file. See
  [Importing products from Excel](#importing-products-from-excel).

**Stock**

- **Stock in** records deliveries from suppliers and adds them to stock. A delivery entered by
  mistake is deleted, which takes its stock back out.
- **Stock corrections** fix the count after a recount, damage, a customer return or a return to
  the supplier.
- Each size keeps an average purchase cost, so profit stays right when supplier prices change.

**Categories and brands**

- Categories can have sub-categories, for example `Paints > Exterior`.
- Each product can have a brand.

**Dashboard and reports**

- The dashboard shows today's net sales, profit, money received, the total customers owe, and
  how many products are low on stock.
- The sales report covers any date range. It shows the totals, a day-by-day breakdown, sales by
  product and the top customers.

**Activity log**

- Every login, bill, payment, stock change and product change is recorded with who did it and
  when. Entries can't be edited.

**Security**

- **Logins:** a login lasts 30 days. Tapping your name in the header opens **Your account**,
  where you change the password. Changing it needs the current password and logs you out on
  every other device.
- **Rate limits:** every API route has one, with stricter limits for logging in and for heavy
  work. See [Rate limits](#rate-limits).
- **Browser protection:** security headers on every answer from the API, and a
  Content-Security-Policy in the built app, so only the app's own scripts can run.
- **Live servers:** the app only answers requests made to its own address, and never goes
  online with the example admin login. See [Before going live](#before-going-live).

**Easy to use**

- Every short form or trade term (SKU, MRP, HSN, GST and so on) has an ⓘ that explains it in
  plain words.
- Phone screens get their own layouts: a slide-in menu, card lists and filter panels.

## Tech stack

| Part     | Uses                                                                                                                                                                                                                                        |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend  | PHP 8.3+, [Laravel 13](https://laravel.com), [Sanctum](https://laravel.com/docs/sanctum) (API tokens), [dompdf](https://github.com/dompdf/dompdf) (PDF bills), [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) (Excel import) |
| Database | MySQL 8+. SQLite also works, and the tests use an in-memory SQLite database.                                                                                                                                                                |
| Frontend | [Vue 3](https://vuejs.org) (`<script setup>`), [Vue Router](https://router.vuejs.org), [Pinia](https://pinia.vuejs.org), [Vite](https://vite.dev)                                                                                           |
| Testing  | PHPUnit, [Vitest](https://vitest.dev) with Vue Test Utils                                                                                                                                                                                   |
| Linting  | [Pint](https://laravel.com/docs/pint) (PHP), ESLint and oxlint (JavaScript), Prettier                                                                                                                                                       |

## Project structure

```text
hardware-shop-management/
├── backend/                     Laravel API
│   ├── app/
│   │   ├── Enums/               Bill status, payment methods, activity actions, …
│   │   ├── Http/
│   │   │   ├── Controllers/Admin/   Thin controllers: take the request, call a service, return JSON
│   │   │   ├── Middleware/          EnsureUserIsAdmin
│   │   │   └── Requests/Admin/      Form requests that validate every input
│   │   ├── Models/              Eloquent models and their relationships
│   │   ├── Repositories/        All database queries
│   │   ├── Services/            Business rules (bills, stock, reports, import, …)
│   │   └── Support/             Small helpers: Money, StockCost, DocumentNumber, ProductSheet, …
│   ├── config/shop.php          Shop name, address, phone and time zone
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/             AdminUserSeeder, UnitSeeder
│   ├── resources/views/pdf/     The bill PDF layout
│   ├── routes/
│   │   ├── api.php              The admin API
│   │   └── web.php              The public bill link sent on WhatsApp
│   └── tests/                   Feature and unit tests
└── frontend/                    Vue app
    └── src/
        ├── components/          Dialogs, product picker, ⓘ info tips, …
        ├── composables/         useMediaQuery, useScrollLock
        ├── layouts/             AdminLayout (header, menu)
        ├── router/
        ├── services/api.js      Calls the API with the login token
        ├── stores/              Pinia stores, one per section
        ├── utils/               Formatting, billing, glossary of terms, …
        └── views/               One page per screen
```

### Code conventions

- **Controllers stay thin.** They have no business logic. Validation lives in form requests,
  rules in services and database queries in repositories.
- **Models are linked through Eloquent relationships**, not hand-written joins.
- **Money** is stored as rupees with two decimals, and calculations are done in paise (whole
  numbers) through `App\Support\Money` to avoid rounding errors. On screen, `.00` is hidden,
  so amounts show as `₹1,500`, or `₹1,500.50` when there are paise.
- **The shop's time zone** (`SHOP_TIMEZONE`) decides which day a bill or payment belongs to.

## Running it locally

### What you need

- PHP 8.3 or newer, with the extensions `pdo_mysql`, `mbstring`, `dom`, `xml`, `gd`, `zip`,
  `fileinfo` and `iconv`
- [Composer](https://getcomposer.org) 2
- MySQL 8 or newer
- Node.js 22.18+ or 24.12+, with npm

### 1. Get the code

```sh
git clone https://github.com/ravijajoriya/hardware-shop-management.git
cd hardware-shop-management
```

### 2. Set up the backend

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Create an empty database:

```sh
mysql -u root -p -e "CREATE DATABASE hardware_shop_management"
```

Open `backend/.env` and set the database and shop details:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hardware_shop_management
DB_USERNAME=root
DB_PASSWORD=your-password

SHOP_NAME="Your Shop Name"
SHOP_ADDRESS="Shop address printed on bills"
SHOP_PHONE=9876543210
```

Create the tables, add the admin account and the common units, link the photo folder, and start
the API:

```sh
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
php artisan db:seed --class=UnitSeeder
php artisan storage:link
php artisan serve
```

The API now runs at <http://localhost:8000>.

> Run the two seeders by name as shown. Plain `php artisan db:seed` also creates a sample
> "Test User" account, which you don't need.

### 3. Set up the frontend

In a second terminal:

```sh
cd frontend
npm install
echo "VITE_API_URL=http://localhost:8000/api" > .env
npm run dev
```

Open <http://localhost:5173> and log in:

| Email               | Password   |
| ------------------- | ---------- |
| `admin@example.com` | `password` |

This example login only works on your own computer. To change the password, tap your name in
the header to open **Your account**. On a live server the seeder refuses the example login and
needs your own (see [Before going live](#before-going-live)).

## Configuration

### Backend (`backend/.env`)

| Setting                                                                            | What it does                                                                                                                   |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `APP_URL`                                                                          | The API's own address. It is used in product photo links and in the bill link sent on WhatsApp.                                |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The database connection.                                                                                                       |
| `SHOP_NAME`                                                                        | Printed at the top of every bill and used in WhatsApp messages. Falls back to `APP_NAME`.                                      |
| `SHOP_ADDRESS`, `SHOP_PHONE`                                                       | Printed on bills.                                                                                                              |
| `SHOP_TIMEZONE`                                                                    | Decides which day a bill or payment belongs to, and the year in bill numbers. Default `Asia/Kolkata`.                          |
| `SANCTUM_EXPIRATION`                                                               | How long a login lasts, in minutes. Default 43200 (30 days).                                                                   |
| `TRUSTED_PROXIES`                                                                  | `*` when the app runs behind a hosting platform's front server, so it sees visitors' real IP addresses. Leave empty otherwise. |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`                                      | Only for creating the first admin on a live server. Remove them once the account exists.                                       |

### Frontend (`frontend/.env`)

| Setting        | What it does                                                                                             |
| -------------- | -------------------------------------------------------------------------------------------------------- |
| `VITE_API_URL` | The API address the app calls, ending in `/api`. It is built into the app, so rebuild after changing it. |

## Tests and code checks

### Backend

The tests use an in-memory SQLite database, so they never touch your real data.

```sh
cd backend
php artisan test        # run the tests
vendor/bin/pint         # format the PHP code
```

### Frontend

```sh
cd frontend
npm run test:unit -- --run   # run the tests once
npm run lint                 # oxlint and ESLint (fixes what it can)
npm run format               # Prettier
npm run build                # production build into frontend/dist
```

## Importing products from Excel

A shop with hundreds of products can add them all at once instead of typing each one: go to
**Products → Import from Excel**.

1. **Download the template.** It has a Products sheet to fill in, an Example sheet, and a Help
   sheet that explains every column and lists the units, categories and brands already in the
   app.
2. **Upload the filled file**: `.xlsx`, `.xls` or `.csv`, up to 2,000 rows and 5 MB. The app
   checks it straight away and saves nothing yet.
3. **Review and import.** The page shows what is ready to add, what is already in the app and
   which rows have problems, with the row number, the column and the reason.

| Needed columns                                                             | Optional columns                                                                                                                             |
| -------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Product name, Category, Unit, Purchase price, Selling price, Opening stock | Size or variant, Brand, Item code (SKU), MRP, Minimum stock, Shop or godown, Floor, Rack, Shelf, HSN code, GST rate (%), Description, Active |

How rows are handled:

- **Sizes:** a product in several sizes gets one row per size, all with the same product name.
- **Existing products:** a product whose name is already in the app is skipped and listed.
  Nothing about it changes.
- **Problem rows:** you choose. Either fix the file and choose it again, or import only the
  products that are ready and leave the rest out.
- **New categories and brands** are created automatically. Write `Paints > Exterior` for a
  sub-category.
- **Column order doesn't matter,** and extra columns are ignored.
- **Prices** can be written as `₹1,250.50` or `Rs. 300`.
- **Activity log:** each import adds one entry.

## Deploying

**To put it on a free Oracle Cloud server,** follow [deploy/README.md](deploy/README.md). Its
scripts set up the whole server: Nginx, PHP, MySQL, HTTPS, nightly backups and a one-command
update (`sudo shop-deploy`). They work on any Ubuntu 24.04 or 26.04 server.

To deploy somewhere else: the frontend is a set of static files, and the backend is a normal
Laravel app (PHP 8.4 or newer, because of the locked package versions).

**Backend**, on a server with PHP, a web server (such as Nginx) and MySQL:

```sh
composer install --no-dev --optimize-autoloader
# .env: APP_ENV=production, APP_DEBUG=false, the real APP_URL, the database and shop settings
php artisan key:generate      # first deploy only
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
```

**Frontend**, built with the live API address:

```sh
VITE_API_URL=https://api.your-shop.com/api npm run build
```

Serve `frontend/dist`, and send every unknown path to `index.html` so links such as
`/products/import` work when opened directly. The build includes a Content-Security-Policy that
allows only `VITE_API_URL`'s address, so build with the real one.

### Before going live

1. **Production settings:** in `backend/.env`, set `APP_ENV=production` and `APP_DEBUG=false`.
   Debug mode shows error details, including settings, to anyone.
2. **The exact address:** set `APP_URL` to the API's real address, with `https://`. The app
   refuses requests made to any other address.
3. **Your own admin login:** put `ADMIN_EMAIL` and `ADMIN_PASSWORD` (at least 10 characters) in
   `.env`, then run `php artisan db:seed --class=AdminUserSeeder --force` and
   `php artisan db:seed --class=UnitSeeder --force`. Remove both lines from `.env` afterwards.
   The seeder refuses the example login on a live server.
4. **Behind a proxy?** If the host puts its own front server before the app (Render, Railway,
   Cloudflare and the like), set `TRUSTED_PROXIES=*`, so rate limits count each visitor's own IP
   address.
5. **HTTPS** for both the app and the API.
6. **Lasting storage for photos.** They are saved in `backend/storage/app/public`, and hosts that
   wipe their disk on restart will lose them.
7. **A daily database backup.** It holds the shop's bills, payments and dues.
8. **Optional:** run `php artisan schedule:run` every minute from cron. It clears expired logins
   out of the database each day; they're refused either way.

## API overview

All admin routes are under `/api/admin` and need an `Authorization: Bearer <token>` header with
the token from login. Every route is rate limited (see [Rate limits](#rate-limits)).

| Area           | Routes                                                                                                                                                          |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Login          | `POST /login`, `GET /me`, `POST /logout`, `PUT /password`                                                                                                       |
| Bills          | `GET, POST /bills`, `GET, PUT /bills/{bill}`, `GET /bills/{bill}/pdf`, `POST /bills/{bill}/cancel`, `POST /bills/{bill}/payments`, `POST /bills/{bill}/returns` |
| Customers      | `GET /customers`, `GET /customers/lookup`, `GET /customers/{customer}`, `POST /customers/{customer}/payments`                                                   |
| Products       | `GET, POST /products`, `GET, PUT, DELETE /products/{product}`, `GET /products/options`, `GET /products/{product}/stock-history`                                 |
| Product photos | `POST /products/{product}/images`, `PUT /products/{product}/images/order`, `DELETE /products/{product}/images/{image}`                                          |
| Excel import   | `GET /products/import/template`, `POST /products/import/check`, `POST /products/import`                                                                         |
| Stock          | `GET, POST /stock-entries`, `GET, DELETE /stock-entries/{stock_entry}`, `GET /stock-entries/suppliers`, `POST /product-variants/{variant}/stock-corrections`    |
| Categories     | `GET, POST /categories`, `GET, PUT, DELETE /categories/{category}`                                                                                              |
| Brands         | `GET, POST /brands`, `GET, PUT, DELETE /brands/{brand}`                                                                                                         |
| Dashboard      | `GET /dashboard/summary`                                                                                                                                        |
| Reports        | `GET /reports/sales`                                                                                                                                            |
| Activity log   | `GET /activity-logs`, `GET /activity-logs/options`                                                                                                              |

One public route needs no login: `GET /bill/{token}` returns a bill's PDF from the private link
sent on WhatsApp. The token is 40 random characters.

### Rate limits

Every route has a limit, so a mistake, a stolen login or someone guessing passwords can't
overload a small server. Searches in the app wait for typing to pause, so normal use stays far
below these. Past a limit the API answers `429 Too Many Requests` with a message such as
"Too many requests. Please wait 1 minute and try again.", which the app shows as it is.

| What                                   | Limit                                                                                     |
| -------------------------------------- | ----------------------------------------------------------------------------------------- |
| Every admin request                    | 60 a minute per admin                                                                     |
| Excel import (template, check, import) | 5 a minute per admin, on top of the above                                                 |
| Product photo uploads                  | 10 a minute per admin, on top of the above                                                |
| Bill PDFs                              | 10 a minute per admin, on top of the above                                                |
| Sales report                           | 20 a minute per admin, on top of the above                                                |
| Changing the password                  | 5 a minute per admin, on top of the above                                                 |
| Login                                  | 5 a minute and 20 an hour from one IP address, and 30 an hour for one email from anywhere |
| The WhatsApp bill link                 | 10 a minute from one IP address                                                           |

The numbers are in `backend/config/shop.php` (`rate_limits`), and the limiters in
`AppServiceProvider`.
