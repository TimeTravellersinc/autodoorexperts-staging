# AGENTS.md - AutoDoor Experts Portal Engineering Guide

## 1) Mission
Build and maintain a WordPress-based operations portal for AutoDoor Experts that supports:
- Client quote generation from hardware schedule PDFs
- Quote approval to project conversion
- Project tracking at door/hardware detail
- Technician execution workflows (schedule, notes, photos, timesheets)
- Invoicing visibility (Wave-linked status metadata)

Primary objective: ship reliable business workflows with minimal custom code, using WordPress/WooCommerce/Elementor first, and custom code only where necessary.

## 2) Current Environment
- Local workspace root: `C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging`
- Runtime: Docker Compose (`autodoorexperts_wp`, `autodoorexperts_db`, `autodoorexperts_pma`)
- WordPress URL: `http://localhost:8080`
- Active custom theme: `site/wp-content/themes/ado-modern`
- Git remote: `origin` -> `https://github.com/TimeTravellersinc/autodoorexperts-staging.git`
- Collaboration base branch: `codex/wordpress-staging-setup`

## 3) Product Scope and Roles
### 3.1 Roles
- `client` / `customer`: create quotes, approve/checkout, view projects/schedule/invoices
- `technician`: view assigned projects, log field notes/photos/hours
- `admin_staff`: internal operations support
- `administrator`: full access

### 3.2 Portal Entry Pages
- Client shell: `/client-dashboard/` (query-driven views)
- Technician shell: `/technician-portal/` (query-driven views)

## 4) Hard Rules (Do Not Break)
1. Keep **Elementor** as the primary page-builder experience.
2. Do not introduce new custom plugins unless explicitly requested.
3. Only approved custom parser plugin should remain: `AutoDoor PDF Parser DEBUG (Adaptive Parser)`.
4. Preserve existing Woo order metadata contracts (see Section 7).
5. Do not remove role-based access gates.
6. Never commit secrets (`.env`, credentials, production keys).
7. Never commit temp parser outputs / local dumps (`tmp_*`, ad-hoc JSON/SQL dumps).
8. Prefer reversible, incremental changes over invasive rewrites.

## 5) Architecture Snapshot
### 5.1 Theme Modules
`site/wp-content/themes/ado-modern/inc/ado-portal/`
- `ado-core-access.php`: roles, login redirects, protected-route guards
- `ado-quote-carts.php`: quote/cart custom behavior
- `ado-project-dashboards.php`: project metadata handling + technician log AJAX
- `ado-client-dashboard-app.php`: client SPA-like shell via shortcode
- `ado-technician-portal-app.php`: technician SPA-like shell via shortcode

### 5.2 Shortcodes (Core)
- `[ado_client_dashboard_app]`
- `[ado_technician_portal_app]`
- Supporting shortcodes in `ado-project-dashboards.php`

### 5.3 Rendering Model
- Elementor page contains a shortcode widget only
- Internal navigation uses `?view=<name>` to keep a single shell/layout

## 6) Preferred Implementation Strategy
1. Use WordPress/Woo/Elementor capabilities first.
2. Extend in-theme PHP only when needed.
3. Reuse existing data structures and AJAX endpoints.
4. Keep UI consistency between client and technician shells.
5. Keep performance practical on local Docker (avoid expensive per-request loops where possible).

## 7) Data Contracts (WooCommerce Order Meta)
Treat these keys as stable API unless migration is planned.

### 7.1 Quote/Project linkage
- `_ado_quote_draft_id`
- `_ado_scoped_json_path`
- `_ado_scoped_json_url`
- `_ado_project_status`

### 7.2 Scheduling and assignment
- `_ado_next_visit_date`
- `_ado_technician_ids` (comma-separated user IDs)

### 7.3 Field execution logs
- `_ado_tech_logs` (array of log objects)
  - `created_at`
  - `user_id`
  - `hours`
  - `priority` (`normal|high|critical`)
  - `note`
  - `attachment_url`
- `_ado_critical_notes` (aggregated high/critical note lines)

### 7.4 Invoicing metadata
- `_ado_wave_invoice_id`
- `_ado_wave_invoice_url`
- `_ado_wave_status` (`pending|overdue|paid|...`)
- `_ado_wave_amount_due`

### 7.5 Checkout/session bridges (if present)
- `ado_last_scope_url`
- `ado_last_scope_path`
- `ado_last_quote_draft_id`

## 8) Security and Quality Baseline
### 8.1 Security
- Sanitize all inbound request data (`sanitize_text_field`, `sanitize_key`, `sanitize_textarea_field`, etc.)
- Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` where applicable)
- Require and verify nonces for all write actions
- Enforce capability/role checks on every privileged endpoint

### 8.2 Code quality
- Prefix theme functions with `ado_`
- Keep functions focused and composable
- Avoid duplicate business logic between client/technician modules
- Prefer explicit arrays over hidden magic

### 8.3 Performance
- Avoid unnecessary full-order scans in repeated render paths
- Cache computed structures within request where feasible
- Keep generated markup and loops bounded (slice long lists)

## 9) Testing Protocol (Minimum)
Before finishing a task, run all relevant checks:

1. PHP lint on changed files in container
   - `docker exec autodoorexperts_wp php -l <file>`
2. Shortcode smoke tests (existing scripts)
   - `scripts/test-client-dashboard-app.php`
   - `scripts/test-technician-dashboard-app.php`
3. Route/access sanity
   - logged-out redirect behavior
   - client cannot access technician pages
   - technician cannot access client-only pages
4. Critical flow sanity
   - note save works
   - photo upload works
   - view switching preserves shell/theme

If a test cannot run, document exactly what was not run and why.

## 10) Multi-Agent Git Workflow
Use this when multiple Codex agents work in parallel.

### 10.1 Branching
- Base from: `origin/codex/wordpress-staging-setup`
- Task branch format: `codex/<area>-<short-topic>`
  - Examples:
    - `codex/technician-timesheet-fixes`
    - `codex/client-quote-parser-hooks`

### 10.2 Sync
- Always start with:
  - `git fetch origin`
  - `git checkout codex/wordpress-staging-setup`
  - `git pull`
- Then create your branch from that latest base.

### 10.3 Commits
- Keep commits scoped and atomic
- Message format:
  - `<area>: <what changed>`
  - Example: `technician-portal: add project-scoped photo grouping`

### 10.4 PR/Handoff expectations
- Include:
  - changed files
  - behavior changes
  - test evidence
  - known risks
- Never force-push over shared base branch

## 11) File and Artifact Hygiene
Do not commit:
- `tmp_*`
- ad-hoc JSON parser dumps
- local SQL export files
- runtime upload/cache directories
- local secret/config material

Keep `.gitignore` updated when new local artifacts appear.

## 12) Definition of Done (Per Task)
A task is done when all are true:
1. Functional requirement implemented
2. Access/security checks still pass
3. No PHP syntax errors in touched files
4. Relevant smoke tests pass
5. No temporary artifacts staged
6. Commit pushed to a `codex/*` branch with clear message
7. Handoff note includes exact verification performed

## 13) Escalation Triggers
Stop and ask for confirmation when:
- schema/data contract changes are required
- plugin set needs to change
- role/capability behavior needs to change
- destructive data operations are requested
- production deployment action is requested

## 14) Notes for Future Agents
- This project is workflow-heavy and stateful; regressions often come from metadata mismatch rather than UI.
- Maintain compatibility with existing order meta keys unless explicit migration is approved.
- Keep UI polish high, but do not trade away reliability of quote->project->field tracking.
