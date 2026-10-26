# Wildlife catalog v2

The catalog contains 184 species: 60 birds, 44 small animals and 80 butterflies.
The paired transparent PNGs are imported without modification. Artwork determines
identity, including the butterflies `albatross` and `peacock`. Replaced SVG assets
and their generation workflow are retired.

## Contract

- The client and PHP manifests and migration 016 carry the same ecology metadata:
  category, Northern Hemisphere solar-term seasons, local periods, suitable and
  preferred weather, placement (`tree`, `grass`, `sky`) and motion.
- Butterflies appear during morning/day in clear/cloudy weather, in their listed
  seasons. Tropical species may appear all year. No ecological match returns a
  null encounter and another check 15–30 minutes later.
- Placement controls all anchors, including original custom animations. Tree
  anchors follow stages 1–5. The doubled visual scale and bounded hit areas remain.
- Explicit `catalog_version=2` selects 184 species. An omitted version selects
  the 107 IDs shared by the old and new catalogs. Legacy restores return current
  compatibility state without writing inventory or discoveries.
- Versionless/old local caches and cloud backups cannot restore into v2, in either
  additive or replacement restore mode. Current-version restores remain cumulative.
- The backpack displays location, and encyclopedia categories are birds, small
  animals and butterflies, in that order.

## Production deployment, 2026-10-26

Five changed files were uploaded to the existing site root: both catalog configs,
`api/_app/wildlife.php`, and the catalog/state routes. Encounter, capture and restore
routes were unchanged and verified. FTP SIZE and downloaded SHA-256 matched all
eight files. Credentials were supplied only through process memory/environment.

Migration 016 installed 107 v1 and 184 v2 database entries. The one-time reset script
backs up rows and resets state in one transaction, checks the result before commit,
and refuses another reset once v2 entries exist.

| Table | Before | Immediately after reset |
| --- | ---: | ---: |
| wildlife_encounter_state | 6 | 0 |
| wildlife_inventory | 195 | 0 |
| wildlife_discoveries | 23 | 0 |
| wildlife_capture_operations | 25 | 0 |
| wildlife_catalog_settings | 0 | 0 |
| wildlife_catalog_entries | 0 | 291 |

All six backup tables remain on the server with suffix `_backup_20261026_122409`
(UTC). Keep these backups for rollback. Restoring a prior deployment requires the
matching prior API/config files and all corresponding database tables; do not rerun
the reset script. Stop wildlife writes while restoring the backup set.

## Verification

- All 368 PNGs decode, have transparency, match the exact original SHA-256, and
  occupy exactly the two expected 184-file source directories.
- PHP tests verify both catalogs, SQL seed parity, version negotiation, strict
  ecology, empty encounter responses, captures and legacy restore protection.
  All 17 PHP test scripts passed.
- Related .NET tests: 78 passed. Full x64 suite: 5263 passed, zero failures/skips.
  Animation tests cover all 184 species at stages 1–5 and transformed hit bounds.
- Live production HTTP returned 107 known legacy IDs and 184 matching v2 entries.
  An authenticated temporary account verified zero inventory, no-op legacy restore,
  storm/no-animal scheduling, a skunk encounter, capture, idempotent retry and state
  refresh (quantity remained 1). Clear/storm weather observations were synthetic;
  HTTP routes, server clock and database were real. Only that account's cooldown
  was advanced through SQL. The temporary account/session and wildlife rows were
  removed afterward. Production inventory, discoveries and capture rows were zero
  after cleanup; active clients had legitimately recreated four scheduling rows.
- Debug was rebuilt and exactly one process was started from
  `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`.
- Independent standards and specification reviews finished with zero unresolved
  findings. A duplicate anchor calculation identified during review was unified.
- The local signed-in account refreshed its v2 cache to 184 entries with zero
  quantity/discoveries. Unit-test fixture caches use isolated temporary directories.
- No installer or GitHub Release was published. The remote `update/stable.json`
  stayed at SHA-256 `2aca978297b83244a19d9c6399022d2f40e3234648868b3fd44a7bfa37c2edde`.

Local detailed evidence is under `.codex-test-temp/wildlife-production/`, PNG
contact sheets under `.codex-test-temp/wildlife-v2/`, and the full test result under
`.codex-test-temp/wildlife-v2-tests/wildlife-v2-full.trx`. These temporary files are
not part of the commit.
