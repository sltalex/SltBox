# Tree illustrations

Original project-local SVG artwork for the five tree-growth stages, using the repository license. No downloaded artwork or external resources are required.

- `stage-1.svg` through `stage-5.svg`: stationary stems and trunks on transparent 260 x 220 canvases, anchored at the same ground position.
- `stage-1-canopy.svg` through `stage-5-canopy.svg`: matching foliage layers, gently rotated about each stage's branch pivot. Fruit is overlaid on the mature canopy rather than baked into the illustration.
- `ground.svg`: stationary grass-and-soil platform, shared by every stage.
- `fruit.svg`: 24 x 28 apple, overlaid according to the actual fruit inventory.
- `falling-leaf.svg`: 18 x 12 decorative leaf used by stages 3–5, with at most three leaves in one shared animation cycle.

Lighting comes from the upper left. Only paths, ellipses, gradients and strokes are used; avoid SVG filters, embedded fonts, scripts and remote links to preserve WinUI SVG compatibility.

`TreeIllustrationLayout` defines the canopy coordinate generator, canopy pivots, harvest hit target and bounded falling-leaf paths. Fruit positions are stable pseudo-random points across the mature crown and are generated on demand, so the visual layer does not impose a fixed fruit count. Update it and its tests together if the mature crown changes. `TreeWidgetContent` sways the foliage and fruit together while keeping the trunk and ground still, and stops all motion when the view is hidden or system accessibility settings disable animations.
