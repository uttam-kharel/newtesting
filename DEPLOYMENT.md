# From Scratch: Laravel + Livewire + Tailwind + Neon on Vercel (free)

A complete, step-by-step guide to reproducing this project end to end: a Laravel 13
app with Livewire 4 and Tailwind CSS 4, backed by a free Neon Postgres database,
deployed to Vercel using Vercel's official Docker container model (FrankenPHP),
with GitHub Actions CI/CD, a two-branch workflow, and a custom domain served over
HTTPS from Cloudflare DNS.

Everything below is the exact path this repository took — including the gotchas
that cost the most time, so you can skip them.

---

## 1. What you'll end up with

```
Browser (HTTPS)
   │  https://v1.api.khareluttam.com.np   (www redirects to it)
   ▼
Cloudflare DNS  ── CNAME ──►  cname.vercel-dns.com
                                   ▼
                        Vercel edge (TLS termination)
                                   ▼
                 Vercel Fluid compute container (FrankenPHP + Caddy)
                                   │  php artisan migrate + optimize on boot
                                   ▼
                        Neon serverless Postgres (free tier)
```

Pieces:

| Layer       | Choice |
| ----------- | ------ |
| App         | Laravel 13 (PHP 8.3+) |
| Frontend    | Livewire 4, Tailwind CSS 4 (Vite) |
| Database    | Neon Postgres, free, provisioned through Vercel |
| Runtime     | FrankenPHP (Caddy + PHP) in a Docker container |
| Hosting     | Vercel Container Registry + Fluid compute (scales to zero) |
| CI/CD       | GitHub Actions (tests + deploy, production branch only) |
| DNS + TLS   | Cloudflare CNAME → Vercel, HTTPS at the edge |

---

## 2. Prerequisites

Local machine:

- PHP 8.3+ (`php -v`)
- Composer 2 (`composer --version`)
- Node.js 22+ (`node -v`)
- Git
- Docker (only needed to test the container locally — optional for deploying)

Accounts:

- GitHub (repo)
- Vercel (free Hobby plan — containers are supported)
- Cloudflare (DNS zone for your domain)
- Neon — **not required**: the Vercel integration creates the database for you

---

## 3. Create the Laravel app

```bash
composer create-project laravel/laravel my-app
cd my-app
php artisan --version   # Laravel Framework 13.x
```

The Laravel 13 skeleton already ships **Tailwind CSS 4 + Vite** (`package.json`
contains `tailwindcss` and `@tailwindcss/vite`), so nothing to install there.

Install Livewire:

```bash
composer require livewire/livewire    # Livewire 4.x
npm install                           # generates package-lock.json (needed by npm ci later)
```

> **Gotcha — Livewire 4 layout location.** Livewire 4's default layout is
> `layouts::app`, i.e. `resources/views/layouts/app.blade.php`. (Livewire 3 used
> `components/layouts/app.blade.php` — if you use the v3 location you get
> `No hint path defined for [layouts]`.) The layout must contain `@livewireStyles`
> in `<head>` and `@livewireScripts` before `</body>`, plus your Vite tags:

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
```

## 4. Build a demo feature (Notes app)

A small CRUD proves the whole stack works end to end.

`database/migrations/2026_08_18_000000_create_notes_table.php`:

```php
Schema::create('notes', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body')->nullable();
    $table->boolean('completed')->default(false);
    $table->timestamps();
});
```

`app/Models/Note.php` — `fillable = ['title', 'body', 'completed']`, cast
`completed` to boolean.

`app/Livewire/Notes.php` — a `Livewire\Component` with `save()`, `toggle(Note)`,
`delete(Note)` actions, validation, and `render()` passing `Note::latest()->get()`.

`resources/views/livewire/notes.blade.php` — Tailwind-styled form + list with
`wire:submit="save"`, `wire:model="title"`, `wire:click="toggle(...)"`,
`wire:confirm="Delete this note?"`.

`routes/web.php`:

```php
Route::get('/', \App\Livewire\Notes::class);
```

Add `tests/Feature/NotesTest.php` covering create, validation, toggle, delete
(uses `RefreshDatabase`; `phpunit.xml` already points at in-memory SQLite).

Verify locally before going further:

```bash
npm run build
php artisan test     # all green
```

## 5. Wire up Neon Postgres

Laravel 13's `config/database.php` reads `env('DB_URL')` for the Postgres
connection string — but the Vercel Neon integration (and Neon itself) provides
`DATABASE_URL`. Map both, and make Postgres the default whenever a URL exists:

```php
// config/database.php
'default' => env('DB_CONNECTION', env('DATABASE_URL') ? 'pgsql' : 'sqlite'),

