# KITA on Render (Docker + hosted MySQL)

For OTP on Render Free, use the included Resend HTTPS mail driver instead of
SMTP. See [OTP_EMAIL.md](OTP_EMAIL.md) for the required API key, sender verification,
Render environment variables and restricted test-recipient setup. The SMTP
settings below apply only when your hosting plan supports outbound SMTP.

## Inspected requirements

- `composer.json` requires PHP `^8.2` and Laravel `^12.0`; `composer.lock` locks Laravel **12.67.0**. The image uses **PHP 8.3 FPM**, within all locked production constraints. It does not update Composer packages.
- The image compiles `mbstring`, `dom`, `pdo_mysql`, and OPcache. The official PHP base provides the other locked requirements (ctype, fileinfo, filter, hash, iconv, JSON, libxml, OpenSSL, PCRE, session, tokenizer). The Composer stage checks actual platform requirements. No GD, BCMath, Redis, or intl extension is required by the inspected code/lockfile.
- `package.json` uses npm, Vite 7, Laravel Vite plugin 2 and Tailwind 4; no Bootstrap package. There was no `package-lock.json`. A lockfile is now supplied, with Vite 7.3.6. **Node 22** satisfies the Vite/plugin `^20.19.0 || >=22.12.0` engine requirement. Local asset verification used Node 22.23.3. Docker uses the maintained `node:22-bookworm-slim` tag.
- KITA's actual page loads `public/app.js`, `support.js`, `styles.css` and images. These are retained. The scaffold's Vite assets are also built; Tailwind's vendor pagination scan is included.
- Sessions/cache default to database; the PIN limiter explicitly defaults to file to keep failed PIN attempts outside rolled-back business transactions. No queued jobs or scheduled tasks were found; OTP mail is synchronous. Use `QUEUE_CONNECTION=sync` for this single web service.
- Public/local filesystem roots remain `storage/app/public` and `storage/app/private`. Existing `public/uploads` assets are retained. No runtime upload writer was found in the inspected application.
- `/up` already provides a Laravel health check without sessions or database access. No duplicate `/health` route was needed.
- No PWA manifest, service worker registration, or RFID integration/configuration was found in this checkout. Docker cannot restore functionality from another version of the application. Static/PWA file types are served without long-lived caching; the UI has not been rewritten.

## Files

| File | Purpose |
| --- | --- |
| `Dockerfile` | PHP base, production Composer install, npm production build, Nginx/FPM runtime; no Node/Composer/dev vendor packages in final image. |
| `.dockerignore` | Excludes local environment files, credentials, database dumps, dependency folders, test artifacts, logs and stale caches. |
| `docker/nginx.conf` | Public-only web root, Laravel routing, FPM forwarding, static caching rules and dynamic port template. |
| `docker/start.sh` | Checks APP_KEY/PORT, prepares writable directories, creates storage link, caches Laravel configuration/routes/views, supervises both servers and handles signals. |
| `docker/php.ini` | Production errors, memory/upload limits and OPcache. |
| `docker/php-fpm.conf` | Loopback-only FPM, environment inheritance, stderr logging and bounded worker pool. |
| `config/trustedproxy.php` | Opt-in Render proxy trust, compatible with Laravel config caching. |
| `package-lock.json` | Reproducible npm dependency installation. |
| `tests/Feature/DeploymentTest.php` | Database-independent health check and trusted/untrusted HTTPS forwarding tests. |
| `docs/RENDER_DEPLOYMENT.md` | This deployment and verification guide. |

The only existing source file changed is `bootstrap/app.php`: restrict trusted forwarding headers to client IP and protocol. Proxy addresses are trusted only when `TRUST_RENDER_PROXY=true`. Local `.env`, business logic, schemas and UI remain unchanged.

## Render service settings

