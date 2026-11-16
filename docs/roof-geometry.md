# Pitched roofs from Hamburg's 3D building models

Status: built for the site (validated on the Gelehrtenschule des Johanneums). The surroundings
still draw pitched buildings as before (see "Not done yet").

## Problem

Flat roofs were already drawn well: their levels come from LoD3.0-HH or LoD2 as `parts` (a block
per roof level, see [architecture.md](architecture.md), "Roof levels"). Pitched roofs were not:

- `SiteBuilder` drew a gable or hip only over an outline that nearly fills its minimum-area
  rectangle, always at 38°. An L, a U, a corner building or a block around a courtyard got a flat
  roof at the eaves height. The Johanneum (a U around a courtyard with a curved entrance front,
  hipped corner pavilions and pitched wings) was drawn flat.
- Half-hips, mansards, roof steps and towers did not exist in the drawing.

The data was already read: `CityGmlRoofs` parsed every `RoofSurface` of LoD2 and LoD3, but kept
only each surface's plan outline and its lowest and highest point.

## Approach: two roof modellers, chosen per building

A pitched roof is drawn by the **parametric roof** where that is certain to be close enough, and
by **the model's own surfaces** everywhere else. The choice is made once, at import, where the
model is at hand (`RoofFit`), and the result is stored on the building.

| Roof | Drawn as |
|---|---|
| flat (LoD2 `roofType` 1000) | roof levels (`parts`), as before |
| pitched, and the fit below passes | parametric gable or hip (`PitchedRoof`), at the model's pitch (`pitch`) |
| pitched, and the fit fails (L, U, half-hip, mansard, towers, a mix of roofs, a monopitch) | the model's roof surfaces and its walls above the eaves (`model`) |
| no model (outside Hamburg, OpenStreetMap only, not matched) | parametric over a rectangle at 38°, else flat (as before) |

Around the Johanneum (130 m), 8 of 21 pitched roofs pass the fit; the rest, among them the
Johanneum itself (37 roof surfaces), are drawn from the model.

### The fit (`App\SpongeCity\Geo\RoofFit`)

1. The eaves are the lowest corner of the model's roof surfaces of at least 1 m² (smaller ones
   are dormer cheeks and edges); the ridge is the highest corner.
2. The cadastre outline must fill at least 90 % of its minimum-area rectangle
   (`PitchedRoof::rectangleAround`; the drawing itself accepts 80 %).
3. The pitch is the one that puts the ridge at the model's height over half the rectangle's
   width (5° to 60°).
4. **Height deviation**: the model's roof surfaces are sampled on a 1 m grid (each sample's
   height on its surface's plane) and compared with the parametric roof's height at the same
   point (`PitchedRoof::heightAt`), once as a gable and once as a hip. The better one passes when
   the root mean square of the differences is at most 0.4 m and none exceeds 1.0 m
   (`MAX_RMS_METRES`, `MAX_DEVIATION_METRES`), from at least 8 samples.

The height deviation is what makes "close enough" certain: it measures what the eye sees (a
plane too high, too low, tilted the other way, a ridge across instead of along) and catches
towers and dormers on otherwise plain roofs. Plane counts or ridge directions need no test of
their own. A real monopitch fails it (the parametric roof draws a monopitch as a gable), so it
gets its model.

`PitchedRoof` (in `lib/Core/Configurator/Site`) is shared by the fit and the drawing, so both
mean the same roof.

### Reading (`CityGmlRoofs`)

Per building: the roof type, each roof surface's corners with their heights above the
building's lowest ground point, and, when asked (`walls: true`, only for the site), the wall
surfaces. Interior rings (holes) and LoD3 openings (windows, doors) are left out: a hole is not
a roof surface of its own.

Two models of each building are read, with walls: **LoD2** decides (the fit) and gives the eaves,
since it is built on the cadastre outline the façade stands on; **LoD3**, where it has the
building, is what is drawn: its roofs and its walls above LoD2's eaves, with the dormers, domes,
lanterns and canopies LoD2 generalises away. The Johanneum: 39 roof surfaces in LoD2, 464 in
LoD3 (196 of them the dome over the entrance rotunda); drawn, after the clean-up, 303 roofs and
269 wall pieces (75 KB). Without LoD3, LoD2's surfaces are drawn. LoD3's low parts (a canopy at
3 m) are drawn as they are and do not lower the façade.

