# Smart Event Attendance & Registration Platform

A complete event management system with online registration, dynamic forms,
encrypted QR e-tickets, PWA mobile scanner with offline sync, certificate
generation, and multi-format reports.

**Stack:** React (Vite + PWA) → PHP REST API (XAMPP) → MySQL

---

## What's inside

- **Public registration** — beautiful guest-facing page per event, no signup
- **GoWild-style e-ticket PDF** — emailed automatically to every guest, with QR
- **Admin dashboard** — events list, live telemetry, recent registrations panel
- **Multi-step event wizard** — title, schedule, price, age limit, dynamic form builder
- **Mobile PWA scanner** — runs on any smartphone browser, scans QRs, works offline
- **Walk-in lookup** — search any attendee by name/email, check them in manually
- **Settings page** — configure SMTP credentials (Gmail/Outlook/SendGrid/etc) from the UI
- **Reports** — CSV, Excel (.xlsx), and styled PDF exports
- **Certificates** — auto-generated certificate of attendance per registration
- **Resend ticket** — re-queue any ticket if you edit an event after registrations exist
- **RBAC** — `super_admin`, `event_manager`, `staff`, `scanner` roles
- **Security** — JWT auth, idempotent check-in, XSS sanitization, prepared statements, IP rate limiting

---

## Setup (first time)

### Prerequisites

- **XAMPP** (Apache + MySQL + PHP 8.1+) — https://www.apachefriends.org
- **Composer** — https://getcomposer.org
- **Node.js 18+** — https://nodejs.org

### 1. Database

1. Start **Apache** and **MySQL** in the XAMPP Control Panel
2. Open http://localhost/phpmyadmin
3. Create a new database: `smart_event` (collation `utf8mb4_unicode_ci`)
4. Select it → **Import** → choose `database/schema.sql` → **Go**

Already have an older install? Run `database/migration_v2.sql` instead to add
the new ticket/settings columns without losing data.

### 2. Backend (PHP API)

The frontend hard-codes the API path as `/backend-php/api`, so the backend
folder **must be named `backend-php`** inside `htdocs`:

```bash
xcopy /E /I backend-php C:\xampp\htdocs\backend-php
cd C:\xampp\htdocs\backend-php
composer install
copy .env.example .env
```

Open `.env` and set two random secrets:
```
JWT_SECRET=any-long-random-string
QR_SECRET=different-long-random-string
```
DB credentials are pre-set for the default XAMPP install (`root` / empty password).

Verify the backend is reachable: open http://localhost/backend-php/api — you
should see JSON with `"status":"online"`.

### 3. Frontend (React)

In a separate terminal, in the **original** project folder (not htdocs):

```bash
cd frontend-react
npm install
npm run dev
```

Open http://localhost:5173.

### 4. First login

```
Email:    admin@smartevent.local
Password: Admin@123
```
**Change this in the admin UI before going live.**

### 5. Configure email (one-time)

The platform sends ticket PDFs by email. Configure SMTP in the admin UI:

