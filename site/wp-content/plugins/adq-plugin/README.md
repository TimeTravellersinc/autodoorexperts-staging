# AutoDoor Experts — ADQ Portal MVP Plugin (Blueprint v2.0)

This plugin implements the core **workflow + data model + permissions + state machines** described in:
**“AutoDoor Experts — Portal MVP Deep Dive Blueprint v2.0 (March 2026)”**. fileciteturn2file0

## What’s implemented (Solution B / blueprint-correct)

### CPTs (Custom Post Types)
- `adq_quote`
- `adq_project`
- `adq_door` (hierarchical, parent = project)
- `adq_hardware` (hierarchical, parent = door)
- `adq_note` (CPT, not serialized meta array)
- `adq_visit`
- `adq_worklog` (cannot be deleted)
- `adq_product`

### Baseline / Live split
- Any meta key prefixed `baseline_` is **immutable after first write**
- `quote_json_snapshot` is **immutable after first write**
- `live_` keys are mutable

### State machines + audit log
- Quote: `draft → sent → approved → po_received → converted` (+ declined/revised/void/extraction_failed)
- Project: `new → scheduled_pending → scheduled_confirmed → in_progress → blocked → substantially_complete → closed` (+ cancelled)
- Door: `not_started → in_progress → needs_parts → complete → deficiency` (+ `n_a_no_operator`)
- Visit: `soft_booked → confirmed → in_progress → complete → cancelled` (+ rescheduling)

All transitions must go through `ADQ_State_Machines::transition_*()` which:
1) validates the transition
2) writes to the audit table **before** mutation
3) applies the meta update

Audit table: `wp_adq_audit` (InnoDB).

### Automatic Project Seeding (Blueprint Stage 3 → Stage 4)
When a quote transitions to `po_received`, a `save_post`-safe hook fires and:
- reads `quote_json_snapshot` (frozen)
- creates project/door/hardware hierarchy
- sets quote to `converted` and writes `converted_project_id`

## REST endpoints

### Polling (Stage 1 support)
`GET /wp-json/adq/v1/quote-status/{quote_id}`  
Returns `{ "quote_id": 123, "quote_status": "draft" }`

### Client approval + PO upload (Stage 3)
`POST /wp-json/adq/v1/quote/{quote_id}/approve`

- Requires login
- Requires ownership (client_id matches current user)
- Requires nonce: `X-ADQ-Nonce` header created via `wp_create_nonce('adq_approve_quote_{id}')`
- Expects multipart form-data:
  - `po_number` (string)
  - `po_file` (file)

On success:
- `quote_status` transitions `sent → approved → po_received` (audit logged)
- PO stored under `wp-content/uploads/adq-private/po/{quote_id}/`
- seeding triggered automatically

## Where files are stored (private upload convention)
- PO documents: `wp-content/uploads/adq-private/po/{quote_id}/...`

> Note: This plugin stores files under `uploads/adq-private/...`. Lock down direct access via server rules
> (e.g., deny in nginx/apache) as appropriate for your environment.

## Dev/testing (wp-admin)
- Quotes include an admin metabox to view:
  - `quote_status`
  - `quote_json_snapshot` (editable only if empty; then locked)

Projects/Doors/Hardware are created automatically and can be verified in list screens. Meta is stored but
the plugin does not attempt to expose every meta key in admin (you can add that later).

## File/Folder Map
- `adq-plugin.php` — bootstrap + hooks
- `includes/class-adq-post-types.php` — CPT registration + roles/caps + quote metabox
- `includes/class-adq-audit.php` — audit table install + insert helper
- `includes/class-adq-immutability.php` — enforces immutable meta rules
- `includes/class-adq-state-machines.php` — transition validation + audit-first updates
- `includes/class-adq-project-seeder.php` — Stage 4 seeding from frozen snapshot
- `includes/class-adq-rest.php` — REST routes

## Activation
On activation:
- Creates `wp_adq_audit` (InnoDB)
- Registers roles (client/technician/accounting) and grants caps to admins

---

## Minimal snapshot JSON shape for seeding

Your `quote_json_snapshot` MUST be JSON with a top-level `"result"` object:

```json
{
  "result": {
    "door_count": 1,
    "doors": [
      {
        "door_id": "101",
        "desc": "Front Entrance",
        "door_type": "Single",
        "header_line": "Door 101 — Front Entrance",
        "_scope_operator_signals": ["OPERATOR"],
        "items": [
          {
            "_scope_kept": true,
            "catalog": "LCN 4040XP",
            "desc": "Closer",
            "qty": 1,
            "finish": "689",
            "raw": "LCN 4040XP 689",
            "_scope_signals": ["CLOSER"]
          }
        ]
      }
    ]
  }
}
```

---

## Notes
This is a backend “core” plugin. Frontend pages/shortcodes (client-quotes, tech-dashboard, scheduling UI) can be layered on later.

