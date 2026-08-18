# Laravel + Livewire + Tailwind + Neon on Vercel

A production-ready Laravel 13 application with **Livewire 4**, **Tailwind CSS 4** (via Vite), and a **Neon serverless Postgres** database, deployed to Vercel using **Vercel's official Docker container model** (FrankenPHP).

The demo app is a simple Notes CRUD — create, toggle complete, and delete notes — proving the whole stack works end to end: Livewire's AJAX round-trips, Tailwind styling, and rows persisted in Postgres.

## Stack

| Piece        | Choice                                                              |
| ------------ | ------------------------------------------------------------------- |
| Framework    | Laravel 13 (PHP 8.3+)                                               |
| Frontend     | Livewire 4 + Tailwind CSS 4 + Vite                                  |
| Database     | Neon (serverless PostgreSQL), connected via `DATABASE_URL`          |
| Runtime      | FrankenPHP (Caddy + PHP) inside a Docker container                  |
| Hosting      | Vercel Container Registry + Fluid compute (scales to zero)          |

Deployment follows the official Vercel guide:
[Deploy PHP on Vercel with Docker](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker).

## Local development

Prerequisites: PHP 8.3+, Composer 2, Node.js 22+.

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Set your Neon connection string in `.env` (Neon dashboard → Connection Details → **Pooled connection**):

```
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://user:password@host/dbname?sslmode=require
DB_SSLMODE=require
```

Then migrate and run:

```bash
php artisan migrate
npm run dev        # terminal 1 — Vite dev server
php artisan serve  # terminal 2 — http://localhost:8000
```

Run the test suite (uses an in-memory SQLite database):

```bash
php artisan test
```

## How the Vercel deployment works

These files implement the official container model:

- **`Dockerfile.vercel`** — three-stage build:
  1. `vendor` — production Composer dependencies (`--no-dev`)
  2. `assets` — Vite/Tailwind production build
  3. `runtime` — FrankenPHP with `pdo_pgsql`/`pgsql`, running as a non-root user
- **`Caddyfile`** — serves `/app/public` and binds FrankenPHP to Vercel's `$PORT`
- **`entrypoint.sh`** — runs `php artisan migrate --force` (with retries for Neon cold starts), rebuilds Laravel's caches with the *runtime* env (`php artisan optimize`), then starts FrankenPHP
- **`vercel.json`** — declares the `container` service and a catch-all rewrite to it
- **`.dockerignore`** — keeps `.env`, local caches, and build artifacts out of the image

Two deliberate decisions:

1. **No `config:cache` at build time.** Vercel injects environment variables at container runtime, so config is cached on boot inside the entrypoint instead. Route/view/event caches are safe to build at image build time.
2. **Migrations run on container boot.** Guarded by Laravel's migrations table, so they're idempotent on every cold start. For high-traffic apps, prefer running them in CI and skipping them at boot via an env flag.

> ⚠️ **Ephemeral filesystem.** Containers scale to zero, so anything written to disk is lost. This app stores sessions, cache, and queues in the database (default Laravel 13 config), which works great with Neon. For file uploads use [Vercel Blob](https://vercel.com/docs/vercel-blob); for high-frequency caching use a [Marketplace Redis integration](https://vercel.com/marketplace). Keep DB connections conservative — each container instance opens its own.

## Deploying to Vercel

### Option A — Git + dashboard

1. Push this repository to GitHub.
2. In Vercel, **Add New → Project** and import the repo.
   Vercel detects `vercel.json` and builds with `Dockerfile.vercel` automatically.
3. Add the **Neon** integration (Marketplace → Neon Postgres). It creates a database
   and injects `DATABASE_URL` into the project automatically.
4. Add the remaining environment variables (Production, Preview, and Development):
   ```
   APP_KEY=<run: php artisan key:generate --show>
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-project.vercel.app
   ```
5. Deploy. The first request to a cold instance runs migrations automatically.

> Full environment-variable reference (manual vars + everything the Neon
> integration injects) is in [`DEPLOYMENT.md`](DEPLOYMENT.md).

### Option B — Vercel CLI

```bash
npm i -g vercel
vercel login

# Link the project, then add env vars for each environment:
vercel env add DATABASE_URL production
vercel env add APP_KEY production      # value: php artisan key:generate --show
vercel env add APP_ENV production      # production
vercel env add APP_DEBUG production    # false

vercel deploy --prod
```

For local container previews against the real Vercel env:

```bash
vercel dev -L
```

### Custom domain (Cloudflare DNS)

The production URL is **https://v1.api.khareluttam.com.np** (with
`www.v1.api.khareluttam.com.np` redirecting to it). Both hostnames are already
registered on the Vercel project and `APP_URL` points at the domain.