| Setting | Value |
| --- | --- |
| Service type | Web Service |
| Repository | `https://github.com/Ravenn-16/kitaapp.git` |
| Runtime | Docker |
| Branch | **master** (the repository's actual branch, not `main`) |
| Region | Singapore for Philippines users if your hosted MySQL is also nearby; otherwise choose the same/nearest region as MySQL. |
| Dockerfile path | `./Dockerfile` |
| Docker build context | `.` |
| Health check path | `/up` |
| Docker command | Leave blank; use the image entrypoint. |
| Pre-deploy command | Leave blank initially; review/import the database before opting into migrations. |
| Instance | Paid service for SMTP OTP delivery and persistent disk support if needed. Start with one instance. |

Commit/push these deployment files before deploying that branch. Render supplies `PORT` (normally 10000); startup renders only that variable into Nginx, which listens on `0.0.0.0`. Render terminates HTTPS. Keep `TRUST_RENDER_PROXY=true` only behind that trusted ingress; do not enable it for a publicly exposed local container.

## Render environment variables

Set these in the Render dashboard, not Docker build arguments or committed files. Do not upload the local `.env`.

| Variable | Required? | Value / description |
| --- | --- | --- |
| `APP_NAME` | Yes | `KITA` |
| `APP_ENV` | Yes | `production` |
| `APP_KEY` | Yes | Stable existing application encryption key if migrating encrypted data. For a brand-new installation only, generate once with `php artisan key:generate --show` on a trusted machine, then save privately in Render. Never rotate on startup. |
| `APP_DEBUG` | Yes | `false` |
| `APP_URL` | Yes | `https://your-service.onrender.com`, or your custom HTTPS domain |
| `TRUST_RENDER_PROXY` | Yes on Render | `true` |
| `DB_CONNECTION` | Yes | `mysql` |
| `DB_HOST` | Yes | Hosted MySQL endpoint; not `localhost` or `127.0.0.1` |
| `DB_PORT` | Yes | Provider port, usually `3306` |
| `DB_DATABASE` | Yes | `kita_app` or the imported database name |
| `DB_USERNAME` | Yes | Dedicated application database user |
| `DB_PASSWORD` | Yes | Provider/application user password, kept secret |
| `MYSQL_ATTR_SSL_CA` | Provider dependent | Absolute path such as `/etc/secrets/mysql-ca.pem`, supplied as a Render secret file, for provider-required TLS |
| `LOG_CHANNEL` | Yes | `stderr` (or `stack` with `LOG_STACK=stderr`) |
| `LOG_STACK` | If using stack | `stderr` |
| `LOG_LEVEL` | Yes | `error` |
| `SESSION_DRIVER` | Yes | `database`; imported DB must include compatible `sessions` table |
| `SESSION_SECURE_COOKIE` | Yes on Render | `true` |
| `SESSION_LIFETIME` | Optional | `120` minutes, existing default |
| `SESSION_DOMAIN` | Optional | Leave unset for host-only cookies |
| `CACHE_STORE` | Yes | `database`; requires `cache` and `cache_locks` tables |
| `CACHE_LIMITER` | Yes | `file`; preserves the application's deliberate PIN rollback behavior |
| `QUEUE_CONNECTION` | Yes | `sync`; no separate worker required by current code |
| `FILESYSTEM_DISK` | Yes | `local`, preserving current storage configuration |
| `APP_MAINTENANCE_DRIVER` | Optional | `file` |
| `MAIL_MAILER` | Required for OTP | `smtp` |
| `MAIL_SCHEME` | Mail provider dependent | Leave unset for STARTTLS on 587; `smtps` for implicit TLS on 465 if required |
| `MAIL_HOST` | Required for OTP | Mail provider's SMTP host |
| `MAIL_PORT` | Required for OTP | Provider port, typically `587` |
| `MAIL_USERNAME` | Required for authenticated SMTP | Provider username |
| `MAIL_PASSWORD` | Required for authenticated SMTP | Secret SMTP/app password |
| `MAIL_FROM_ADDRESS` | Required for OTP | Verified sender address; enter the actual address, not `${MAIL_USERNAME}` |
| `MAIL_FROM_NAME` | Optional | `KITA` |
| `MAIL_EHLO_DOMAIN` | Optional | Provider requirement; otherwise derived from APP_URL |
| `PAYMONGO_SECRET_KEY` | Required if payments enabled | Secret provider key |
| `PAYMONGO_PUBLIC_KEY` | Required if payments enabled | Matching provider public key |
| `PAYMONGO_WEBHOOK_SECRET` | Required if payments enabled | Webhook signature secret; register `https://your-domain/api/payments/paymongo/webhook` |
| `RUN_MIGRATIONS` | Optional | `false` (default). Explicit `true` runs only `migrate --force` before startup. Prefer a manual, single migration runner. |
| `PORT` | Render supplies it | Defaults to `10000`; any valid assigned port is accepted |

Standard Laravel scaffolding also exposes locale settings, `BCRYPT_ROUNDS`, `APP_PREVIOUS_KEYS`, alternate session/cache/queue connection overrides, Redis/Memcached and S3 settings, and Postmark/Resend/SES/Slack credentials. Leave unused backend settings unset. Selecting Redis, S3, or alternate mail transports can require extra drivers/packages not present in this image; do not switch them merely by setting variables. No custom RFID environment variables were found. `VITE_APP_NAME` is not consumed by the current frontend and is not a runtime secret mechanism.

Do not set `DB_URL` or `DB_SOCKET` unless intentional: they can override/interfere with the listed MySQL connection values. Do not set `ASSET_URL` to a local development address.

## Hosted MySQL and migrations

1. Back up the current database and test restoring it. Use a staging copy of the hosted MySQL database first. Preserve the `migrations` history if importing an existing Laravel database.
2. Provision the database near the Render service. Configure the database provider's firewall to permit the Render service's outbound addresses and use its TLS/CA requirements. Use a dedicated least-privilege application user; migrations require schema privileges and can use a separately controlled account.
3. Import the existing KITA schema/data through a secure provider tool or MySQL client. Do not initialize an empty database over an imported one. Inspect migration history against actual schema before running any pending migration; do not blindly mark mismatched migrations as applied.
4. Add the DB variables above to Render. Keep the old APP_KEY if encrypted values must remain readable. Set `RUN_MIGRATIONS=false` initially.
5. From the deployed service's shell, check the connection and pending migrations:

   ```sh
   php artisan migrate:status
   ```

6. Review all pending migrations on the staging copy first, then take a fresh production backup and schedule a maintenance window. Existing migrations change users' primary keys, require unique/nonempty user emails, and adjust purchase IDs and relationships. `--force` suppresses the prompt; it does not guarantee a migration is harmless or reversible.
7. Run exactly one approved migration process from the service shell:

   ```sh
   php artisan migrate --force
   php artisan migrate:status
   ```

8. Verify login/OTP, role permissions, inventory, purchasing/receiving, payments/webhooks, reports and printing against staging, then production. `/up` confirms PHP/Laravel boot only, not database or mail availability.

There is no automatic seeding, `migrate:fresh`, `db:wipe`, reset or key generation. Leave `RUN_MIGRATIONS=false` for routine deploys. The sale entry method migration (`2026_11_04_000003_record_sale_entry_method.php`) is a scoped exception: startup applies it before serving requests because checkout writes its new columns. Only use the general migration opt-in on a single instance after review. For first-time account provisioning, the existing interactive `php artisan accounts:superadmin your-email@example.com` command is available; imported databases should retain their existing administrator.

## Storage and scaling

The image excludes all local storage data and generates `public/storage` at startup. Runtime writes to `storage` and `bootstrap/cache` belong to `www-data`; application source stays root-owned. No `chmod 777` is used.

Render's default filesystem is ephemeral. If persistent local files are required, attach a paid persistent disk at `/var/www/html/storage`, then securely copy existing `storage/app/public` and private uploads into the appropriate directories. This also retains the file PIN limiter across deploys. Do not mount an empty disk over `public` or the project root: it would hide application assets. Existing versioned `public/uploads` are copied into the image; any future runtime writes there need their own persistence solution.

Without a persistent disk, PIN limiter state resets on restart, and local sessions/uploads are lost. Database sessions/cache survive. File-based PIN limits are not shared across multiple instances; retain one instance until shared limiter storage that preserves rollback semantics is designed and tested. S3 support would require a Flysystem adapter and review of upload paths, so it is not introduced here.

## Local build and run

Install/start Docker with Linux containers, then:

```sh
docker build -t kita-app .
docker run --rm --name kita-app -p 8080:10000 --env-file .env --env PORT=10000 --env APP_ENV=production --env APP_DEBUG=false --env APP_URL=http://localhost:8080 --env TRUST_RENDER_PROXY=false --env SESSION_SECURE_COOKIE=false --env LOG_CHANNEL=stderr --env DB_CONNECTION=mysql --env DB_HOST=host.docker.internal --env RUN_MIGRATIONS=false kita-app
```

This example connects to MySQL on your Windows Docker Desktop host using credentials already in `.env`. MySQL must allow connections from Docker. For hosted MySQL, replace `host.docker.internal` with the hosted endpoint, or supply an ignored `.env.docker` file with the correct values. Never commit it. Use a staging database for validation. Passing `--env-file` injects variables at runtime; it does not copy the file into the image. Docker env files do not perform Laravel-style `${VARIABLE}` interpolation, so supply literal resolved values for fields like `MAIL_FROM_ADDRESS`.

Open `http://localhost:8080` and `http://localhost:8080/up`. Optional local persistent storage: add `--mount type=volume,source=kita-storage,target=/var/www/html/storage` to the run command.

Useful container checks (separate terminal):

```sh
docker exec kita-app php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'
docker exec kita-app nginx -t
docker exec kita-app php-fpm -t
docker exec kita-app php artisan migrate:status
docker exec --user www-data kita-app sh -c 'test -w storage && test -w bootstrap/cache'
docker exec kita-app sh -c 'test ! -f .env && test ! -d node_modules && test ! -d vendor/phpunit'
curl -f http://localhost:8080/up
curl -f http://localhost:8080/styles.css
curl -f http://localhost:8080/app.js
curl -f http://localhost:8080/build/manifest.json
curl -I http://localhost:8080/.env
```

The last request must return 404. Repeat with `-p 8081:12345 --env PORT=12345` to verify assigned-port handling. Stop FPM inside a disposable test container and confirm the entire container exits, then repeat with Nginx. Check Render-generated HTTPS asset URLs and secure cookies after deployment.

Laravel routes remain unchanged. KITA's dashboard/inventory/report screens are in the `/` client UI, and `/login` is POST-only in this checkout. Nginx forwards unknown application paths to Laravel; it does not invent new GET routes such as `/dashboard` or `/inventory`.

## Validation and limitations

- Laravel test suite: **69 tests / 625 assertions passed**, including three deployment checks.
- `composer validate`, a clean isolated `composer install --no-dev --optimize-autoloader`, and production platform checks passed on local PHP 8.2.12.
- Configuration, route and Blade view caching passed in the isolated production installation with an unreachable MySQL host, confirming these steps do not require a live database. Existing closure routes are cacheable with this Laravel version.
- `npm ci` and `npm run build` passed; generated Vite manifest, JS and CSS.
- Bash startup script syntax check passed.
- Docker is not installed in this workspace. The actual Linux image build, Nginx/FPM startup, dynamic-port HTTP serving and container permissions must still be verified with the commands above. No live MySQL/SMTP/PayMongo credentials or Render service were provided, so those integrations have not been tested against production.
- No schemas/business logic were changed. Unit/feature tests do not substitute for staging acceptance checks with the imported MySQL database.

## References

- [Render Docker deployment](https://render.com/docs/docker)
- [Render web services and port binding](https://render.com/docs/web-services)
- [Render persistent disks](https://render.com/docs/disks)
- [Render free-tier limitations, including outbound SMTP restrictions](https://render.com/docs/free)
- [Laravel trusted proxies](https://laravel.com/docs/12.x/requests#configuring-trusted-proxies)
- [Vite 7 Node requirements](https://v7.vite.dev/guide/)
