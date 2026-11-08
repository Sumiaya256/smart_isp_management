# Backend Starter (PHP + MySQL)

This project currently has static HTML pages. This backend starter is the first step to make the system dynamic.

## 1) Database Setup
1. Open phpMyAdmin in XAMPP.
2. Import `backend/sql/001_initial_schema.sql`.
3. Confirm database `isp_management` is created.

## 2) Environment Setup
1. Copy `backend/.env.example` to `backend/.env`.
2. Update DB credentials if needed.

Example:
- DB_HOST=127.0.0.1
- DB_PORT=3306
- DB_NAME=isp_management
- DB_USER=root
- DB_PASSWORD=

## 3) Test DB Connection
Open this URL in browser:
- `/Smart-ISP-Business-Operations-Management-System-main/backend/public/health_db.php`

Expected result:
- JSON response with `ok: true`

## 4) Next Implementation Step
Start with Auth module:
- Login API
- Password hashing
- Role-based middleware
- Connect `login.html` to backend login endpoint

## 5) Authentication Endpoints
- POST `backend/public/auth/login.php`
	- Body: `{ "email": "admin@promee.local", "password": "Admin@12345" }`
- POST `backend/public/auth/logout.php`
- GET `backend/public/auth/me.php`

Auth uses PHP session storage and role data from database.

## 6) Seed Default Admin User
Run from project root:

`php backend/scripts/seed_admin.php`

Default credentials created if not present:
- Email: `admin@promee.local`
- Password: `Admin@12345`

After first login, update this password from your user management flow.

## 7) Role-Protected Demo Endpoints
- GET `backend/public/protected/admin_only.php` (Admin only)
- GET `backend/public/protected/billing_ops.php` (Admin, Administration, Bill Collector)

## 8) User Management APIs
- GET `backend/public/users/roles.php`
- GET `backend/public/users/list.php`
- POST `backend/public/users/create.php`
	- Body: `{"full_name":"Name","email":"mail@example.com","phone":"017...","password":"Secret@123","role_id":4}`
- POST `backend/public/users/update.php`
	- Body: `{"user_id":2,"full_name":"Updated","email":"mail@example.com","phone":"018...","role_id":5,"is_active":true}`
- POST `backend/public/users/deactivate.php`
	- Body: `{"user_id":2}`
- POST `backend/public/users/reset_password.php`
	- Body: `{"user_id":2,"new_password":"NewSecret@123"}`

All user management endpoints require an authenticated session and proper role permission.

## 9) Client Management APIs (Initial)
- POST `backend/public/clients/create.php`
	- Body example:
	- `{"first_name":"Md","last_name":"Rahman","phone":"017...","email":"rahman@example.com","address":"Road 12","area":"Ward 5","city":"Dhaka","postal_code":"1207","package_slug":"standard","installation_date":"2026-03-23"}`
- GET `backend/public/clients/list.php`
	- Query params (optional): `search`, `zone`, `ward`, `client_code`
- GET `backend/public/clients/packages.php`

## 10) Frontend Integration Status
- `login.html` connected to auth login endpoint.
- `add_new_client.html` connected to client create endpoint.
- `client_list.html` connected to client list endpoint with live search.

## 11) Users Management Page
- Page: `users_management.html`
- Features:
	- List users
	- Create user
	- Edit user
	- Reset password
	- Deactivate user
- Connected endpoints:
	- `backend/public/users/roles.php`
	- `backend/public/users/list.php`
	- `backend/public/users/create.php`
	- `backend/public/users/update.php`
	- `backend/public/users/reset_password.php`
	- `backend/public/users/deactivate.php`

## 12) Billing APIs (Initial)
- POST `backend/public/billing/create_invoice.php`
	- Body: `{"client_id":1,"billing_month":"2026-03-01","due_date":"2026-03-31"}`
- POST `backend/public/billing/collect_payment.php`
	- Body: `{"invoice_id":1,"amount":400,"method":"cash","transaction_ref":""}`
- GET `backend/public/billing/list_invoices.php`
	- Query params (optional): `client_id`, `status`

`client_list.html` now receives real `billing_status` from latest invoice data via `backend/public/clients/list.php`.

## 14) Employee API (Initial)
- POST `backend/public/employees/create.php`
	- Body: JSON from `add_employee.html` form fields
	- Persists core data in `employees`
	- Persists extended profile data in `employee_profiles`

## 13) Global Logout Wiring
- Header Log Out buttons now call `backend/public/auth/logout.php` through shared script `assets/js/script.js`.
- After API call, user is redirected to `login.html`.
