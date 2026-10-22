# MediCare HMS: Hospital Management System (PHP)

A complete, framework-free hospital management system written in plain PHP 8 with PDO.
It runs on **MySQL/MariaDB** (e.g. XAMPP/WAMP/LAMP) or on **SQLite** with no setup at all.

## Features

| Module | What it does |
| --- | --- |
| **Dashboard** | Live stats (patients, doctors, today's appointments, admissions, free beds, revenue), today's schedule and recent patients. Doctors see their own numbers. |
| **Patients** | Register, edit, search and paginate patients (auto patient numbers like `PT-000001`). A full profile shows medical history, appointments, admissions and invoices. |
| **Appointments** | Book, edit, complete or cancel. Filter by date, status or doctor. Double-booking a doctor is blocked. |
| **Medical Records** | Doctors record symptoms, diagnosis, prescription and notes, either directly or from an appointment ("Consult"). Records are printable. |
| **Wards & Beds** | Create wards with daily rates, generate beds, and see live bed occupancy. |
| **Admissions** | Admit a patient to an available bed, then discharge them. Discharging frees the bed and opens a pre-filled invoice for the stay (bed days plus doctor reviews). |
| **Billing** | Invoices with any number of line items, live totals, payment recording (cash, card, mobile money, insurance…), and print-friendly invoices. |
| **Doctors & Departments** | Manage doctors (fees, specialization, department), with an optional login account for each doctor. |
| **Users** | Admins manage staff accounts and roles, and link doctor logins to doctor profiles. |
| **Profile** | Every user can change their own password. |

### Roles

- **Admin**: everything.
- **Receptionist**: patients, appointments, admissions, wards (view), billing, doctors (view).
- **Doctor**: their own appointments and admitted patients, patient profiles, and medical records.

### Security

Passwords are hashed with `password_hash`. Every query is a prepared statement. All output is escaped. Every form is CSRF-protected. Session IDs are regenerated on login. Role checks run on the server for every page and action. `.htaccess` files block web access to `includes/`, `database/` and `data/`.

## Installation

### Option A: quick start (SQLite, no database server)

```bash
git clone <this repo> hospital
cd hospital
php -S localhost:8000
```

Open http://localhost:8000. The database (`data/hospital.sqlite`) and demo data are created automatically on the first request.
Requires the `pdo_sqlite` extension (enabled by default in most PHP builds).

### Option B: XAMPP / MySQL

1. Copy the project folder into `htdocs/` (e.g. `C:\xampp\htdocs\hospital`).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Edit `config.php`:
   ```php
   define('DB_DRIVER', getenv('HMS_DB_DRIVER') ?: 'mysql');
   ```
   and adjust `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` if needed (XAMPP's defaults are `root` with an empty password).
4. Visit http://localhost/hospital. The database `hospital_db` and all tables are created automatically.
   If you prefer, you can import `database/schema.mysql.sql` manually in phpMyAdmin first.

You can also set the settings with environment variables instead of editing the file: `HMS_DB_DRIVER`, `HMS_DB_HOST`, `HMS_DB_PORT`, `HMS_DB_NAME`, `HMS_DB_USER`, `HMS_DB_PASS`.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Admin | admin@hospital.com | admin123 |
| Doctor | doctor@hospital.com | doctor123 |
| Receptionist | reception@hospital.com | reception123 |

**Change these passwords (Profile → Change Password) before using the system for real.**

## Project structure

```
├── config.php            # App & database settings
├── index.php             # Dashboard
├── login.php / logout.php / profile.php
├── patients.php          # Patients CRUD + profile
├── appointments.php      # Appointment booking & status
├── records.php           # Medical records / prescriptions
├── wards.php             # Wards & beds
├── admissions.php        # Admit / discharge
├── billing.php           # Invoices & payments
├── doctors.php / departments.php / users.php
├── includes/             # bootstrap, db (auto-install + seed), helpers, layout
├── database/             # schema.mysql.sql, schema.sqlite.sql
├── assets/style.css
└── data/                 # SQLite database file (created at runtime)
```

To change the currency symbol or time zone, edit `CURRENCY` and `date_default_timezone_set` in `config.php`.
