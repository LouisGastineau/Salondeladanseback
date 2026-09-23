# Production VPS

API: https://vps123924.serveur-vps.net/api

PHP 8.2 FPM, Nginx, MariaDB 10.11 and Laravel 12. MariaDB listens on localhost only. HTTPS uses Let's Encrypt with automatic renewal. The frontend origin currently allowed is `http://localhost:5173`.

## Deployment

Push to `main` in `LouisGastineau/Salondeladanseback`. GitHub Actions runs PHPUnit and the MariaDB integration tests, including concurrent reservations. Only a successful test job can deploy. Pull requests run tests without deploying. You can also run the workflow manually on main.

Repository Actions secrets:

- `VPS_DEPLOY_KEY`: private SSH key for the dedicated `deploy` account, never the root password.
- `VPS_KNOWN_HOSTS`: pinned SSH host key for `vps123924.serveur-vps.net`.

GitHub uploads exactly the tested Git commit archive. The server installs Composer production dependencies, caches configuration/routes, creates a backup, runs migrations and atomically switches `/var/www/back/current` to the new release. A failed HTTP health check restores the previous code when available. Schema migrations are never automatically reversed; use backward-compatible migrations.

Server paths:

| Path | Purpose |
|---|---|
| `/var/www/back/current` | Active release symlink |
| `/var/www/back/releases` | Retained releases for code rollback |
| `/var/www/back/shared/.env` | Production secrets and frontend origin |
| `/var/www/back/shared/storage` | Persistent photos, cache and application logs |
| `/usr/local/bin/salon-deploy` | Root-owned deployment script, executed as deploy |
| `/var/backups/salon-danse` | Database + storage backups, root-only |

The deploy user has sudo rights only for the fixed backup command and PHP-FPM reload. Changing scripts under `deployment/vps` in GitHub does not overwrite the installed privileged scripts; an administrator must review and reinstall them.

Backups run before deployment and every day at 03:00 server time, with 14-day retention. These are local server backups; copy them to separate storage for protection against loss of the VPS. Releases are retained until an administrator removes old versions after review.

## Administration

Create the initial administrator over SSH (interactive password prompt):

```sh
sudo -u deploy bash -c 'cd /var/www/back/current && php artisan salon:admin admin@example.com'
```

For a newly deployed frontend, update `FRONTEND_URL` in `/var/www/back/shared/.env` to the exact HTTPS origin, optionally keeping localhost as a comma-separated second origin. Then rebuild the config cache:

```sh
sudo -u deploy bash -c 'cd /var/www/back/current && php artisan config:cache'
systemctl reload php8.2-fpm
```

The Vite frontend should use `https://vps123924.serveur-vps.net/api` as its API base URL and Bearer token authentication. No database credentials belong in frontend variables.

Useful checks:

```sh
systemctl status nginx php8.2-fpm mariadb
systemctl list-timers salon-backup.timer certbot.timer
curl -f https://vps123924.serveur-vps.net/up
curl -i -H 'Accept: application/json' https://vps123924.serveur-vps.net/api/me
```

The last command should return HTTP 401 without a token. A fresh deployment has no users, editions or slots. Available API operations are documented in API-BACKEND.md; deployment does not add extra CRUD endpoints.

`setup.sh` is for initial provisioning after installing Debian packages. Do not rerun it blindly on an existing production machine: it installs the site's initial Nginx configuration and deployment public key.
