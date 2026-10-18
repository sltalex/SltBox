# Aquarium realistic asset expansion brief

Status: 150 original SVGs and their transparent PNG exports are generated and
integrated locally. The PHP catalogue is version 4: 50 fish, 50 plants and 50
rockscapes, plus the existing 8 shrimp/crab/shellfish entries. No server deployment
or installer release was performed. The JSON in this directory is the offline
generation manifest, not a runtime catalogue source.

## Requested scope

- 50 fish varieties in the target collection, including large sharks and small
  schooling species such as sardines; retain existing inventory IDs.
- 50 distinct aquarium rock/rockscape designs, not 50 colour swaps of one shape.
- 50 aquatic plant/plant-form designs with identifiable foliage and growth habits.
- Naturalistic SVG style, as subsequently selected by the user: gradients,
  anatomical silhouettes, leaf veins and rock texture; not photographs.
- Existing crabs, shrimp, shellfish, user inventory and saved scenes stay intact.

## Production workflow decision

The user explicitly selected locally authored SVGs instead of an image API or
licensed photo cutouts. No image API, paid generation or credentials are needed.

Run the reproducible generator from the repository root:

```powershell
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/generate-aquarium-svg-assets.py
```

SVG masters are in `src/DeskBox/Assets/Aquarium/Svg/`; WinUI PNG exports are in
`src/DeskBox/Assets/Aquarium/Vector/`. Old root-level PNGs remain untouched. The
scene and shop prefer the reviewed vector export for known keys and retain the
original resource path for the background, legacy creatures and unknown keys.
There is no runtime SVG parsing, network dependency or new reflection binding.

Use `--check` to validate existing files without writing them. `--render-only`
re-exports PNGs without regenerating SVGs; `--category fish|plants|rocks` narrows
the work. Contact sheets are in `.artifacts/aquarium-svg-preview/`.

Do not ship arbitrary search results: an earlier automatic photo search confused
firefish with lionfish. All ten resulting draft images have been preserved outside
the application's asset glob under
`.artifacts/aquarium-expansion-unreviewed-20261018/`.

## Prompt templates

The following original art direction guided the SVG drawing implementations.
One distinct asset and one distinct subject per entry. Substitute the entry's
common name, scientific name where applicable, and identifying features.
The resulting files are naturalistic vector illustrations, not photographs.

### Fish

Use case: scientific-educational.
Asset type: photorealistic aquarium sprite.
Subject: one living {common_name} ({scientific_name}), with {identifying_features}.
Show the entire individual in a clear side profile, facing right, with its mouth,
tail, fins and natural body proportions intact. Use natural soft underwater
lighting, realistic scales or skin, subtle fin translucency and natural colour.
Isolate it on a genuinely transparent background with generous clear margins.
No scenery, other animals, seabed, rocks, bubbles, labels, watermark or cast shadow.
No cartoon eyes, toy/plastic look, exaggerated teeth, or cut-off fins.
Small schooling fish must remain single individuals; do not bake a school into
one sprite. This permits later instance-based animation without duplicated groups.

### Rockscapes

Use case: product-mockup.
Asset type: photorealistic aquarium hardscape sprite.
Subject: {rock_material} arranged as {distinct_structure}.
Show a complete, physically plausible miniature rockscape from a near-front,
slightly elevated aquarium viewing angle. Preserve the material's natural pores,
fractures, stratification and varied surface roughness. Use soft natural lighting,
a stable base and realistic depth. Isolate on a genuinely transparent background.
No surrounding landscape, aquarium glass, fish, plants, labels, watermark, large
cast shadow or toy/plastic appearance. Keep arches and holes genuinely open.

### Aquatic plants

Use case: scientific-educational.
Asset type: photorealistic aquarium plant sprite.
Subject: one natural clump of {common_name} ({scientific_name_or_cultivar}),
showing {leaf_shape_colour_and_growth_habit}.
Keep leaves, stems, roots/rhizome and fine branching coherent and correctly scaled.
Use realistic leaf veins, natural colour variation, soft underwater lighting and
subtle leaf translucency. Show the whole plant without clipped tips, on a genuinely
transparent background. No pots, hands, soil blocks, unrelated plants, fish,
labels, watermark or plastic foliage. Floating species need their own placement
handling before becoming live catalogue entries.

## Acceptance gates before integration

1. Confirm each subject visually and against its defining features; use a distinct
   scientific identity for each fish species and label plant cultivars accurately.
2. Inspect every cutout on both light and dark backgrounds. Reject attached rock,
   water rectangles, detached fragments and damaged fins/leaves.
3. Keep full-resolution masters outside the shipped asset folder. Export reviewed
   sRGB RGBA PNGs at appropriate compact sprite sizes, preserving alpha edges.
4. For photo sources, retain source URLs, authors, license URLs and derivative
   terms per file before copying anything into the application asset directory.
5. Only after all corresponding images pass review, update the catalogue,
   localizations, size/speed mappings and tests together. Do not publish missing
   images as purchasable entries. Preserve previous catalogue/inventory IDs.
6. Validate category counts, unique keys, PNG existence/transparency, PHP tests,
   all locale JSON and targeted/full x64 tests. Build and start the canonical
   Debug executable under the repository workflow and inspect the actual scene.

The existing same-species schooling planner is reused. No new schooling physics,
inventory grant, layout reset, API deployment or release publication is included.
Catalogue versions 2 and 3 remain accepted for previously queued purchases;
existing item IDs, prices and saved layouts are preserved.