'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('DB_URL', env('DATABASE_URL')),
    // ...rest unchanged
],
```

> **Gotcha — migrations "succeed" but the database stays empty.** This bit us in
> production. `DB_CONNECTION` was unset in the container, so Laravel defaulted to
> **SQLite** and ran migrations against a throwaway file in the ephemeral
> container disk. Migrations reported success while Neon had zero tables. The
> auto-select above fixes it; also set `DB_CONNECTION=pgsql` explicitly in your
> Vercel environment variables (see §10).

Update `.env.example` for local dev:

```
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://user:password@host/dbname?sslmode=require
DB_SSLMODE=require
```

## 6. The official Vercel Docker deployment files

This follows Vercel's own guide:
[Deploy PHP on Vercel with Docker](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker).

### `Dockerfile.vercel` — three stages

```dockerfile
# Stage 1 — PHP dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: Laravel's package:discover needs the full app bootstrapped,
# so run it in the runtime stage instead.
RUN composer install --no-dev --no-interaction --no-progress \
    --no-scripts --prefer-dist --optimize-autoloader

# Stage 2 — frontend assets
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# Stage 3 — runtime (FrankenPHP)
FROM dunglas/frankenphp:1-php8.4
WORKDIR /app
RUN install-php-extensions pdo_pgsql pgsql   # PostgreSQL driver for Neon

ARG USER=appuser
RUN useradd ${USER} \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R ${USER}:${USER} /config/caddy /data/caddy

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# Env-independent caches only. Config is NOT cached here: Vercel injects env
# vars at runtime, so caches are rebuilt on boot.
RUN mkdir -p /app/bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan view:cache \
    && php artisan event:cache

