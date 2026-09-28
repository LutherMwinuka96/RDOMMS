# RDO|MMS — Default Credentials

> **Security:** These values are for **local development and first-time setup only**.  
> Change all passwords and secrets before deploying to production. Do not commit real production credentials to version control.

---

## Default application login

Created by `database.sql` and `api/install.php`.

| Field | Value |
|-------|--------|
| **Email** | `ffilipatali@yahoo.com` |
| **Password** | `RDOMMS@2012` |
| **Role** | `admin` |
| **Status** | `active` |
| **Display name** | System Administrator |
| **User ID** | `USR001` |

**Sign-in URL:** `http://localhost/RDOMMS/auth/signin.php`

After first login, change the password via **Profile → Change password** or **Users → All Users**.

---

## Database (MySQL / MariaDB)

Defaults from `.env.example` and `config/db.php` (WAMP/XAMPP local setup).

| Setting | Default value |
|---------|----------------|
| **Host** | `localhost` |
| **Database name** | `rdomms_db` |
| **Username** | `root` |
| **Password** | *(empty)* |
| **Charset** | `utf8mb4` |

### Configure via `.env`

Copy `.env.example` to `.env` and adjust:

```env
DB_HOST=localhost
DB_NAME=rdomms_db
DB_USER=root
DB_PASSWORD=
```

### Legacy `config/db.php`

If not using `.env`, the application reads `DB_*` variables through `config/env.php`. WAMP typically uses `root` with no password.

---

## Installation

| Item | Value |
|------|--------|
| **Installer URL** | `http://localhost/RDOMMS/api/install.php` |
| **SQL schema** | `database.sql` |
| **App base URL (local)** | `http://localhost/RDOMMS` |

The installer imports the schema and ensures the default admin account exists.

---

## Email / SMTP (optional)

No default SMTP credentials are shipped. For password reset and notifications:

1. Copy `config/mail.local.php.example` → `config/mail.local.php`
2. Or configure in **Settings → Email SMTP** (`settings/email-smtp.php`)

Without SMTP, outbound mail may be written to `storage/mail-outbox/` (fallback mode).

---

## Session & security defaults (`.env.example`)

| Setting | Default |
|---------|---------|
| `APP_ENV` | `local` |
| `SESSION_IDLE_MINUTES` | `10` |
| `RATE_LIMIT_LOGIN_MAX` | `5` attempts / 15 min |
| `CSRF_ENABLED` | `1` |
| `WAF_ENABLED` | `1` |

---

## Default roles (seed data)

| Role ID | Name | Type |
|---------|------|------|
| R001 | Admin | admin |
| R002 | Field Officer | custom |
| R003 | Head of Department | admin |
| R004 | Staff | custom |
| R005 | Accountant | custom |

---

## Quick reference

```
Application:  ffilipatali@yahoo.com / RDOMMS@2012
MySQL:        root / (empty password)
Database:     rdomms_db
Install:      /api/install.php
```

---

*RDO|MMS v2.0.0 — change defaults immediately on any shared or production server.*