1. Sign in → click **Settings** in the sidebar
2. Pick your provider (Gmail/Outlook/SendGrid/Mailgun/Brevo/Custom)
3. Enter your SMTP username (your email) and password
   - **Gmail:** create an [App Password](https://myaccount.google.com/apppasswords) — your normal password won't work
   - **Outlook:** also requires an app password if 2FA is on
4. Set the "From" email and name
5. Click **Save**
6. Click **Send test** with your own email to verify it works

### 6. Email queue worker (cron)

The email queue is processed asynchronously. Run it periodically:

```bash
php C:\xampp\htdocs\backend-php\cron\mail_queue_worker.php
```

For automated dispatch on Windows, use **Task Scheduler** to run this every
minute. On Linux:
```cron
* * * * * php /var/www/backend-php/cron/mail_queue_worker.php
```

---

## How to use the scanner on your phone

The PWA scanner runs in any modern mobile browser — no native app install
required. Both devices must be on the **same WiFi network**.

### One-time setup

1. **Find your PC's IP address.** Open Command Prompt and run `ipconfig`. Look
   for "IPv4 Address" (e.g. `192.168.1.20`).
2. **Restart Vite with external access:**
   ```bash
   cd frontend-react
   npm run dev -- --host
   ```
   You'll see two URLs printed — the second one (Network) is the one you'll
   use on the phone.
3. **On the phone:** open Chrome (Android) or Safari (iOS) and visit
   `http://YOUR-PC-IP:5173/scan` (e.g. `http://192.168.1.20:5173/scan`).
4. **Sign in.** Create a staff or scanner-role user from the admin
   dashboard first if you don't want to share the super-admin login.
5. **Grant camera permission** when the browser asks.

### Daily usage

- Point the camera at any guest's QR code (printed or on their phone screen)
- Audible beep + green overlay = checked in successfully
- Red overlay = duplicate scan (they've already entered) or invalid QR
- Lost network? It keeps scanning — tokens queue locally and auto-sync when
  WiFi comes back
- Tap **Walk-in lookup** to find attendees by name/email and download their
  badge or certificate

### Install as a home-screen app (optional)

In Chrome on the phone, after opening the scanner URL, tap the menu
(⋮) → **Add to Home screen**. Now it launches full-screen like a native app.

---

## Creating events

1. From the dashboard, click **+ Create event**
2. **Step 1 — Details:** title, slug (URL), description, venue
3. **Step 2 — Schedule & Capacity:**
   - Start/end times (in UTC)
   - Capacity (0 = unlimited)
   - **Price** (leave blank for FREE) + currency
   - **Minimum age** (0 = no limit)
   - **Organizer name** (appears on tickets)
4. **Step 3 — Registration Form:** add custom fields the guest must fill in
   (text, select, checkbox, file upload). Each field supports validation and
   conditional visibility (e.g. show "Company name" only if "Registration type"
   = "Corporate").
5. **Step 4 — Review** → click **Create event**
6. Back on the dashboard, click **→ published** to make the event public
7. Share the URL: `http://your-domain/e/<slug>`

When someone registers, they instantly get the e-ticket PDF by email and a
download button on the confirmation screen.

---

## Project structure

```
smart-event-platform/
├── database/
│   ├── schema.sql              # Full schema for fresh installs
│   └── migration_v2.sql        # Upgrade script for existing data
├── backend-php/
│   ├── api/
│   │   ├── index.php           # Front controller (router)
│   │   └── .htaccess           # Apache rewrites
│   ├── config/
│   │   ├── database.php        # PDO factory
│   │   └── credentials.php     # .env loader
│   ├── src/
│   │   ├── Controllers/        # Auth, Event, Registration, Scanner, Settings
│   │   ├── Middleware/         # AuthMiddleware (RBAC), RateLimiter
│   │   └── Utils/              # MailService, PdfService, QrService,
│   │                           # XlsxWriter, Token, Settings, etc
│   ├── templates/
│   │   ├── ticket.html         # GoWild-style e-ticket
│   │   ├── badge.html          # Compact CR80 / lanyard badge
│   │   └── certificate.html    # Certificate of attendance
│   ├── cron/
│   │   └── mail_queue_worker.php
│   ├── composer.json
│   └── .env.example
└── frontend-react/
    ├── public/
    │   ├── manifest.json
    │   └── service-worker.js
    ├── src/
    │   ├── api/client.js               # Axios + IndexedDB offline queue
    │   ├── components/                 # Button, Sidebar, FormRenderer,
    │   │                               # AnalyticsChart, CameraFeed
    │   ├── contexts/AuthContext.jsx
    │   ├── pages/
    │   │   ├── Login.jsx
    │   │   ├── Admin/
    │   │   │   ├── EventsDashboard.jsx
    │   │   │   ├── EventWizard.jsx
    │   │   │   └── SettingsPage.jsx
    │   │   ├── Mobile/PwaScannerPortal.jsx
    │   │   └── Public/RegistrationPortal.jsx
    │   ├── App.jsx
    │   ├── main.jsx
    │   └── index.css
    ├── package.json
    └── vite.config.js
```

---

## API reference (selection)

### Authentication
- `POST /auth/login` — returns JWT + sets HttpOnly cookie
- `GET /auth/me` — current user
- `POST /auth/logout`

### Events
- `GET /events` — list (auth)
- `POST /events` — create
- `GET /events/:id` — show
- `PUT /events/:id` — update
- `PATCH /events/:id/status` — change lifecycle (draft/published/live/paused/archived)
- `POST /events/:id/clone`
- `GET /events/:id/analytics` — telemetry + recent registrations
- `PUT /events/:id/form` — save form schema
- `GET /events/:id/export` — CSV
- `GET /events/:id/export/xlsx` — Excel
- `GET /events/:id/export/pdf` — PDF report
- `GET /events/public/:slug` — public event info

### Registration
- `POST /register/:slug` — public registration
- `POST /events/:id/walkin` — staff walk-in
- `GET /registrations/:id/ticket.pdf` — download e-ticket
- `GET /registrations/:id/badge` — print badge (CR80/thermal/lanyard)
- `GET /registrations/:id/certificate` — certificate of attendance
- `POST /registrations/:id/resend` — re-queue confirmation email
- `GET /ticket/:id` — HTML ticket fallback

### Scanner
- `POST /scan` — check-in
- `POST /scan/sync` — offline batch sync
- `GET /scan/lookup?q=` — search attendees

### Settings (super_admin)
- `GET /settings` — current SMTP config (password masked)
- `PUT /settings/smtp` — save SMTP credentials
- `POST /settings/smtp/test` — send test email

---

## Going live (production checklist)

When you're ready to deploy beyond XAMPP:

- [ ] Change the default admin password (Settings page or directly in DB)
- [ ] Generate strong `JWT_SECRET` and `QR_SECRET` (long random strings)
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] Use a real domain with HTTPS (Let's Encrypt is free)
- [ ] Build the frontend: `cd frontend-react && npm run build` → serve `dist/`
- [ ] Update `vite.config.js` proxy and `FRONTEND_URL` in `.env`
- [ ] Set up the email worker as a real cron job (every minute)
- [ ] Use a dedicated MySQL user (not `root`)
- [ ] Enable Apache's `mod_security` or equivalent WAF
- [ ] Set up regular database backups (`mysqldump`)

---

## Default roles

| Role | Can do |
|------|--------|
| `super_admin` | Everything: all events, all registrations, user management, settings |
| `event_manager` | Manage own events, full operational control inside assigned events |
| `staff` | Attendee lookups, manual check-ins, walk-in registration, badge printing |
| `scanner` | PWA camera scanning only — no dashboard access |

---

## Troubleshooting

**"Composer install fails with security advisory"**
Add `"audit": { "block-insecure": false }` to `composer.json`, then re-run.

**"White screen after login"**
Check the browser console (F12). The most common cause is the backend not
running — visit http://localhost/backend-php/api directly to confirm.

**"Test email fails with authentication error"**
For Gmail, you **must** use an [App Password](https://myaccount.google.com/apppasswords),
not your normal Google password. Make sure 2-factor authentication is on
first, then generate the 16-character app password.

**"Scanner camera won't open on phone"**
Modern browsers require HTTPS for camera access — **except** for `localhost`
and local network IPs. If you're hitting your PC's LAN IP from the phone (e.g.
`http://192.168.1.20:5173`), it should work. For deployment, you'll need HTTPS.

**"QR scans but says invalid"**
Make sure both the backend that issued the ticket AND the backend the scanner
talks to are using the **same** `QR_SECRET` in `.env`. The QR is a signed JWT;
different secrets = signature mismatch = rejected.

---

## License

MIT — adapt freely for your events.