### Storing (`SiteObject::MODEL`)

A building drawn from its model carries `model: {roofs, walls}` in the site: planar polygons of
`[x, y, z]` millimetres (x/z in the plan, y above the building's ground). The walls only above the
eaves, cut there (Sutherland–Hodgman): below them the outline's façade is drawn as before, with
its storey rows and the taller ground floor. Corners closer than 5 cm are merged; surfaces under
0.25 m² are left out. `height` is the eaves.

- Not in the agent's schema (like `ground`): the import sets it, `SiteType` normalises it
  (at most 1000 polygons of each kind, of 64 corners, within the site's reach).
- 75 KB for the Johanneum (LoD3), 96 KB for its whole site; its page is 510 KB (74 KB gzipped)
  and renders at 60 fps (`--perf --dpr=2 --tier=auto`, M4).
- A roof chosen by hand (`update_object` with `roof`) drops the model: an explicit choice beats
  the model, as a façade set by hand beats the data (`SiteEditor`).
- A building whose model reaches beyond the site's reach gets none (its outline is cut there).

This differs from the first proposal (a separate store and endpoint, drawn by a new browser
module): `Slab::planar` already draws any planar polygon as a part, so the server draws the model
like every other part, in one code path, and the plan carries it like `parts`.

### Drawing (`SiteBuilder::modelRoof`)

- The façade: the cadastre outline up to the eaves, as before.
- Each roof surface: a slab in its plane (`Slab::planar`), tiles where it slopes, gravel where it
  is under 10°, each starting at its most level long edge so the tile rows run along the eaves.
- Each wall above the eaves (gables, the walls between roof levels): a plain wall in the
  façade's colour.

## Fixed on the way

- **`Slab::planar`** took a slab's plane from its first edge and the first corner off that line.
  Model polygons often have nearly collinear corners where neighbouring surfaces meet, which
  tilted the plane: two of the Johanneum's roof planes came out flat. It now uses Newell's normal
  over all corners.
- **Outlines with more than 36 corners** were thinned to every n-th point, which cut diagonal
  walls across the Johanneum's courtyard (its outline has 56 corners). `SiteImporter` now drops
  the least significant corner, one at a time (Visvalingam–Whyatt), so the walls stay where they
  are.

## Checking it

```bash
symfony console app:plan:reimport 01M7JRT57YE18RV2F6W23KHJ8K   # the Johanneum
bin/render-loop 01M7JRT57YE18RV2F6W23KHJ8K --views=overview,left,right
bin/render-loop 01M7JRT57YE18RV2F6W23KHJ8K --views= --flicker=left
```

Tests: `RoofFitTest` (a gable and a hip pass, a gable with a tower and an L keep their model, the
walls are cut at the eaves, a detailed model is drawn while the eaves come from LoD2), `CityGmlRoofsTest` (corner heights, roof type, holes and windows left
out), `SiteBuilderTest` (the own pitch, a model's roof and gables, a slab with nearly collinear
corners), `SiteImporterTest` (simplified outlines, the model in plan millimetres),
`BuildingLookTest` (a roof set by hand drops the model).

## Not done yet

- **Materials**: neither model says what a roof is made of. The Johanneum's dome, dormers and
  canopy are copper (green) and drawn in the roof's colour; its façade (dark clinker) was set
  by hand, as a user would tell the agent.

- **Surroundings**: `RealCity` still draws pitched buildings as before. The same choice (and the
  model, from LoD2) for 700 m needs a measurement first (`bin/render-loop --perf --dpr=2
  --tier=auto`); perhaps models only within 150 m.
- **Mixed buildings**: the façade ends at the lowest eaves. A building with a low wing under the
  same id gets plain walls above the wing's eaves where the main part has windows.
- **Courtyard roofs with holes**: a roof surface's hole is left out, so the surface would cover
  the courtyard. LoD2 usually splits such roofs; none seen so far.
- **Outside Hamburg**: a straight-skeleton hip roof over any outline (approach B) as the
  fallback for pitched buildings without a model.
- **Shade** (`ImpactCalculator`) still sees a model-drawn building as its outline at the eaves.
- Licence: LoD2-DE is dl-de/by-2-0 (LGV), already in the sources and the attribution line.
