# AutoDoor Technician Portal Handover (2026-03-22)

## Location and Branch
- Repo: `C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\site`
- Active branch: `codex/drawer-backdrop-zindex`
- Main file under active development:
  - `C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\site\wp-content\themes\ado-modern\inc\ado-portal\ado-technician-portal-app.php`
- Latest pushed commit at handoff:
  - `4e9fc163306a5a11291ec7a0b80a73a90bbc121f`

## How to Reach the Feature
- Technician portal base:
  - `http://localhost:8080/technician-portal/`
- Project route:
  - `http://localhost:8080/technician-portal/?view=project&project_id=<order_id>`
- Door drawer route:
  - `http://localhost:8080/technician-portal/?view=project&project_id=<order_id>&door_id=<door_id>`

## What Was Implemented
- Technician-native project route with assignment-gated access (no data leak on invalid/unassigned project).
- Sidebar project selector and doors-first project workspace.
- Slide-in door drawer workflow UI.
- Door hardware hydration from scoped quote payload.
- Project/door activity panels and in-portal link routing updates.
- Hardware and testing workflow in drawer:
  - grouped hardware lines by category
  - per-hardware notes/media
  - install state controls
  - testing notes + final video
  - completion rules requiring required conditions
- Information section is collapsible and defaults closed.
- Unconfirm controls for site prep/hardware availability with reason capture.
- Autosave flow for drawer changes (manual save button removed).

## Current Drawer Behavior (Latest State)
- Drawer width is `50vw` desktop, mobile remains full-width.
- Unconfirm controls live above Information.
- Hardware section shows category titles + model rows.
- Installed control and video upload were redesigned to be more interactive:
  - installed has live on/off status behavior
  - final video area supports select + drop + status messaging
- Changes in drawer save automatically.

## Recent Relevant Commits
- `4e9fc163` tech portal: widen door drawer to half-screen on desktop
- `916a8f9b` tech portal: redesign install and video controls for calm dark workflow
- `ed74135b` tech portal: autosave door drawer and fix unconfirm reason expansion
- `eb27e713` tech portal: simplify hardware category layout and restyle install/video controls
- `26f6f6ad` tech portal: move unconfirm controls above info with required reason fields
- `170445a1` tech portal: replace prep confirmations with unconfirm action buttons
- `10e7d924` tech portal: make Information panel collapsible and closed by default
- `496b3c98` tech portal: require hardware installed checks for door completion
- `f91fecaa` tech portal: redesign door drawer workflow and testing sections
- `926fea98` tech portal: hydrate door hardware from scoped quote data
- `9343b3bb` tech portal: add project door slide-in hardware workspace
- `a2a8e0ac` tech portal: add sidebar project selector and doors-first workspace
- `b415a5ec` tech portal: add project activity panel and in-portal project links

## Validation Approach Used
- Repeated lint on the active file:
  - `docker exec autodoorexperts_wp php -l /var/www/html/wp-content/themes/ado-modern/inc/ado-portal/ado-technician-portal-app.php`
- Manual route and interaction checks in technician portal.

## Known Constraints and Risks
- Keep Woo order workflow/backbone intact.
- Keep assignment gate model (`_ado_technician_ids`) intact.
- Avoid broad refactors; keep slices narrow and push after each accepted slice.
- There are unrelated modified/untracked files in this repo; do not bundle those into technician portal commits.

## Prompt for Incoming Senior
```
You are taking over the AutoDoor technician portal project-drawer milestone.

Work in:
C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\site

Start on branch:
codex/drawer-backdrop-zindex

Read handover context first:
C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\site\wp-content\themes\ado-modern\inc\ado-portal\TECH_PORTAL_HANDOVER_2026-03-22.md

Primary implementation file:
C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\site\wp-content\themes\ado-modern\inc\ado-portal\ado-technician-portal-app.php

Primary test route:
http://localhost:8080/technician-portal/?view=project&project_id=<order_id>&door_id=<door_id>

Keep Woo backbone and technician assignment security unchanged.
Continue iterating on drawer UX/behavior with small, testable slices.
After each accepted slice: commit + push to shared branch before continuing.
```
