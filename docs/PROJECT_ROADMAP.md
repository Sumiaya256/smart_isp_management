# ISP Management System Roadmap

## Phase 0: Foundation and Planning (Week 1)
- Freeze module scope from SRS and map each HTML page to a backend module.
- Finalize database naming conventions, status enums, and role permissions.
- Set up Git branching strategy and environment plan (local/staging/production).
- Define API response format and error standard.

## Phase 1: Database and Backend Core (Week 1-2)
- Design and create MySQL schema for all major modules.
- Add reusable database connection layer (PDO + environment config).
- Create authentication foundation (users, roles, password hashing, login session/JWT).
- Add seed data for default roles and admin account.

## Phase 2: Auth and Access Control (Week 2)
- Implement login API and logout flow.
- Implement role-based access middleware for Admin, Administration, Bill Collector, and Support/Technician.
- Enforce module-level permissions from SRS.
- Connect login page to backend auth endpoint.

## Phase 3: Client and Billing Modules (Week 3-4)
- Build APIs for add client, client list, left client, and filters.
- Build package and billing invoice generation APIs.
- Build payment collection and payment status update APIs.
- Add due notification query/jobs for upcoming bill expiry.

## Phase 4: HR, Payroll, and Leave (Week 4-5)
- Build APIs for employees, departments, positions, attendance.
- Build payhead and payroll calculation APIs.
- Build leave application and approval workflow APIs.
- Add salary sheet generation endpoints.

## Phase 5: Support and Service Operations (Week 5-6)
- Build complaint category and ticket management APIs.
- Add ticket assignment, status updates, and resolution history.
- Add service history lookup by client.
- Build ticket reporting endpoints.

## Phase 6: Inventory, Asset, Purchase, Finance (Week 6-7)
- Build suppliers, purchases, and stock movement APIs.
- Build inventory item and asset tracking APIs.
- Build bandwidth purchase/usage records.
- Build income and expense reporting with profit visibility rules.

## Phase 7: Mikrotik Integration and Automation (Week 7-8)
- Integrate Mikrotik API (connection, auth, command wrapper).
- Sync active users and service status with billing state.
- Implement enable/disable user access based on invoice status.
- Add safe retries and operation logs.

## Phase 8: Frontend Integration and QA (Week 8-9)
- Replace static table/form data with API integration page by page.
- Add validation and user-friendly error messaging.
- Run module-level testing and cross-role UAT.
- Fix regressions and optimize load time.

## Phase 9: Deployment and Monitoring (Week 10)
- Prepare production database migration scripts.
- Deploy backend and web frontend.
- Add logging, backup schedule, and health checks.
- Handover documentation and maintenance checklist.

## Immediate Next Sprint (Start Now)
1. Create DB and run initial schema script.
2. Configure backend environment file and test DB connection endpoint.
3. Implement Auth module first (users, roles, login).
4. Connect login page to real login API.
