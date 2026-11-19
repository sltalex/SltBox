# Wildlife vector artwork

The 180 stable IDs come from `src/DeskBox/Assets/Tree/Wildlife/catalog.json`.
`insects.py`, `birds.py` and `animals.py` explicitly define their appearance;
motion codes, hashes and random colors never choose anatomical features.

Each `Drawing` records at least three identifying traits. Paint the complete
animal with the primitives in `common.py`, using a 0–128 working coordinate
space. The renderer adds a safe transparent margin. External anatomy belongs
in the silhouette; eyes, patterns, highlights and internal feather lines do not.
Use natural large color patches and a recognizable silhouette at 24–32 pixels,
then secondary feather, fur, wing-vein or scale details at 54–96 pixels.

Generate the application SVGs:

```powershell
py -3.13 scripts/generate_wildlife_svgs.py
py -3.13 scripts/generate_wildlife_svgs.py --check
```

Render contact sheets and run raster validation (Pillow and Node.js with `sharp`
are required; `--node` and `--node-modules` can select an existing runtime):

```powershell
py -3.13 scripts/preview_wildlife_assets.py
```

The previews are local QA artifacts, not shipped PNG replacements. Inspect
the overview, actual-size light/dark/canopy sheets and near-species comparisons.
The validator checks transparent margins, small-size visibility, and duplicate
rendered artwork/silhouettes. WinUI loading remains a separate runtime check.
