# RDO|MMS — Rural Development Organization Monitoring & Management System

**Version:** 2.0.0  
**Organization:** Rural Development Organization (RDO), Tanzania  
**Mission:** Empowering rural communities through sustainable development initiatives.

---

## Table of Contents

1. [Overview](#overview)
2. [Technology Stack](#technology-stack)
3. [System Requirements](#system-requirements)
4. [Installation & Setup](#installation--setup)
5. [Default Credentials](#default-credentials)
6. [Entry Points & URL Map](#entry-points--url-map)
7. [Project Structure](#project-structure)
8. [Modules (Module by Module)](#modules-module-by-module)
9. [Authentication & Sessions](#authentication--sessions)
10. [Security Architecture](#security-architecture)
11. [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
12. [API Reference](#api-reference)
13. [Configuration & Environment Variables](#configuration--environment-variables)
14. [Database Overview](#database-overview)
15. [Backup & Restore](#backup--restore)
16. [Production Deployment Checklist](#production-deployment-checklist)
17. [Troubleshooting](#troubleshooting)
18. [Support & Documentation](#support--documentation)

---

## Overview

**RDO|MMS** (RDOMMS) is a web-based monitoring and management system for rural development organization operations. It provides a unified workspace for:

- Project lifecycle management (create, track, budget approval, archive, deregister)
- Planning (monthly, quarterly, annual plans, targets, progress, evidence)
- Finance (budget allocation, expense tracking, financial reports, variance analysis)
- Beneficiary registration and service tracking
- Reports and analytics
- User management, roles, and activity auditing
- Notifications, approvals, and internal messaging
- System settings, SMTP, backups, and organization profile

The application is a **monolithic PHP application** with vanilla JavaScript frontends. There is **no Composer, npm, or build step** — deploy by copying files to Apache and configuring MySQL.

| Item | Value |
|------|-------|
| Local base URL | `http://localhost/RDOMMS` |
| Database | `rdomms_db` |
| Default timezone | `Africa/Dar_es_Salaam` |
| UI language (settings) | English (`en`), French (`fr`) |

> **Quick credentials:** see [Default Credentials](#default-credentials) or [`credentials.md`](credentials.md).

---

## Technology Stack

| Layer | Technology |
|-------|------------|
| Web server | Apache with `mod_rewrite` |
| Backend | PHP 8.x (`declare(strict_types=1)`) |
| Database | MySQL / MariaDB (`utf8mb4`) |
| Data access | PDO (`config/db.php`) |
| Frontend | HTML5, CSS3, vanilla JavaScript |
| API | JSON REST-style PHP endpoints under `api/` |
| Authentication | PHP sessions + `user_sessions` table |
| Charts | Chart.js (local + CDN on landing page) |
| Icons | Font Awesome 6 (offline bundle) |
| Export | SheetJS (Excel), html2pdf, html-docx-js |
| Email | SMTP via `config/mailer.php`; fallback outbox at `storage/mail-outbox/` |
| Optional 2FA | TOTP (Google Authenticator compatible) |

**Required PHP extensions:** `pdo_mysql`, `json`, `mbstring`, `openssl`

---

## System Requirements

- Apache with `mod_rewrite` enabled
- PHP 8.0+ recommended
- MySQL 5.7+ or MariaDB 10.3+
- WAMP, XAMPP, or LAMP stack

---

## Installation & Setup

### Step 1 — Copy files

Place the project in your web root, for example:

```
C:\wamp64\www\RDOMMS\
```

### Step 2 — Environment file

```bash
copy .env.example .env
```

Edit `.env` with your database and app URL:

```env
APP_ENV=local
APP_URL=http://localhost/RDOMMS
DB_HOST=localhost
DB_NAME=rdomms_db
DB_USER=root
DB_PASSWORD=
```

Environment is loaded by `config/env.php`.

### Step 3 — Create the database

**Option A — Web installer (recommended)**

Open in a browser:

```
http://localhost/RDOMMS/api/install.php
```

The installer:

- Executes `database.sql` statement-by-statement
- Creates schema and seed data
- Upserts the default admin account
- Links to sign-in and landing page

**Option B — Manual import**

1. Open phpMyAdmin
2. Import `database.sql`

**Optional migrations** (for newer features, if not already in `database.sql`):

```
database/migrations/project_budget_approval.sql
database/migrations/project_deregistration.sql
database/migrations/planning_integration.sql
```

Most schema changes are also applied automatically at runtime via `ensure*Schema()` functions in `config/`.

### Step 4 — Email (optional, required for password reset)

1. Copy `config/mail.local.php.example` → `config/mail.local.php`, **or**
2. Configure via **Settings → Email SMTP** (`settings/email-smtp.php`)

Without SMTP, messages may be saved to `storage/mail-outbox/`.

### Step 5 — Sign in

| URL | Description |
|-----|-------------|
| `http://localhost/RDOMMS/` | Public landing page |
| `http://localhost/RDOMMS/auth/signin.php` | Sign in |
| `http://localhost/RDOMMS/RDOMMS.php` | Main dashboard (authenticated) |

> `RDOMMS.html` is rewritten to `RDOMMS.php` via `.htaccess` for server-side auth.

---

## Default Credentials

### Application login (after install)

| Field | Value |
|-------|--------|
| **Email** | `ffilipatali@yahoo.com` |
| **Password** | `RDOMMS@2012` |
| **Role** | `admin` |
| **Status** | `active` |
| **User ID** | `USR001` |

**Change this password immediately** after first login on any shared or production server.

Sources: `database.sql`, `api/install.php`, [`credentials.md`](credentials.md)

### MySQL (local WAMP default)

| Setting | Value |
|---------|--------|
| Host | `localhost` |
| Database | `rdomms_db` |
| User | `root` |
| Password | *(empty)* |

### Seed roles (reference table `roles`)

| ID | Name | Typical use |
|----|------|-------------|
| R001 | Admin | Full system access |
| R002 | Field Officer | Projects, reports |
| R003 | Head of Department | Projects, planning, reports |
| R004 | Staff | Projects |
| R005 | Accountant | Finance, reports |

Runtime permissions use the `users.role` string — see [RBAC](#role-based-access-control-rbac).

---

## Entry Points & URL Map

### Public (no login required)

| URL | Handler | Notes |
|-----|---------|-------|
| `/` | `index.php` | Landing page (`index.html`) |
| `/auth/signin.php` | Sign in | Login + optional 2FA |
| `/auth/signup.php` | Registration | Pending admin approval |
| `/auth/forgot_password.php` | Password reset request | Requires SMTP |
| `/auth/reset_password.php` | Set new password | Token from email |
| `/api/install.php` | DB installer | **Remove in production** |
| `/api/public/landing-stats.php` | Public stats API | Landing page KPIs |

### Authenticated

| URL | Handler | Notes |
|-----|---------|-------|
| `/RDOMMS.php` | Main dashboard | PHP auth guard |
| `/RDOMMS.html` | Rewritten → `RDOMMS.php` | Same dashboard |
| `/projects/*.php` | Projects module | Protected |
| `/planning/*.php` | Planning module | Protected |
| `/finance/*.php` | Finance module | Protected + role checks |
| `/beneficiaries/*.php` | Beneficiaries module | Protected |
| `/reports/*.php` | Reports module | Protected + role checks |
| `/users/*.php` | Users module | Protected |
| `/settings/*.php` | Settings module | Protected |
| `/notifications/*.php` | Notifications | Protected |
| `/documents/*.php` | Documents | Protected |

### Page rendering pattern

Most modules use paired files:

- `module/page.html` — UI template
- `module/page.php` — PHP entry point

Protected pages call `rdomms_require_authenticated_page()` or `rdomms_render_protected_html()`. Module `.htaccess` files redirect legacy `.html` URLs to `.php`.

---

## Project Structure

```
RDOMMS/
├── index.php / index.html       # Public landing
├── RDOMMS.php / RDOMMS.html     # Authenticated dashboard
├── api.php                      # Legacy bulk data sync
├── database.sql                 # Full schema + seeds
├── .env / .env.example          # Environment config
├── .htaccess                    # Apache routing & security headers
├── README.md                    # This file
├── credentials.md               # Default credentials reference
│
├── api/                         # JSON backend
│   ├── auth/                    # Login, signup, 2FA, sessions
│   ├── auth/2fa/                # Two-factor authentication
│   ├── dashboard/               # KPI stats & charts
│   ├── settings/                # System settings APIs
│   ├── dropdowns/               # Reference lists
│   ├── notifications/           # Alerts API
│   ├── finance/                 # Funding sources
│   └── projects/                # Project health API
│
├── auth/                        # Authentication pages
├── config/                      # Core PHP configuration (29 files)
├── assets/                      # CSS, JS, vendor libraries, images
├── projects/                    # Projects module
├── planning/                    # Planning module
├── finance/                     # Finance module
├── beneficiaries/               # Beneficiaries module
├── reports/                     # Reports module
├── notifications/               # Notifications module
├── users/                       # User management
├── settings/                    # System settings UI
├── documents/                   # Project documents
├── help/                        # User manual & tutorials
├── storage/                     # Backups, mail outbox, WAF logs
├── database/migrations/         # Manual SQL migrations
└── scripts/                     # Dev/ops utilities
```

---

## Modules (Module by Module)

### 1. Dashboard

| File | Purpose |
|------|---------|
| `RDOMMS.php` | Authenticated entry — serves `RDOMMS.html` |
| `RDOMMS.html` | Main dashboard UI |

**Features:**

- Role-based KPI stat cards
- Overview charts (activities, projects, funding sources)
- Project status, budget vs expense, recent projects
- Sidebar navigation to all modules
- Session countdown timer, notifications, profile menu
- Auto-refresh of dashboard data

**APIs:** `api/dashboard/stats.php`, `api/dashboard/charts.php`

---

### 2. Projects

| Page | File | Purpose |
|------|------|---------|
| Create Project | `projects/create-project.php` | Register new project with team, budget, funding source |
| All Projects | `projects/all-projects.php` | Searchable project grid with health indicators |
| Status Tracking | `projects/status-tracking.php` | Kanban/table view, progress updates, budget approval |
| Project Health | `projects/project-health.php` | Budget vs spend, alerts, linked finance |
| Archived Projects | `projects/archived-projects.php` | Completed/archived projects, restore, deregister |

**Key workflows:**

| Workflow | Description |
|----------|-------------|
| **Budget approval** | Projects with `budget > 0` require approver sign-off before progress/expenses |
| **Archive** | Set status `completed` or progress ≥ 100% |
| **Deregister** | Request removal → approver confirms → deletes project + linked budget, expenses, progress, documents |

**APIs:** `api/projects.php`, `api/projects/health.php`, `api/project-documents.php`

**Config:** `config/project-budget.php`, `config/project-deregistration.php`, `config/project-health.php`

---

### 3. Planning

| Page | File | Purpose |
|------|------|---------|
| Monthly Plans | `planning/monthly-plans.php` | Create and manage monthly plans |
| Quarterly Plans | `planning/quarterly-plans.php` | Quarterly planning |
| Annual Plans | `planning/annual-plans.php` | Annual planning |
| All Plans | `planning/all-plans.php` | Unified register with filters, approval actions |
| Targets & Indicators | `planning/targets-indicators.php` | KPI targets |
| Progress Monitoring | `planning/progress-monitoring.php` | Track plan execution |
| Upload Evidence | `planning/upload-evidence.php` | Attach supporting evidence |

**APIs:** `api/plans.php`, `api/targets.php`, `api/progress-monitoring.php`, `api/planning-evidence.php`, `api/planning-helpers.php`

**Roles with access:** admin, head_of_organization_center, head_of_department, field_officer

---

### 4. Finance

| Page | File | Purpose |
|------|------|---------|
| Budget Allocation | `finance/budget-allocation.php` | Allocate and approve departmental budgets |
| Expense Tracking | `finance/expense-tracking.php` | Record and approve expenses |
| Financial Reports | `finance/financial-reports.php` | Summary financial reports |

**APIs:** `api/budget.php`, `api/expenses.php`, `api/finance/funding-sources.php`

**Access:** Finance **module and API** are restricted to `admin` and `accountant`. Other roles may see finance KPIs on the dashboard but cannot open finance pages.

**Linked records:** Budget allocations and expenses can link to projects (`linked_type=project`, `linked_id=PRJ...`). Project budget approval gates linked expense recording.

---

### 5. Beneficiaries

| Page | File | Purpose |
|------|------|---------|
| Register Beneficiary | `beneficiaries/register-beneficiary.php` | Register new beneficiary |
| Beneficiary List | `beneficiaries/beneficiary-list.php` | View all beneficiaries |
| Service Tracking | `beneficiaries/service-tracking.php` | Track services delivered |
| Search & Filter | `beneficiaries/search-filter.php` | Advanced search |

**APIs:** `api/beneficiaries.php`, `api/service-tracking.php`

**Data scoping:** Non-admin users typically see only records they created (`registered_by` / `created_by`).

---

### 6. Reports

| Page | File | Purpose |
|------|------|---------|
| Report Studio | `reports/generate-reports.php` | Generate role-scoped reports |
| Variance Analysis | `reports/variance-analysis.php` | Budget vs expense variance (admin/accountant) |

**APIs:** `api/reports.php`, `api/report-helpers.php`

**Config:** `config/report-access.php` — defines which report types each role can access.

---

### 7. Notifications

| Page | File | Purpose |
|------|------|---------|
| System Alerts | `notifications/system-alerts.php` | System-wide and personal alerts |
| Approval Requests | `notifications/approval-requests.php` | Pending approvals queue |
| Messages | `notifications/messages.php` | Internal messaging threads |

**APIs:** `api/notifications.php`, `api/approval-requests.php`, `api/messages.php`, `api/notifications/unread/index.php`

---

### 8. Users & Access Control

| Page | File | Purpose |
|------|------|---------|
| Add User | `users/add-user.php` | Create user account |
| All Users | `users/all-users.php` | User directory, approve pending users |
| Role Management | `users/role-management.php` | Permission matrix (admin) |
| Activity Log | `users/activity-log.php` | Audit trail of user actions |

**APIs:** `api/users.php`, `api/roles.php`, `api/activity-log.php`, `api/auth/approve-user.php`

---

### 9. Settings

| Page | File | Purpose |
|------|------|---------|
| General Settings | `settings/general-settings.php` | App name, language, theme, maintenance mode |
| Organization Profile | `settings/organization-profile.php` | Org name, logo, contact info |
| Notification Settings | `settings/notification-settings.php` | Notification preferences |
| Data Backup | `settings/data-backup.php` | Export/restore JSON backups |
| System Logs | `settings/system-logs.php` | View system logs |
| Email SMTP | `settings/email-smtp.php` | SMTP configuration |

**APIs:** `api/settings/general.php`, `organization-profile.php`, `notification-preferences.php`, `data-backup.php`, `mail-config.php`, `test-mail.php`, `public.php`

**Access:** Primarily `admin` and `head_of_organization_center`.

---

### 10. Documents

| Page | File | Purpose |
|------|------|---------|
| Project Documents | `documents/project-documents.php` | Upload and manage project evidence/documents |

**API:** `api/project-documents.php`

---

### 11. Authentication

| Page | File | Purpose |
|------|------|---------|
| Sign In | `auth/signin.php` | Email/password login, 2FA step, session expiry toast |
| Sign Up | `auth/signup.php` | Self-registration (if enabled) |
| Forgot Password | `auth/forgot_password.php` | Request reset link |
| Reset Password | `auth/reset_password.php` | Set new password from token |

**Flow:**

```
signin.html → POST api/auth/login.php
           → (optional) POST api/auth/2fa/verify-login.php
           → PHP session + user_sessions row
           → localStorage: currentUser, sessionToken
           → Redirect to RDOMMS.php
```

---

### 12. Help

| File | Purpose |
|------|---------|
| `help/user-manual.html` | In-app user manual |
| `help/video-tutorials.html` | Video tutorial links |

---

## Authentication & Sessions

RDOMMS uses a **hybrid PHP session + database token** model:

| Component | Storage |
|-----------|---------|
| Browser cookie | `PHPSESSID` (HttpOnly, SameSite, Secure on HTTPS) |
| Server session | `$_SESSION` keys: `user_id`, `session_token`, `user_role`, `csrf_token` |
| Database | `user_sessions` table with `expires_at`, `last_active_at` |
| Client mirror | `localStorage`: `currentUser`, `sessionToken`, `isLoggedIn` |

### Session idle timeout

| Setting | Default (`.env.example`) |
|---------|--------------------------|
| `SESSION_IDLE_MINUTES` | `10` minutes |
| `SESSION_WARN_SECONDS` | `60` seconds before expiry |

**Behavior:**

- Session expires after configured idle minutes without activity
- User activity (click, keyboard, scroll) triggers `api/auth/session-ping.php` to extend the session
- Countdown timer shown in dashboard topbar
- On expiry: redirect to `auth/signin.php?expired=1`
- `session_regenerate_id(true)` on successful login

### Session APIs

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `api/auth/me.php` | GET | Current user + session metadata |
| `api/auth/session-ping.php` | POST | Extend idle timeout (activity) |
| `api/auth/session-status.php` | GET | Remaining time, warn threshold |
| `api/auth/restore-session.php` | POST | Rehydrate PHP session from token |
| `api/auth/logout.php` | POST | Destroy session |

### Two-factor authentication (optional)

Per-user TOTP via `api/auth/2fa/*` endpoints. When enabled, login returns `requires_2fa` and completes via `verify-login.php`.

---

## Security Architecture

### Layer summary

```
Browser → Apache (.htaccess headers)
       → WAF / IDS (config/waf.php)
       → HTTPS redirect (production)
       → PHP session + CSRF (config/security.php)
       → verifySession() + requireRole() (config/db.php)
       → Scoped data filters (organization / department / owner)
```

### 1. Secure cookies

Configured in `config/security.php` → `rdomms_configure_secure_session()`:

| Flag | Value |
|------|-------|
| HttpOnly | `true` |
| Secure | Auto on HTTPS / production (`SESSION_SECURE`) |
| SameSite | `Lax` (configurable via `SESSION_SAMESITE`) |
| Lifetime | Browser session (`0`) |

### 2. CSRF protection

- Enabled via `CSRF_ENABLED=1`
- Token in `$_SESSION['csrf_token']`, fetched from `api/auth/csrf.php`
- Required on API mutations (`POST`, `PUT`, `DELETE`, `PATCH`)
- Client sends `X-CSRF-Token` header (`assets/js/rdomms-security.js` patches `fetch` and `XMLHttpRequest`)
- Login, signup, install endpoints are exempt

### 3. Web Application Firewall (WAF) & IDS

File: `config/waf.php`

| Setting | Default | Purpose |
|---------|---------|---------|
| `WAF_ENABLED` | `1` | Master switch |
| `WAF_BLOCK_SCORE` | `85` | Immediate block threshold |
| `WAF_IDS_BLOCK_SCORE` | `120` | IDS block score |
| `WAF_IDS_MAX_VIOLATIONS` | `5` | Violations before IP ban |
| `WAF_IDS_WINDOW_MINUTES` | `15` | Rolling window |
| `WAF_IDS_BLOCK_MINUTES` | `60` | IP block duration |

Blocked IPs: `storage/security/blocked-ips.json`  
IDS events: `storage/security/ids/events-YYYY-MM-DD.log`

### 4. Rate limiting

File-based in `storage/security/rate-limits/`:

| Action | Default limit |
|--------|---------------|
| Login | 5 attempts / 15 minutes |
| Password reset | 3 attempts / 30 minutes |
| Signup | Uses login limits (IP-based) |

Identifier: `IP|email`

### 5. PHP page guards

- `rdomms_require_authenticated_page()` — redirects unauthenticated users to sign-in
- Applied on `RDOMMS.php` and all protected module `.php` entry points
- Public pages: landing, auth, install

### 6. Password security

- `password_hash()` / `password_verify()` (bcrypt)
- Legacy plain-text passwords auto-rehashed on login
- Reset tokens in `password_reset_tokens` table with expiry

### 7. Field-level encryption (optional)

When `DB_ENCRYPTION_KEY` is set in `.env`:

- Algorithm: AES-256-GCM (`config/encryption.php`)
- Encrypted fields: user phone/employee ID, beneficiary phone/national ID
- Prefix: `enc1:`

Generate key: `openssl rand -base64 48`

### 8. Activity logging

All significant actions logged via `logActivity()` to `activity_logs` table (login failures, role changes, project deregistration, etc.).

Viewable at **Users → Activity Log**.

### 9. Maintenance mode

When `maintenance_mode=1` in system settings, non-admin login is blocked.

### 10. Offline auth bypass

`SESSION_OFFLINE_AUTH=0` (default) — server session validation is required. Client-side offline fallback is disabled for stronger security.

### 11. HTTP security headers

Set via `.htaccess` and `rdomms_send_security_headers()`:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`

Sensitive files blocked: `.env`, `.env.example`, `.gitignore`

---

## Role-Based Access Control (RBAC)

### System roles (`users.role`)

| Role key | Description |
|----------|-------------|
| `admin` | Full access, all data, all modules |
| `head_of_organization_center` | Organization center head |
| `head_of_department` | Department head |
| `human_resource_manager` | HR — org-wide user visibility |
| `accountant` | Finance module and APIs |
| `field_officer` | Field operations |
| `staff` | Standard user |

**Aliases normalized to admin:** `mkurugenzi_mtendaji`, `administrator`, `system_administrator`

### Module visibility (sidebar navigation)

| Module | Roles |
|--------|-------|
| Projects | admin, head_of_organization_center, head_of_department, field_officer, staff |
| Beneficiaries | same as Projects |
| Planning | admin, head_of_organization_center, head_of_department, field_officer |
| Finance | **admin, accountant only** |
| Reports | admin, head_of_organization_center, head_of_department, human_resource_manager, accountant |
| Users | admin, head_of_organization_center, human_resource_manager |
| Settings | admin, head_of_organization_center |
| Notifications | all authenticated roles |

### Data scoping

| Role | Data visibility |
|------|-----------------|
| admin | All records |
| head_of_organization_center | Organization center scope; can toggle personal/team view |
| head_of_department | Department scope within organization |
| human_resource_manager | All users (user list bypass) |
| accountant | All finance data |
| field_officer / staff | Own records (`created_by`) |

Helpers: `appendProjectListScopeFilter`, `appendBeneficiaryListScopeFilter`, `appendFinanceListScopeFilter`, `appendUserListScopeFilter` in `config/db.php`.

### Key permission rules

| Action | Who can do it |
|--------|---------------|
| Create/edit projects | admin, heads, field_officer, staff (scoped) |
| Approve project budget | admin, accountant, relevant head |
| Request project deregistration | Project manager / creator / head |
| Approve project deregistration | admin, accountant, relevant head |
| Finance CRUD | accountant (+ admin bypass) |
| Manage users | admin, HR manager, heads (with restrictions) |
| Role management API | admin only |
| Delete admin accounts | admin only |

### Organization centers

RDO Mafinga, Mdabulo, Ibwanzi, Kilolo, Malangali (`config/organization-centers.php`)

### Departments (seed)

Orphanage Support, Water Transmission Association, Administration, Agriculture, Pottery, Vocational Training Center, Store, Department of Finance, Department of Marketing, Procurement & Supply

---

## API Reference

### Conventions

- Base path: `/api/`
- Methods: `GET`, `POST`, `PUT`, `DELETE` (per endpoint)
- Auth: PHP session cookie + optional `X-Session-Token` header
- CSRF: `X-CSRF-Token` on mutations
- Response format:

```json
{
  "status": "success",
  "message": "Human-readable message",
  "data": { }
}
```

### Authentication endpoints (`api/auth/`)

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `login.php` | POST | No | Sign in (+ 2FA challenge) |
| `logout.php` | POST | Yes | Sign out |
| `me.php` | GET | Yes | Current user profile |
| `signup.php` | POST | No | Register (pending approval) |
| `approve-user.php` | POST | Admin | Approve pending user |
| `forgot-password.php` | POST | No | Request reset email |
| `reset-password.php` | POST | No | Set new password |
| `verify-reset-token.php` | GET | No | Validate reset token |
| `change-password.php` | POST | Yes | Change password |
| `update-profile.php` | POST | Yes | Update profile |
| `csrf.php` | GET | Session | Get CSRF token |
| `session-ping.php` | POST | Yes | Extend idle session |
| `session-status.php` | GET | Yes | Session metadata |
| `restore-session.php` | POST | Token | Restore from stored token |
| `2fa/setup.php` | POST | Yes | Begin 2FA setup |
| `2fa/confirm.php` | POST | Yes | Confirm 2FA |
| `2fa/disable.php` | POST | Yes | Disable 2FA |
| `2fa/status.php` | GET | Yes | 2FA status |
| `2fa/verify-login.php` | POST | Pending | Complete 2FA login |

### Core resource endpoints

| Endpoint | Resource |
|----------|----------|
| `projects.php` | Projects CRUD, budget approval, deregistration |
| `users.php` | User management |
| `roles.php` | Role definitions (admin) |
| `beneficiaries.php` | Beneficiaries |
| `service-tracking.php` | Service delivery tracking |
| `budget.php` | Budget allocations |
| `expenses.php` | Expenses |
| `plans.php` | Planning documents |
| `targets.php` | Targets & indicators |
| `progress-monitoring.php` | Progress updates |
| `planning-evidence.php` | Evidence uploads |
| `reports.php` | Report generation |
| `notifications.php` | System alerts |
| `approval-requests.php` | Approval queue |
| `messages.php` | Internal messaging |
| `activity-log.php` | Audit log |
| `project-documents.php` | Document management |

### Project API actions (`api/projects.php`)

| Action | Method | Purpose |
|--------|--------|---------|
| *(default)* | GET/POST/PUT/DELETE | Standard CRUD |
| `pending-budgets` | GET | Budgets awaiting approval |
| `approve-budget` | PUT | Approve project budget |
| `reject-budget` | PUT | Reject project budget |
| `resubmit-budget` | PUT | Resubmit after rejection |
| `pending-deregistrations` | GET | Removal requests awaiting approval |
| `deregistration-preview` | GET | Preview linked records to delete |
| `request-deregistration` | PUT | Submit removal request |
| `cancel-deregistration` | PUT | Cancel pending request |
| `approve-deregistration` | PUT | Approve and cascade delete |
| `reject-deregistration` | PUT | Reject removal request |
| `deregister` | PUT | Admin immediate removal |

### Dashboard endpoints

| Endpoint | Purpose |
|----------|---------|
| `dashboard/stats.php` | KPI aggregates (role-scoped) |
| `dashboard/charts.php` | Chart data (funding, budget vs expense) |
| `notifications/unread/index.php` | Unread notification count |

### Settings endpoints (`api/settings/`)

| Endpoint | Purpose |
|----------|---------|
| `general.php` | App settings |
| `organization-profile.php` | Organization profile |
| `notification-preferences.php` | Notification prefs |
| `data-backup.php` | Backup/restore |
| `mail-config.php` | SMTP settings |
| `test-mail.php` | Send test email |
| `public.php` | Public settings (no auth) |

---

## Configuration & Environment Variables

Copy `.env.example` → `.env`. Key variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_ENV` | `local` | Environment (`production` enables HTTPS auto) |
| `APP_URL` | `http://localhost/RDOMMS` | Base URL for redirects/CORS |
| `APP_TIMEZONE` | `Africa/Dar_es_Salaam` | PHP timezone |
| `DB_HOST` | `localhost` | MySQL host |
| `DB_NAME` | `rdomms_db` | Database name |
| `DB_USER` | `root` | MySQL user |
| `DB_PASSWORD` | *(empty)* | MySQL password |
| `DB_PASSWORD_FALLBACK` | *(empty)* | Secondary password attempt |
| `DB_ENCRYPTION_KEY` | *(empty)* | AES-256-GCM key for sensitive fields |
| `FORCE_HTTPS` | `0` | Force HTTPS redirect |
| `FORCE_HTTPS_AUTO` | `1` | Auto-enable in production |
| `SESSION_SECURE` | *(auto)* | Secure cookie flag |
| `SESSION_SAMESITE` | `Lax` | Cookie SameSite policy |
| `CSRF_ENABLED` | `1` | CSRF protection |
| `CORS_ALLOWED_ORIGIN` | *(empty)* | Explicit CORS origin |
| `WAF_ENABLED` | `1` | Web Application Firewall |
| `WAF_BLOCK_SCORE` | `85` | WAF block threshold |
| `WAF_IDS_*` | see `.env.example` | IDS tuning |
| `RATE_LIMIT_LOGIN_MAX` | `5` | Login attempt limit |
| `RATE_LIMIT_LOGIN_WINDOW` | `15` | Login window (minutes) |
| `RATE_LIMIT_PASSWORD_MAX` | `3` | Password reset limit |
| `RATE_LIMIT_PASSWORD_WINDOW` | `30` | Reset window (minutes) |
| `SESSION_IDLE_MINUTES` | `10` | Session idle timeout |
| `SESSION_WARN_SECONDS` | `60` | Warning before expiry |
| `SESSION_OFFLINE_AUTH` | `0` | Offline localStorage auth (disabled) |

### Key config files

| File | Purpose |
|------|---------|
| `config/db.php` | Database, auth, RBAC, scoping, activity log |
| `config/env.php` | Environment loader |
| `config/security.php` | Cookies, CSRF, CORS, HTTPS |
| `config/waf.php` | WAF / IDS |
| `config/session-manager.php` | Session idle timeout, `user_sessions` |
| `config/two-factor.php` | 2FA TOTP |
| `config/encryption.php` | Field-level encryption |
| `config/system-settings.php` | DB-backed app settings |
| `config/render-page.php` | HTML page rendering |
| `config/project-budget.php` | Project budget approval workflow |
| `config/project-deregistration.php` | Project removal workflow |
| `config/report-access.php` | Report permissions by role |
| `config/mailer.php` | Email sending |

---

## Database Overview

Full schema: `database.sql`

| Table group | Tables |
|-------------|--------|
| **Auth** | `users`, `user_sessions`, `password_reset_tokens` |
| **Organization** | `organizations`, `departments`, `roles`, `system_settings` |
| **Projects** | `projects` (incl. budget approval & deregistration columns) |
| **Finance** | `budget_allocations`, `expenses` |
| **Beneficiaries** | `beneficiaries`, `service_tracking` |
| **Planning** | `monthly_plans`, `quarterly_plans`, `annual_plans`, `targets`, `progress_updates`, `planning_evidence` |
| **Documents** | `project_documents` |
| **Notifications** | `notifications`, `approval_requests`, `notification_threads`, `notification_messages` |
| **Reports** | `scheduled_reports`, `report_history` |
| **Audit** | `activity_logs` |

### Project budget columns (`projects`)

| Column | Purpose |
|--------|---------|
| `budget_approval_status` | `draft`, `pending`, `approved`, `rejected` |
| `budget_submitted_at` | Submission timestamp |
| `budget_approved_by/at` | Approver info |
| `budget_rejected_by`, `budget_reject_reason` | Rejection info |

### Project deregistration columns (`projects`)

| Column | Purpose |
|--------|---------|
| `deregistration_status` | `none`, `pending`, `approved`, `rejected` |
| `deregistration_requested_at/by` | Request metadata |
| `deregistration_reason` | Why removal was requested |
| `deregistration_approved_by/at` | Approval metadata |
| `deregistration_rejected_by`, `deregistration_reject_reason` | Rejection info |

---

## Backup & Restore

**Settings → Data Backup** (`settings/data-backup.php`):

- Exports important tables to JSON files
- Stored in `storage/backups/`
- Supports download, restore, and delete
- API: `api/settings/data-backup.php`

> Also back up `database.sql`, `.env`, and `storage/backups/` regularly in production.

---

## Production Deployment Checklist

- [ ] Change default admin password (`ffilipatali@yahoo.com` / `RDOMMS@2012`)
- [ ] Set strong `DB_PASSWORD` in `.env`
- [ ] Remove or restrict access to `api/install.php`
- [ ] Keep `.env` and `config/mail.local.php` out of version control
- [ ] Set `APP_ENV=production` or `FORCE_HTTPS=1`
- [ ] Set real `APP_URL` (e.g. `https://your-domain.go.tz/RDOMMS`)
- [ ] Set `DB_ENCRYPTION_KEY` for sensitive field encryption
- [ ] Configure SMTP for password reset emails
- [ ] Review `SESSION_IDLE_MINUTES` (default: 10 minutes)
- [ ] Confirm `SESSION_OFFLINE_AUTH=0`
- [ ] Test WAF does not block legitimate traffic
- [ ] Enable regular database backups
- [ ] Set `maintenance_mode=0` unless actively maintaining

### Apache subfolder deployment

The root `.htaccess` auto-detects subfolder base (e.g. `/RDOMMS/`). Set `APP_URL` to match.

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Database connection failed | Check MySQL is running; verify `.env` credentials |
| Blank page / HTTP 500 | Check Apache/PHP error log; verify PHP extensions |
| Cannot sign in after setup | Run `api/install.php`; verify admin exists in `users` table |
| Login succeeds then redirects back | Check PHP/MySQL timezone match (`APP_TIMEZONE`); restart Apache after `.env` changes |
| Session expires too quickly | Adjust `SESSION_IDLE_MINUTES` in `.env`; re-login after change |
| CSRF errors on API calls | Ensure `rdomms-security.js` loads; call `api/auth/csrf.php` first |
| Password reset not working | Configure SMTP in `mail.local.php` or Settings → Email SMTP |
| Login returns "pending" | Admin must approve user at Users → All Users |
| `.html` URL does not work | Use `.php` equivalent — module `.htaccess` redirects automatically |
| Icons missing offline | Verify `assets/vendor/fontawesome/webfonts/` exists |
| WAF blocking requests | Check `storage/security/ids/` logs; tune scores in `.env` |
| Rate limit lockout | Wait for window to expire or clear files in `storage/security/rate-limits/` |

### Test database connection

```
http://localhost/RDOMMS/api/install.php
```

### Test session configuration

```
php scripts/test-session-login.php
```

---

## Support & Documentation

| Resource | Location |
|----------|----------|
| Default credentials | [`credentials.md`](credentials.md) |
| User manual | `help/user-manual.html` |
| Video tutorials | `help/video-tutorials.html` |
| In-dashboard help | Help button on `RDOMMS.html` |
| Database schema | `database.sql` |
| Environment template | `.env.example` |

---

## License & Contribution

Built for **Rural Development Organization (RDO)** and its rural development centers in Tanzania.

For changes, bug reports, or contributions — contact the organization's IT team.

---

**RDO|MMS v2.0.0** — *Empowering rural communities through sustainable development initiatives.*