Point DNS at Vercel from the Cloudflare dashboard (zone `khareluttam.com.np`):

| Type  | Name          | Target                 | Proxy  |
| ----- | ------------- | ---------------------- | ------ |
| CNAME | `v1.api`      | `cname.vercel-dns.com` | ON     |
| CNAME | `www.v1.api`  | `cname.vercel-dns.com` | ON     |

Vercel issues and terminates HTTPS automatically at the edge (and Cloudflare
proxies over it), so both hostnames serve over TLS once DNS propagates.

### Testing the container locally

```bash
docker build -f Dockerfile.vercel -t laravel-vercel .

docker run --rm -p 8080:8080 \
  -e PORT=8080 \
  -e APP_KEY=$(php artisan key:generate --show) \
  -e APP_ENV=production \
  -e APP_DEBUG=false \
  -e DATABASE_URL="$YOUR_NEON_CONNECTION_STRING" \
  -e DB_SSLMODE=require \
  laravel-vercel
# → http://localhost:8080
```

## Branch strategy & CI/CD

Two branches, one rule: **only `production` deploys to Vercel.** `main` is for
development; `production` is the deploy source. Deployment runs through the
GitHub Actions workflow, which only triggers on pushes to `production`.

> If you later connect the repo to Vercel's native Git integration, set
> **Project → Settings → Git → Production Branch** to `production` so it
> doesn't auto-deploy from `main`.

Workflows:

- **`.github/workflows/ci.yml`** — runs on push/PR to `main` and `production`: PHP 8.3 + Node 22,
  `composer install`, `npm ci`, `npm run build`, `php artisan test` (in-memory
  SQLite), then builds the `Dockerfile.vercel` image as a smoke test.
- **`.github/workflows/deploy.yml`** — deploys to Vercel production with the Vercel CLI,
  but **only on push to `production`**. It uploads the source and lets Vercel
  build the container remotely (no Docker needed in CI).

To enable the deploy workflow, add these repository secrets
(**Settings → Secrets and variables → Actions**):

| Secret               | Value                                                                  |
| -------------------- | ---------------------------------------------------------------------- |
| `VERCEL_TOKEN`       | Create at vercel.com/account/tokens (or copy from `~/.local/share/com.vercel.cli/auth.json`) |
| `VERCEL_ORG_ID`      | `team_...` from `.vercel/project.json`                                 |
| `VERCEL_PROJECT_ID`  | `prj_...` from `.vercel/project.json`                                  |

> Prefer the simpler route? Connect the repo to Vercel from the dashboard
> (**Project → Settings → Git**) and Vercel deploys every push automatically —
> then you can delete `deploy.yml` to avoid double deploys.

## Troubleshooting

- **502 Bad Gateway** — Caddy isn't listening on Vercel's port. Keep `:{$PORT:80}` in the `Caddyfile`; don't hardcode a port.
- **404 on everything** — the document root isn't pointing at `public`. Keep `root * /app/public` + `php_server`.
- **`could not find driver (pgsql)`** — the image is missing `pdo_pgsql`; it's installed via `install-php-extensions pdo_pgsql pgsql` in `Dockerfile.vercel`.
- **Stale code after deploy** — framework caches regenerate on boot (`php artisan optimize`), and `.dockerignore` keeps local `bootstrap/cache` out of the image, so a fresh deploy always boots clean.