RUN chown -R ${USER}:${USER} /app/storage /app/bootstrap/cache
USER ${USER}
ENV PORT=80
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
```

> **Gotcha — stale local caches in the image.** If `bootstrap/cache/*.php`
> (from your local dev install) is COPYed into the image, `php artisan
> package:discover` crashes with `Class "Laravel\Pail\PailServiceProvider" not
> found` (Pail is a dev-only dependency). Exclude those files in `.dockerignore`.

### `Caddyfile`

```
:{$PORT:80} {
	root * /app/public
	encode zstd gzip
	php_server
}
```

`$PORT` is Vercel's container port. `php_server` falls back to `index.php`, so
Laravel's router handles everything. Keep the `{$PORT}` placeholder — a
hardcoded port causes 502s.

### `entrypoint.sh`

```sh
#!/bin/sh
set -e

# Migrations are idempotent (Laravel's migrations table), safe on every cold
# start. Retries give serverless Neon time to wake up.
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 30 ]; then
        echo "[entrypoint] Migrations failed after ${attempt} attempts." >&2
        exit 1
    fi
    echo "[entrypoint] Database not ready (attempt ${attempt}/30), retrying in 2s..." >&2
    attempt=$((attempt + 1))
    sleep 2
done

# Rebuild caches with the *runtime* environment (config is not cached at build).
php artisan optimize

exec "$@"
```

### `vercel.json`

```json
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "services": {
    "api": {
      "root": ".",
      "entrypoint": "Dockerfile.vercel",
      "runtime": "container"
    }
  },
  "rewrites": [
    { "source": "/(.*)", "destination": { "service": "api" } }
  ]
}
```

### `.dockerignore`

```gitignore
.git
.vercel
.env
.env.*
!.env.example
node_modules
vendor
public/build
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/testing/*
storage/framework/views/*
database/database.sqlite
bootstrap/cache/*.php
```

### Test the container locally

```bash
docker build -f Dockerfile.vercel -t laravel-vercel .

docker run --rm -p 8080:8080 \
  -e PORT=8080 \
  -e APP_KEY=$(php artisan key:generate --show) \
  -e APP_ENV=production -e APP_DEBUG=false \
  -e DATABASE_URL="$YOUR_NEON_CONNECTION_STRING" \
  laravel-vercel
# open http://localhost:8080
```

## 7. Push to GitHub with a two-branch workflow

Two branches, one rule: **only `production` deploys.** `main` is for development.

```bash
git init
git remote add origin https://github.com/<you>/<repo>.git
git add -A
git commit -m "Initial commit"
git push -u origin main
git checkout -b production
git push -u origin production
git checkout main
```

Keep `production` in sync with `main`:

```bash
git checkout production && git merge main --no-edit && git push origin production && git checkout main
```

Make sure `.env`, `database/database.sqlite`, `bootstrap/cache/*.php`,
`node_modules`, `vendor`, and `.vercel` are gitignored.

## 8. GitHub Actions CI/CD

### `.github/workflows/ci.yml` — tests on every push/PR

Runs on `main` and `production`: PHP 8.3 + Node 22 → `composer install` →
`npm ci` → `npm run build` → `php artisan test` (in-memory SQLite) → Docker
build of `Dockerfile.vercel` as a smoke test.

### `.github/workflows/deploy.yml` — deploy, production branch only

```yaml
name: Deploy to Vercel
on:
  push:
    branches: [production]
  workflow_dispatch:
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - name: Deploy to Vercel
        env:
          VERCEL_TOKEN: ${{ secrets.VERCEL_TOKEN }}
          VERCEL_ORG_ID: ${{ secrets.VERCEL_ORG_ID }}
          VERCEL_PROJECT_ID: ${{ secrets.VERCEL_PROJECT_ID }}
        run: npx vercel deploy --prod --yes --token="$VERCEL_TOKEN"
```

Vercel builds the container remotely — no Docker needed in CI.

Add these repository secrets (**Settings → Secrets and variables → Actions**):

| Secret              | Value                                        |
| ------------------- | -------------------------------------------- |
| `VERCEL_TOKEN`      | vercel.com/account/tokens                    |
| `VERCEL_ORG_ID`     | `team_...` from `.vercel/project.json`       |
| `VERCEL_PROJECT_ID` | `prj_...` from `.vercel/project.json`        |

> Alternative: connect the repo to Vercel natively (Project → Settings → Git)
> and set **Production Branch = production**. Then delete `deploy.yml` to avoid
> double deploys.

## 9. Deploy to Vercel (first time)

```bash
npm install -D vercel          # project-scoped CLI
npx vercel login               # prints a device-code URL — open it and authorize
npx vercel link --yes --project laravel-neon-vercel   # or create via dashboard
```

Set environment variables for production (repeat for preview/development):

```bash
npx vercel env add APP_KEY production        # value: php artisan key:generate --show
npx vercel env add APP_ENV production        # production
npx vercel env add APP_DEBUG production      # false
npx vercel env add APP_URL production        # https://your-domain.com
npx vercel env add DB_CONNECTION production  # pgsql   ← critical, see §5
```

> `vercel env add` refuses to overwrite — `vercel env rm NAME production --yes`
> first. `env add` for the preview environment prompts for a Git branch and
> ignores piped input; set it interactively or skip (previews use their own URLs).

Deploy:

```bash
npx vercel deploy --prod --yes
```

The first deploy takes ~2 minutes (Docker build on Vercel's side). The container
boot runs migrations against Neon automatically.

> **Gotcha — `*.vercel.app` URLs are login-protected by default** (Vercel
> deployment protection returns a 302 to SSO). Custom domains are **not**
> protected, so your real URL is public.

## 10. Create the free Neon database via Vercel

No Neon account needed — the marketplace integration provisions everything:

```bash
npx vercel integration add neon
```

Output: `Success! Neon successfully provisioned: <project-name>` and
`connected to <your-vercel-project>`. It injects into all three environments:

- `DATABASE_URL` (pooled, `sslmode=require`)
- `DATABASE_URL_UNPOOLED`, `POSTGRES_DATABASE`, `PGDATABASE`

Verify:

```bash
npx vercel env ls            # DATABASE_URL present
psql "$DATABASE_URL" -c "\dt"   # after first deploy: tables exist
```

Redeploy once the env vars are in place:

```bash
npx vercel deploy --prod --yes
```

Confirm in the deployment logs (`npx vercel logs <deploy-url>`) that migrations
ran, and check the tables landed in Neon (`notes`, `users`, `sessions`, `cache`,
`jobs`, `migrations`).

### Environment variables — the complete reference

#### Manually set on Vercel (`vercel env add` / dashboard)

Add these to **Production, Preview, and Development**:

| Variable | Example value | Purpose |
| -------- | ------------- | ------- |
| `APP_KEY` | `base64:...` | Laravel encryption key — generate with `php artisan key:generate --show`. Required for sessions, cookies, signed URLs. |
| `APP_ENV` | `production` | Environment name. |
| `APP_DEBUG` | `false` | Never `true` in production. |
| `APP_URL` | `https://v1.api.example.com.np` | Canonical base URL for URL generation. |
| `DB_CONNECTION` | `pgsql` | Forces Postgres. Without it Laravel silently falls back to SQLite (see the §5 gotcha). |

#### Auto-injected by the Neon integration (all three environments)

`npx vercel integration add neon` provisions the database and sets these.
**The app only reads `DATABASE_URL`** — the rest exists for other frameworks
and tools:

| Variable | Purpose |
| -------- | ------- |
| `DATABASE_URL` | Pooled connection string (`...-pooler...?sslmode=require`) — **what Laravel uses** |
| `DATABASE_URL_UNPOOLED` | Direct (non-pooled) connection string |
| `POSTGRES_URL`, `POSTGRES_URL_NON_POOLING`, `POSTGRES_URL_NO_SSL`, `POSTGRES_PRISMA_URL` | Same database in other forms (Prisma, non-SSL, …) |
| `PGHOST`, `PGHOST_UNPOOLED`, `PGUSER`, `PGPASSWORD`, `PGDATABASE` | libpq-style component variables |
| `POSTGRES_HOST`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DATABASE` | Component variables (framework-agnostic) |
| `NEON_PROJECT_ID` | Neon project identifier |
| `NEON_AUTH_BASE_URL`, `VITE_NEON_AUTH_URL` | Neon Auth preview feature — not used by this app |

#### What the app actually reads

`DATABASE_URL`, `DB_CONNECTION`, `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`.
Sessions, cache, and the queue default to the `database` driver, so they all
share the Neon database.

#### Local development `.env` (`cp .env.example .env`)

```
APP_NAME=Laravel
APP_ENV=local
APP_KEY=            # php artisan key:generate
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://user:password@host/dbname?sslmode=require
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

> `.env` is never copied into the Docker image (see `.dockerignore`). Every
> value reaches the app through Vercel's environment variables at container
> runtime, and the entrypoint rebuilds the config cache on boot
> (`php artisan optimize`) so the runtime values always win.

## 11. Custom domain + Cloudflare DNS + HTTPS

### Register the hostnames on Vercel

Dashboard: **Project → Settings → Domains → Add** — or via API:

```bash
# apex + www (www redirects to apex)
curl -X POST "https://api.vercel.com/v10/projects/<projectId>/domains" \
  -H "Authorization: Bearer $VERCEL_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"v1.api.example.com.np"}'
curl -X POST "https://api.vercel.com/v10/projects/<projectId>/domains" \
  -H "Authorization: Bearer $VERCEL_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"www.v1.api.example.com.np","redirect":"v1.api.example.com.np"}'
```

Update `APP_URL` to `https://v1.api.example.com.np` (rm then add).

### Point Cloudflare at Vercel

In Cloudflare (zone `example.com.np`) → **DNS → Records → Add record**:

| Type  | Name         | Content (Target)       | Proxy | TTL  |
| ----- | ------------ | ---------------------- | ----- | ---- |
| CNAME | `v1.api`     | `cname.vercel-dns.com` | ON ☁️  | Auto |
| CNAME | `www.v1.api` | `cname.vercel-dns.com` | ON ☁️  | Auto |

Notes:

- The **Name** field is the subdomain only — Cloudflare appends the zone.
- **Proxied (orange cloud)** keeps Cloudflare's edge in front; Vercel terminates
  TLS at its own edge, so HTTPS works with zero certificate setup.
- No TXT records or nameserver changes needed.
- `www.v1.api...` 301-redirects to the apex automatically (set on Vercel).

Propagation takes minutes. Verify with `nslookup v1.api.example.com.np` and by
opening `https://v1.api.example.com.np`.

## 12. Troubleshooting (the ones that actually happened)

| Symptom | Cause | Fix |
| ------- | ----- | --- |
| 502 Bad Gateway | Caddy not on Vercel's port | Keep `:{$PORT:80}` in the Caddyfile |
| `No hint path defined for [layouts]` | Livewire 4 layout location | Use `resources/views/layouts/app.blade.php` |
| `Class "Laravel\Pail\PailServiceProvider" not found` at build | Stale local `bootstrap/cache/*.php` copied into image | Exclude in `.dockerignore` |
| Migrations "succeed" but Neon is empty | `DB_CONNECTION` unset → Laravel used SQLite in ephemeral disk | Set `DB_CONNECTION=pgsql`; auto-select pgsql when `DATABASE_URL` exists |
| 302 to vercel.com/sso-api | Vercel deployment protection on `*.vercel.app` | Expected; custom domain is public |
| `vercel env add` "already exists" | Env vars can't be overwritten | `vercel env rm NAME <env> --yes` first |
| `vercel env add ... preview` hangs on a branch prompt | CLI expects interactive input | Add interactively or skip (previews use their own URLs) |

## 13. Final checklist

- [ ] `php artisan test` green locally
- [ ] Container builds: `docker build -f Dockerfile.vercel .`
- [ ] Two branches pushed: `main` + `production`
- [ ] `deploy.yml` triggers only on `production`
- [ ] CI runs on both branches
- [ ] Vercel project linked, env vars set (`APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `DB_CONNECTION`)
- [ ] Neon integration installed, `DATABASE_URL` injected
- [ ] Production deploy Ready; Neon shows `notes`, `users`, `sessions`, ...
- [ ] Domains added on Vercel, `APP_URL` updated
- [ ] Cloudflare CNAMEs added and `https://v1.api.example.com.np` loads
- [ ] Repo secrets added so `production` auto-deploys
