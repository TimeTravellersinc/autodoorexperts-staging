# ADO Portal Test Cases (Quote-First, Parser-Only Custom Plugin)

## Scope
- Quote-first client workflow with PDF drag/drop parser integration
- WooCommerce as quote/order/project backbone
- Client and technician portals built with Elementor
- Role/security boundaries and data isolation
- Wave invoice visibility on client dashboard
- Future-ready admin staff area foundations

## Constraint
- Only custom plugin in active use: `AutoDoor PDF Parser DEBUG (Adaptive Parser)`
- Any other custom behavior implemented with core settings + supported plugins + snippets

## Roles
- `administrator`
- `client`
- `technician`
- `admin_staff` (or equivalent managed role)

## Test Data
- Existing hardware schedule PDFs from `C:\Users\marcr.TIME_MACHINE\Downloads\`
- One client account, one technician account, one admin/staff account
- WooCommerce product catalog mapped to common operator hardware models
- One calendar embed URL (Google Calendar)
- One invoice sample set (Wave sync/import/manual mock)

## Functional Cases
1. `AUTH-001` Role login routing
- Client lands on Client Dashboard.
- Technician lands on Technician Portal.
- Admin/admin_staff lands on Admin Dashboard.

2. `AUTH-002` Quote generator access restriction
- Client can access `New Quote`.
- Technician and anonymous users are blocked from quote generation.

3. `QUOTE-001` PDF parse output generation
- Client drags/drops hardware schedule PDF.
- Parser generates parser JSON + scoped JSON + pricing debug/quote file.

4. `QUOTE-002` Parser-to-Woo cart autofill
- Parsed/scoped hardware items are mapped to Woo products.
- A cart is auto-populated with matched products and quantities.
- Unmatched lines are flagged for manual handling.

5. `QUOTE-003` Multiple carts as quotes
- Client can keep multiple saved carts/quote drafts.
- Client can rename each quote draft.
- Client can reopen/edit/remove draft quotes.

6. `QUOTE-004` Manual quote fallback
- Client can manually add items when parser mapping is incomplete.
- Manual edits persist to the quote draft/cart.

7. `ECOM-001` Quote -> order with PO
- Client checks out quote cart to place order (treated as approved quote/project start).
- PO/reference is captured on order.

8. `ECOM-002` Order tracking renamed as project tracking
- Client-facing labels show `Project` / `Project Tracking` instead of `Order`.
- Placed quote orders appear in project tracking views.

9. `PROJ-001` Project detail visibility
- Client can view project-level details including door/hardware structure, notes, photos, status.
- Data is sourced from stored JSON and ongoing technician updates.

10. `TECH-001` Technician workspace
- Technician sees only assigned projects.
- Technician can add notes, upload photos, and log hours.

11. `TECH-002` Schedule visibility
- Technician portal displays Google Calendar availability/schedule widget.
- Client portal shows upcoming visits.

12. `INV-001` Invoice card and list (Wave)
- Client dashboard shows outstanding invoice total and count.
- Client can view pending/overdue statuses with links/reference IDs.

13. `DASH-001` Required client dashboard cards
- Outstanding invoices / amounts
- Upcoming scheduled visits
- Critical/high-priority project notes

14. `SEC-001` Data isolation
- Client A cannot access Client B quotes/projects/invoices.
- Technician cannot access client quote creation.

15. `SEC-002` Upload hardening
- Quote upload accepts only PDF.
- Size limit and clear error handling are enforced.

16. `NFR-001` Performance smoke
- Warm page transitions target <3s locally.
- Parse-to-result response remains within configured timeout envelope.

17. `ADMIN-001` WordPress editability
- Dashboard layout/cards editable via Elementor.
- Pricing/mapping/settings manageable from WP admin without deploying code.

## Acceptance Gate
- No P1/P2 permission leakage.
- PDF drag/drop can produce a quote cart autofill path.
- Multiple quote drafts exist and are manageable.
- Checkout converts quote to project-tracked workflow.
- Client dashboard required cards render correctly.
- End-to-end happy path: PDF -> autofilled quote cart -> client approval/order + PO -> project tracking.
