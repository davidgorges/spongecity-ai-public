# Deploying with Laravel Forge

The app runs on a plain PHP server: no Node, no database server. State lives in SQLite and a cache
pool, both under the kernel's share dir (`var/share/<env>`, set by `APP_SHARE_DIR`).

## Site settings

| Setting | Value |
|---|---|
| Root directory | `/` |
| Web directory | `/public` |
| PHP version | 8.4 or later |
| Frontend package manager | None (AssetMapper and the Tailwind bundle need no Node) |
| Zero downtime deployments | On |
| Shared paths | `var/share` |

`var/share` holds everything that must survive a release: the SQLite database (the Messenger
queues, see `DATABASE_URL`) and the plan cache pool. `var/` itself is committed (with only a
`.gitkeep`) so Forge has a parent directory to put the symlink in. Never commit `var/share`.
`App\Kernel::boot()` creates the share dir when it is missing, because SQLite cannot.

## Environment

Forge's environment editor writes `.env`, which replaces the committed defaults. Either paste the
whole committed `.env` and change the values below, or share `.env.local` as well and put them
there.

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=        # php -r 'echo bin2hex(random_bytes(16));'
DEFAULT_URI=https://your-domain.tld/
ANTHROPIC_API_KEY=
OSM_USER_AGENT="SpongeCityAI/0.1 (contact: you@your-domain.tld)"
```

## Deploy script

After Forge's own clone and activate lines:

```bash
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
$FORGE_PHP bin/console tailwind:build --minify
$FORGE_PHP bin/console asset-map:compile
$FORGE_PHP bin/console cache:clear
$FORGE_PHP bin/console messenger:setup-transports
$FORGE_PHP bin/console messenger:stop-workers
```

`messenger:stop-workers` makes Supervisor restart the workers on the new release.

## Daemons

One per Messenger transport, both with the directory `/home/forge/<site>/current`:

```
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
php bin/console messenger:consume simstadt --time-limit=3600 --limit=1
```

## Server

- SimStadt runs in Docker (`SIMSTADT_IMAGE`, `linux/amd64`). Install Docker and run
  `sudo usermod -aG docker forge`. Pick an x86 server; amd64 emulation on ARM is slow. Without
  Docker everything but the SimStadt building questions still works.
- After the first deploy, run `php bin/console app:doctor` in `current`.
