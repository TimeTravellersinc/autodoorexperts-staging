## Parser Exit Criteria

The parser work stops when the dominant hardware-schedule family is good enough for production use.

### In scope
- Heading-based and operator-style schedules like:
  - `Hardware-Schedule-1.pdf`
  - `Revised-Hardware-Schedule-1.pdf`
  - `Providence-Manor-Hardware-Schedule-Operators-only.pdf`
  - `Hardware-Schedule-for-ADO-Install-1.pdf`
  - `23-162-Hardware-SD_MCR-resubmit.pdf`
  - `Carleton-University-New-Student-Residence-Revised-Hardware-Schedule-January-31-2025.pdf`
  - `St-Joseph-St-Thomas-Hardware-Feb-28-2023-Revised-02.pdf`
  - `234 Laurier - Hardware schedule 2024.07.23.pdf`
  - `Cheo-hardware-schedule.pdf`

### Explicitly out of scope for this parser milestone
- `Hardware-Schedule-Low-Volt.-Install.pdf`
- `Queens-University-Leonard-Hall-Renovations-Hardware-Schedule.pdf`

Those formats need separate document-family strategies and should not block the normal-family parser milestone.

### Exit conditions
All of the following must hold:

1. Providence row boundaries remain fixed.
2. ADO Install note and revision contamination stay removed.
3. 23-162 narrative contamination stays removed from the target rows.
4. Carleton door `101` preserves full category phrases and wrapped continuations.
5. St Joseph and 234 Laurier no longer degrade obvious category rows into fragments.
6. Controls do not regress:
   - `Hardware-Schedule-1.pdf`
   - `Revised-Hardware-Schedule-1.pdf`
   - `Cheo-hardware-schedule.pdf`
7. Parser output is stable across repeated runs.

### Stop rule
Do no more than three focused parser cycles from the current baseline. After that:
- either the dominant family passes the exit criteria, or
- the remaining defects must be explicitly reclassified as separate unsupported format families.

### Validation entry point
Run the parser validation suite:

```powershell
docker cp "C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging\scripts\test-parser-output.php" autodoorexperts_wp:/var/www/html/scripts/test-parser-output.php
docker exec autodoorexperts_wp php -l /var/www/html/site/wp-content/plugins/autodoor-pdf-parser/includes/class-adx-parse.php
docker exec autodoorexperts_wp php -l /var/www/html/scripts/test-parser-output.php
docker exec autodoorexperts_wp php /var/www/html/scripts/test-parser-output.php
```

### Evidence required for every parser cycle
- exact changed file(s)
- full lint output
- full parser validation output
- before/after rows for the targeted defect
- explicit non-regression evidence for Providence, ADO Install, 23-162, and the control files
