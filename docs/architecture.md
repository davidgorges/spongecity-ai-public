# SpongeCity AI: architecture

An interactive 3D planning tool: pick a real place, rebuild it from OpenStreetMap, and let an AI
agent redesign it with rain gardens, permeable surfaces and shade, with measurable effect.

Stack: Symfony 8.2 (beta), Symfony AI 1.0 (beta: `ai-bundle`, `ai-agent`, `ai-anthropic-platform`,
`ai-mcp-tool`), `mcp-bundle` / `mcp/sdk` (PHP MCP SDK), Messenger, UX Live/Twig Components,
Stimulus, Tailwind v4, three.js. The 3D engine and UI kit come from the `sf_configurator` project.

## Big picture

```
 Browser                              Symfony app                                External
 ───────                              ───────────                                ────────
 home form ── POST / ───────────────► HomeController ── geocode ───────────────► Nominatim
                                         │  create Plan(pending), PlanImporter → dispatch
                                         ▼
                                      Messenger (async, Doctrine/SQLite)
                                         │  worker: messenger:consume async
                                         ▼
                                      ImportSiteHandler: PlanImport start → steps → complete
                                         │  FetchOpenStreetMap ───────────────────► Overpass mirrors
                                         │  ApplyOfficialLayers (MapLayer, in Hamburg) ► api.hamburg.de (OGC API)
                                         │  BuildSite, ImportTerrain, CollectSiteConditions, GroundSite
                                         ▼
 plan page ◄── Live Component ──────  PlanStore (cache.app)  ◄───────────┐
  (3D view, impact, list)             Plan: values + status               │ reads/writes
  3D view ◄── GET /plan/{id}/surroundings ── SurroundingsLoader ─────────►│ (real city around the site)
  3D view ◄── GET /plan/{id}/conditions ──── SiteConditionsStore ────────►│ (infiltration overlay, sources)
  3D view ◄── GET /plan/{id}/terrain ─────── TerrainStore ───────────────►│ (the ground: height grids)
        │                                                                 │
        │ chat POST /plan/{id}/chat                                       │
        ▼                                                                 │
   ChatController → PlanAssistant → Agent "spongecity" (Claude)           │
                                         │ MCP client (HTTP)              │
                                         ▼                                │
                                      /mcp  ── SpongeCityTools ───────────┘
                                         (geodata, scene edit, impact)
```

Everything is addressed by a **plan id**. The browser and the agent's MCP tools run in separate
requests and share state only through `PlanStore`.

## Layers and directories

| Path | What lives there |
|---|---|
| `lib/Core/Configurator/` | The parametric 3D engine, copied from `sf_configurator` (namespace `Core\`). Framework-light, no Doctrine. |
| `lib/Core/Configurator/Product/SpongeCity/` | The product definition, SKUs, intervention builder and the impact calculation. |
| `src/SpongeCity/` | Application services: plans, geodata, import, chat. |
| `src/SpongeCity/Geo/Hamburg/` | Hamburg's official geodata (OGC API Features at api.hamburg.de): buildings, street trees, infiltration potential. |
| `src/SpongeCity/Conditions/` | What official data says about a site's ground (`SiteConditions`: infiltration potential, sources). |
| `src/SpongeCity/Terrain/` | The ground of a plan (`Terrain`, `TerrainGrid`), standing objects on it (`SiteGrounding`), reading it for the agent (`TerrainAnalysis`). |
| `src/Mcp/` | The MCP tools (`SpongeCityTools`). The MCP registry is restricted to `App\Mcp\`. |
| `src/Controller/`, `src/Twig/` | HTTP entry points, the `PlanWorkspace` Live Component, Twig functions for the viewer. |
| `assets/configurator/` | three.js scene renderer, environments, textures, models (from `sf_configurator`), the city around an aerial site (`real_city.js`, `city_blocks.js`). |
| `assets/controllers/` | Stimulus controllers: `configurator_viewer`, `plan_chat`, UI kit controllers. |
| `templates/components/` | The shadcn UX Toolkit components (`<twig:Button>` …) and `PlanWorkspace`. |
| `config/prompts/spongecity.md` | The agent's system prompt. |
| `tools/render-loop/`, `bin/render-loop` | Headless Chrome render loop for the 3D view: screenshots, flicker and frame-time measurements (see "Render loop"). |

## The configurator engine

Data flow (see `sf_configurator/docs/configurator.md` for the full framework):

```
values (storm, site JSON)
  └─ ConfigurationResolver ─► Configuration
       └─ SpongeCityDefinition::build()  ─► Assembly of Parts      (the product: plot + interventions)
            ├─ BillOfMaterials ─► Quote (prices per SKU)           (bill of quantities, cost estimate)
            └─ Evaluation::scene() = product + SiteBuilder(site)   (the surrounding city)
                 └─ SceneSerializer ─► scene JSON ─► SceneRenderer (three.js)
```

Units: **millimetres** on the server, metres in the browser. Plan coordinates: **x east, z south**,
origin = the searched point.

### The site

The city and the design are one structured parameter, `site`: `{"objects": [{id, type, …fields}]}`,
normalised by `Site\SiteType` (defaults filled in, numbers clamped, unknown things dropped, max 200
objects, coordinates within `SiteObject::REACH` = 100 m of the centre), so nothing an agent sends
can break the scene. Types are specified once in
`Site\SiteObject::fields()`; the JSON Schema for agents is derived from it.

| Kind | Types | Built by | Meaning |
|---|---|---|---|
| Context (real) | `plot`, `building`, `road`, `tree`, `house`, `paving`, … | `SiteBuilder` (scenery, no SKU) | What exists today (imported from OSM). |
| Interventions | `newtree`, `raingarden`, `permeable`, `planting`, `shadesail` | `InterventionBuilder` (parts **with SKU**) | What the agent plans. |

Because interventions have SKUs they are *the product*: they are priced, listed in the bill of
quantities, framed by the camera and named on hover. The `plot` is built with SKU `SC-PLOT` but
`counted: false`, so the camera frames the whole yard without it appearing on the bill.

A `building` carries its `height`, `use` (`Site\BuildingType`), `facade` (`Site\Facade`), `color`,
`roof` and `roofColor`; a `road` is a centre line with `width` and `kind` (carriageway or footway).
`SiteBuilder` draws a footprint as walls with the façade's window texture plus a roof slab (gravel
when flat, tiles when pitched); a near-rectangular footprint with a pitched roof gets a real gable or
hip roof over its minimum-area rectangle (`Site\PitchedRoof`, at the building's `pitch` when known), and
a building with its own `model` (Hamburg) gets the model's roof surfaces and gables
([roof-geometry.md](roof-geometry.md)). Roads are thin strips (carriageways 7 mm, footways 10 mm
above ground, the plot 4 mm: see "Depth and flicker").

`InterventionBuilder` sets the environment hints `Environment::AERIAL` (the viewer looks down on the
site: bird's-eye camera, larger max distance, a city instead of a garden around it) and
`Environment::SUBURBAN` when the site's buildings are mostly low (median ≤ 10 m or ≥ 40 % houses),
so the generic city around it is a suburb rather than perimeter blocks.

### Impact calculation (`Impact\ImpactCalculator`)

Compares the plan **without** interventions (baseline) to the plan **with** them:

- The plot is rasterised into square cells (≤ 40 000 cells); each cell takes the top-most surface,
  so overlaps resolve like in the 3D view and nothing is counted twice.
- Surfaces are `Impact\GroundClass` values with a runoff coefficient (typical DWA-A 138 /
  DIN 1986-100 values) and an indicative midday surface temperature.
- Rain gardens capture runoff from the sealed area around them, up to their storage and a 10:1
  catchment ratio (otherwise a warning is reported). Tree crowns intercept 2 mm.
- Shade is a ray march toward the sun (14:00, mid-summer: 52° elevation, from the south-west)
  against buildings, tree crowns and shade sails.
- Result: `ImpactReport` of `Metric`s (permeable share, runoff volume, retained volume, runoff
  coefficient, shade share, surface temperature), each with baseline, design, delta and its
  **basis**, plus the list of assumptions and warnings. Every number is traceable; the UI shows the
  bases under "Calculations and assumptions".

This is a planning-level estimate, not a simulation. Keep new rules in `GroundClass` /
`ImpactCalculator` and list them in the report's assumptions.

## Plans and state

`App\SpongeCity\Plan\Plan` is an entity: id, title, location, configuration `values` (`storm`,
`site`, changed through `replaceSite()` and `setStorm()`) and a `PlanStatus`, which only the
`PlanImport` state machine moves (below). `PlanStore` keeps plans in the
application cache pool (`cache.app`), with no database entities. This is deliberate for the demo:
the browser and the MCP tools read and write the same plan in separate requests.
`SiteEditor` is the only code that mutates the site (add, update, remove, clear interventions); it
always goes through `SiteType::normalize()`.

`PlanAnalyzer` combines `PlanEvaluator` (engine) and `ImpactCalculator` into impact and cost.

### The import as a state machine (`PlanImport`)

A Symfony Workflow state machine, defined in its class (`#[AsWorkflow]`, a `#[Transition]` per
constant, the places from the `PlanStatus` enum; `symfony console workflow:dump plan_import`):

```
pending ──start──► importing ──complete──► ready
   │                   │                     │
   └──────fail─────────┴──► failed           │
           ▲                  │              │
           └── request ◄──────┴──────────────┘   (request: back to pending)
```

- `PlanImporter::request()` is the one way in (home page, `load_site`, `app:plan:reimport`): a new
  plan is pending already, a ready or failed one is requested again, one being imported is left
  alone (`load_site` says so).
- The worker applies `start` (a second worker for the same plan finds it importing and stops),
  then `complete` with the new site, or `fail`.
- `PlanImport::store()` (`#[AsEnteredListener]`) saves the plan in every status it enters: the
  page and the agent poll it. `ImportSurroundings` (`#[AsCompletedListener]` on `complete`)
  imports the surroundings once the site is in place.

SimStadt runs have their own state machine, `SimulationRun` (`queued → running → done | failed`),
built the same way.

Plans are not persisted beyond the cache. For production, replace `PlanStore` with a database
backed implementation (the interface is small: `create`, `find`, `get`, `save`).

`PlanStore`, `SimulationStore`, `TerrainStore`, `SurroundingsStore` and `SiteConditionsStore`
extend `CacheStore` (one kind of object per key in `cache.app`). Controllers take the plan as an
argument (`Plan $plan`): `PlanValueResolver` looks up the route's `{id}` and answers 404 when
there is none. The chat binds its message with `#[MapRequestPayload]` to `ChatMessage`
(validated: not blank, at most 2000 characters).

## Geodata and the import (async)

1. `HomeController` geocodes the text with `NominatimGeocoder` (synchronous, fast), takes the
   first hit, creates a `pending` plan and has `PlanImporter` dispatch `ImportSite`. It redirects
   at once.
2. `ImportSiteHandler` (Messenger worker) runs every `SiteImportStep` (a tagged interface,
   `#[AutowireIterator]`, ordered by `#[AsTaggedItem(priority)]`), handing a `SiteImportContext`
   from one to the next. `FetchOpenStreetMap` asks `OverpassClient` for the area:
   - the ways the point lies in (`is_in` + `pivot`): leisure (playground, pitch, garden, park,
     schoolyard), amenity (school, kindergarten, …), landuse (education, residential). Not
     `around:1`: that measures the distance to a way's *outline*, so a point well inside a school
     yard was never "around" its polygon and every plan fell back to the square;
   - buildings, streets (`highway=*`) and `natural=tree` nodes within the radius (80 m, 30 to 150 m
     via the MCP tool).
3. `BuildSite`: `SiteImporter` projects lat/lon to plan millimetres (`LocalProjection`) and builds the site
   JSON: the **plot** is the most specific polygon containing the point (leisure > amenity >
   landuse, smaller wins), else a 40 m square; buildings (nearest first, at most 80) cut to the
   reach with Sutherland-Hodgman rather than squashed by the clamp; streets as `road` objects cut
   where they leave the reach (at most 40); at most 30 trees.
4. `BuildingTags` classifies each building the way urban energy simulation does (SimStadt: building
   function + IWU/TABULA archetypes such as detached house, terrace, apartment block, high-rise):
   `building=*` and the storeys give a `BuildingType`, which suggests a `Facade` (plaster, brick,
   window bands, cladding) and a roof; `building:material`, `building:colour`, `roof:shape` and
   `roof:colour` from the map win. Missing colours are picked from the façade's palette, seeded by
   the building's position, so a re-import looks the same. Heights from `height`, else
   `building:levels` × 3 m, else 9 m.
5. Before that, `ApplyOfficialLayers` has `MapLayers` apply every `MapLayer` (a tagged
   interface) that covers the area: in Hamburg, the city's own buildings and street trees replace
   OpenStreetMap's (below, "Hamburg's official geodata"). A layer whose service fails is skipped,
   and that kind of element stays as OpenStreetMap has it.
6. `ImportTerrain` and `CollectSiteConditions` store the ground and what official data says
   about it, with the sources of every layer; `GroundSite` puts the objects onto the terrain.
7. The handler completes the import with the new site (or fails it when a step throws); the
   **surroundings** (below) follow on `complete`. A failure there never fails the plan.

### Hamburg's official geodata

Inside Hamburg (a bounding box, `Hamburg::covers`) three layers come from the city's
OGC API Features (`HamburgApi`, `https://api.hamburg.de/datasets/v1`, GeoJSON or CityJSON per
bounding box, paged, cached a day per box: `CACHE_VERSION` in the key):

| Layer | Datasets | Becomes |
|---|---|---|
| Buildings (`HamburgBuildingLayer`) | ALKIS `alkis_vereinfacht/GebaeudeBauwerk` (footprint, function, storeys) joined with LoD2 `lod2_hamburg/building` (measured height, roof form, eaves) by ALKIS id (LoD2 id + `BL`) | `building` objects of the site and the surroundings |
| Street trees (`HamburgTreeLayer`, the site only) | Straßenbaumkataster `strassenbaumkataster/strassenbaumkataster_hh` (species, planting year, crown diameter), heights from the 3D Straßenbaumkataster (laser scan) | `tree` objects with `diameter` (crown), measured `height` and a label ("Spitz-Ahorn, 1982") |
| Infiltration (`HamburgInfiltration`) | Versickerungspotentialkarte `versickerung/versickerungspotential` | `SiteConditions` (areas with holes, cut to 100 m around the site) |

- The Hamburg layers are turned into **Overpass-like elements with OpenStreetMap tags**
  (`building=school`, `height`, `building:levels`, `roof:shape`, `diameter_crown`, …), so
  `SiteImporter`, `SurroundingsImporter` and `BuildingTags` stay unaware of the source.
  ALKIS functions map to `building=*` by keyword (`HamburgBuildings::FUNCTIONS`), the LoD2
  roof codes (ALKIS Dachform) to `roof:shape`; Bauteile and buildings below ground are left out.
- **Roof levels**: a flat-roofed building whose roof has several levels (a low wing, the main
  roof, a recessed top storey, a stair core) carries them as `parts` (`SiteObject::PARTS`:
  `{points, height}` per roof surface, at most 40), drawn as a block each and casting their own
  shade in the impact calculation. As one block at the measured height (the highest point) a
  two-storey wing stood as tall as the tower beside it. The cadastre's storeys belong to the
  largest part (the window rows follow it).
  - On the site, from **LoD3.0-HH** (`HamburgLod3`, LGV, dl-de/by-2-0): the roofscape modelled by
    hand from 2020 aerial photos, with recessed storeys and roof structures from 1 m². Five area
    ZIPs of 1 km CityGML tiles named by the last two digits of the UTM kilometre (`6734.gml`); a
    tile may be split over two areas. Tiles are 10–60 MB unpacked, so they are **streamed**
    (`RemoteZip::stream`: range request unbuffered, inflated piece by piece, each building parsed
    as soon as it is complete, `CityGmlRoofs::streamed`) and only the result per site is cached.
    Its ids are mostly the cadastre's; where they are not (area 4), buildings are matched by
    position. The Brecht-Schule: 14 levels (main roof 20.8 m, recessed top storey 23.7–24.5 m,
    stepped wing 5.5–7.1 m) where LoD2 had 3.
  - Elsewhere (the surroundings), from LoD2's full-precision CityGML (`HamburgCityGml`).
  - Pitched roofs keep their gable or hip; parts only for roofs at least 80 % flat.
- **Pitched roofs** (site only) are fitted to LoD2's roof surfaces (`RoofFit`): a plain gable or
  hip within 0.4 m (root mean square, at most 1 m anywhere) is drawn parametrically at the
  model's pitch; anything else (an L or U, half-hips, towers, a mix) as the model's own roof
  surfaces and its walls above the eaves (`model`). See [roof-geometry.md](roof-geometry.md).
- **Long outlines** (more than 36 corners) lose their least significant corners first
  (Visvalingam–Whyatt), not every n-th one, so walls stay where they are.
- **Storeys**: buildings keep the cadastre's storeys (`storeys`); the façade texture is stretched
  upwards so each row of windows is one storey (`Facade::textureHeight`, 2.6 to 6 m). From three
  storeys on, the ground floor is a quarter taller than the rest (3.5 to 5.5 m): the walls are two
  stacked pieces, each with its own rows (`SiteBuilder::facadeWalls`). Before, every
  façade had 3 m rows, and a school with 4–5 m floors looked twice as many storeys high.
- **Looks are a guess**: no dataset records façade material, so it is picked from the use
  (`BuildingType::facades`; `render` is the newer rendered wall with tall, dark-framed windows).
  A user who knows better tells the agent, which corrects `facade` and `color` with
  `update_object` (`get_site` lists building ids). Such a building's `look` becomes `user`, and
  `app:plan:reimport` gives it back to the building standing in the same place.
- **Glazed bands**: roof parts under a metre wide that rise above the main roof are wall tops the
  model draws for a glazed top storey or a glass railing: rendered wall up to the main roof, glass
  above it.
- A pitched roof stands on its eaves: the eaves height is the lowest roof point over the lowest
  ground point of the LoD2 solid (on the site from the full-precision CityGML, see `RoofFit`). The API quantises LoD2 vertices to whole metres, which is why
  footprints come from ALKIS, not from LoD2.
- The register knows **street trees only**: OpenStreetMap trees more than 3 m from a registered
  one (yards, parks) stay. A recorded crown is used by the impact calculation instead of the
  28 %-of-height estimate.
- **Tree heights** come from the "3D Straßenbaumkataster Hamburg" (LGV, dl-de/by-2-0, metadata
  `24513F73-D928-450C-A334-E30037945729`): the highest point of the airborne laser scan (April
  2022) within the crown above the DGM1 ground, joined by tree number (`Baumnummer`). It is read
  per site from the layer's 3D Tiles (`Hamburg3dTiles`: the tileset walked down to the leaf tiles
  touching the site, external tilesets followed, batch tables of i3dm/b3dm/composite tiles read;
  no geometry decoded). The same data exists as one 487 MB CityGML file, which cannot be read in
  pieces. Trees the scan could not measure, or all of them when the layer is unavailable, get the
  estimate 1.3 × crown + 2 m; `height:source` says which. On the Brecht-Schule the estimate was off
  by up to 6.6 m (old limes 20.8 m measured against 15 m estimated).
- Not used: "Grünes Hamburg in 3D" (CC0, all other vegetation from the laser scan, no species): its
  metadata limits it to visualisation and rules out shade, crown areas and counts. "3D Stadtbäume"
  in the 3D portal has no metadata or licence.
- **Each layer falls back on its own**: when a service fails, that layer stays as OpenStreetMap
  has it (logged as a warning); outside Hamburg nothing is asked.
- Every layer used is a `DataSource` (title, publisher, as of, licence, URL, limitations), kept in
  the plan's `SiteConditions`. The page shows the attribution under the view (Datenlizenz
  Deutschland – Namensnennung 2.0, as the licence requires); `get_site_conditions` gives them to
  the agent.
- Infiltration classes (`Conditions\InfiltrationPotential`), best first: possible (> 5 m to
  groundwater, any facility), likely (2 to 5 m), limited (1 to 2 m), unlikely (< 1 m). A
  preliminary assessment, never an infiltration rate; the agent is told to say so.

Not imported yet: surface sealing (the Bodenversiegelung WFS and OGC API answered 404 in
November 2026), runoff paths and the heavy-rain hazard map.

### Terrain (DGM1)

Hamburg publishes its 1 m terrain model (bare earth, airborne laser scan 2022) only as one
1.4 GB ZIP of 926 GeoTIFF tiles (1 × 1 km, float32, LZW). `HamburgElevation` reads it with
**HTTP range requests**: the end of the ZIP for its central directory, then the local header
and the deflated bytes of each tile it needs (about 1.7 MB); both are cached for a week.
`ElevationTile` reads the GeoTIFF itself (one strip, LZW or none, float32, tie point and pixel
scale; no GDAL). `Utm` projects ETRS89 to UTM 32 (Krüger series; matches PROJ to 0.1 mm).

`HamburgTerrain::around()` samples two grids around the origin, each node the mean of the 1 m
heights in its cell: **2 m out to ±120 m** (the site and the lawn) and **4 m out to ±360 m** (the
real surroundings), in centimetres relative to the origin's height (`Terrain::reference`, m
above sea level). Between 300 and 350 m the heights fade to 0, so the generic city beyond stays
flat. Building them takes about 2 s (under a second with cached tiles), at most 4 tiles.

Who uses it:

- **Upright objects** (`SiteObject::UPRIGHT`: buildings, trees, walls, sails, …) get a
  `ground` field (mm): the lowest ground along their outline, sampled every metre.
  `SiteGrounding` sets it whenever a site is saved (import and `SiteEditor`), so whatever the
  agent adds stands right; `ground` is not in the agent's schema. `SiteBuilder` and
  `InterventionBuilder` raise every part of such an object by it (`Part::raisedBy`).
- **Ground layers** (plot, roads, paving, rain gardens, permeable surfaces, planting) are parts
  with `draped: true`; the view lays them over the terrain.
- **The 3D view** (`terrain.js`, `TerrainField`, from `GET /plan/{id}/terrain`; 204 without
  terrain) is loaded before the surroundings and the overlay. Within a grid the ground is
  **linear on the two triangles of each cell** (split north-west to south-east), in PHP
  (`TerrainGrid::heightAt`) and JS alike. The lawn is built on exactly those triangles, and a
  draped part is cut along the grid lines and diagonals so each piece lies in one triangle: lawn,
  plot, roads and surfaces then stay parallel at their millimetre offsets on any slope (with
  plain subdivision they drifted by centimetres and the lawn showed through the plot). The real
  city's buildings stand on the lowest ground under them, its streets are subdivided to 4 m,
  its trees, the generic blocks and the city ground follow `heightAt` too. The infiltration
  overlay is draped like a ground layer.
- **The agent**: `get_site_conditions` adds `terrain` (`TerrainAnalysis`, on the plot's open
  ground, buildings left out): elevation range, relief, mean slope, the direction surface water
  runs, and up to five low spots (lower than the ring 8 m around them by ≥ 5 cm). Hollows deeper
  than 1 m are marked "probably built": on the Brecht-Schule plot the deepest is the 3 m ramp to
  the underground car park, which the model shows like any other ground.

### Surroundings (the real city around the site)

`SurroundingsLoader` imports the OSM buildings and streets out to 350 m
(`ImportSite::SURROUNDINGS_RADIUS_METRES`) through `OverpassClient::surroundings()` and
`SurroundingsImporter` (same `BuildingTags`; buildings that are the site's own are left out), and
keeps them in `SurroundingsStore` (cache pool, next to the plan). They are context for the 3D view
only: not part of the site, the agent's tools or the balance. `GET /plan/{id}/surroundings`
(`SurroundingsController`) returns them as JSON in metres; a plan that has none yet (older plans)
imports them on that first request. The viewer fetches them once in the background.

`symfony console app:plan:reimport <id>` rebuilds a plan's site and surroundings at once (no
worker), keeping its interventions: useful after importer changes.

The plan page polls (`data-poll`) while pending or importing and shows a clear failed state with a way back.

`OverpassClient` tries several public mirrors in order (`overpass.openstreetmap.fr` first), skips
a silent one after 30 s (under load they take 20 s even for small queries), and caches results per place and radius for a day (`CACHE_VERSION` in the
key: bump it when a query changes). Public Overpass
instances are shared and often overloaded, and they reject generic user agents: set
`OSM_USER_AGENT` to something identifying you (also required by Nominatim's usage policy).

Messenger: transport `async` on Doctrine (SQLite, `var/data_<env>.db`), no retries (the mirrors are
the retry), failed messages go to the `failed` transport. **A worker must run**
(`messenger:consume async`); `.symfony.local.yaml` starts it with `symfony server:start`. DBAL is
installed only for the queue.

## The agent and MCP

`config/packages/ai.yaml` defines the agent `spongecity` (Anthropic, `claude-sonnet-5-5`, prompt in
`config/prompts/spongecity.md`). The model name carries `?max_tokens=8000`: the limit covers the
model's thinking *and* its answer, and with the platform's default of 1000 the thinking could use it
all up, so the reply came back as a bare `ThinkingResult` and `asText()` failed ("The planning
agent could not answer"). Its tools are the **MCP server of this same application**:

- **Server** (`mcp-bundle`, `config/packages/mcp.yaml`): `/mcp` (HTTP, localhost only by default;
  set `http.allowed_hosts` to expose it) serves `App\Mcp\SpongeCityTools`, declared with
  `#[McpTool]`. Any MCP client (Claude Desktop, the inspector) can use it.
- **Client**: `mcp.clients.spongecity.servers.local` points at `MCP_SERVER_URL`
  (default `http://127.0.0.1:8000/mcp`); the agent's `tools: - mcp_server: 'spongecity.local'`
  exposes the tools to the model with the prefix `local_`.

| Tool | Does |
|---|---|
| `search_place` | Nominatim lookup. |
| `load_site` | Queues the OSM import for a plan (status `pending`, then `importing`; poll `get_site`). |
| `get_site` | Status, plot bounds, objects, existing buildings and trees. |
| `list_object_types` | Types, fields, ranges, defaults. |
| `add_objects` / `update_object` / `remove_object` / `clear_interventions` | Edit the design; values are corrected, not rejected. |
| `set_design_storm` | Rain depth for the calculation (10 to 120 mm/h). |
| `calculate_impact` | Metrics with bases, assumptions, warnings, cost. |
| `simulate_buildings` / `get_building_simulation` | Hamburg only: a SimStadt run (heat demand, heat demand with shadow, PV potential) for the buildings within 100 m, started and polled (see "Building simulations"). |
| `get_site_conditions` | Hamburg only: terrain of the plot (elevation, slope, where water runs and collects), infiltration potential as shares of the plot with their meaning, and the sources (publisher, date, licence, limitations) of the site's official layers. |

Tools are stateless: the plan id is part of every call (`PlanAssistant` prefixes each user message
with `plan_id: …`). The system prompt tells the agent to look first, design with tools, measure
before it claims anything, and never state a number that did not come from a tool.

Because the app calls its own `/mcp` over HTTP while handling a request, the web server needs more
than one worker (the Symfony CLI server has several).

## Building simulations (SimStadt)

[SimStadt 2](https://simstadt.hft-stuttgart.de/) (HFT Stuttgart, MIT licence) simulates the
energy of buildings from CityGML: heat demand after DIN V 18599, PV potential of roof surfaces,
shading. It covers the buildings, not the open ground, so it complements the impact calculation
rather than replacing it. Its tools are part of this application's MCP server
(`App\Mcp\SimStadtTools`, Symfony MCP bundle), next to `SpongeCityTools`.

```
simulate_buildings ─► Simulation (queued) + RunSimulation ─► Messenger transport "simstadt"
                                                                  │ worker
                     HamburgCityGml: LoD2 tiles (RemoteZip) ─► CityGML of the site's buildings
                     SimStadtTemplates → the workflow's SimStadtTemplate
                     SimStadtRunner: docker run simstadt/simstadt:cli simstadt <Template> …
                     SimStadtResults: summary + one row per building (plan x/z)
get_building_simulation ◄─ SimulationStore (cache pool, per plan and workflow)
```

- **Workflows and templates**: `SimStadtWorkflow` names what the agent can run (and describes it
  to the agent); what SimStadt needs for it is a `SimStadtTemplate` service (`Template\HeatDemand`,
  `HeatDemandWithShadow`, `PvPotential`): the template's name, the result columns, whether rows
  are roof surfaces, the sort key. They are tagged with the workflow's value as their index, and
  `SimStadtTemplates` finds one through a service locator (`#[AutowireLocator]`). A new workflow
  is an enum case and one template class.
- **State**: `SimulationRun` is a state machine like `PlanImport` (`queued → running → done |
  failed`); it stores the run in every status it enters, so the agent can poll it.

- **SimStadt runs in its own Docker image** (`SIMSTADT_IMAGE`, default `simstadt/simstadt:cli`:
  Java, INSEL and simstadtpy). Nothing is installed on the host: INSEL has `/usr/local/insel`
  compiled in, so a native install would need admin rights. The image is amd64 only
  (`SIMSTADT_PLATFORM=linux/amd64`, emulated on Apple Silicon). **Docker must be available to the
  worker**; `.symfony.local.yaml` starts a second worker for the `simstadt` transport, so a run of
  a minute never holds up a site import.
- **Input**: `HamburgCityGml` cuts the buildings whose first vertex lies within the site's reach
  (100 m) out of Hamburg's LoD2 model (CityGML 1.0, AdV profile, 1 km tiles in a 660 MB ZIP read by
  range requests through `RemoteZip`, shared with the terrain). It adds the envelope with its
  reference system (SimStadt locates the model and fetches its weather by it) and a
  **construction year**, which Hamburg's model lacks and without which SimStadt drops a building.
  The year (default 1970, the agent may pass another) decides the U-values: every result lists it
  among its assumptions.
- **Runs**: `var/simstadt` is mounted as `/data` and is SimStadt's repository. Each run uses its
  own file names there and removes them afterwards; the repository's `.cache` (PVGIS weather,
  irradiance, shadows) stays, so only the first run downloads. A run that fails on the PVGIS
  download (which drops connections now and then) is tried once more. Timeout
  `SIMSTADT_TIMEOUT_SECONDS` (900).
- **Results** (`SimStadtResults`): simstadtpy's printed totals as they are, and the saved table
  condensed to one row per building (PV: the roof surfaces added up), with the position in plan
  millimetres, largest first, at most 40.
- Measured on the Brecht-Schule (21 buildings, M4, emulated amd64): heat demand 28 s, PV 5 s,
  heat demand with shadow about 75 s.
- Not used yet: GreenWater (needs trees in the CityGML: the tree register could provide them),
  refurbishment scenarios, load profiles. SimStadt has no cooling demand.

## Chat and the live UI

- `POST /plan/{id}/chat` (`ChatController`) → `PlanAssistant::ask()`. The conversation is kept in
  the session per plan (last 20 turns) so user and agent refine the design together. One turn is
  several model calls with tools in between (40 to 60 s when the agent places interventions), so
  the action raises PHP's time limit to 180 s.
- `PlanWorkspace` (Live Component) renders the 3D view, the impact cards, the storm slider, the
  planned measures and the cost table. Removing an object or changing the storm are live actions;
  the storm persists into the plan.
- `plan_chat_controller.js` posts the message and dispatches a `plan:changed` event on window
  every 2.5 s while the agent works and once more when it answers; the component re-renders on that
  event, so the user watches the design appear. The chat region is `data-live-ignore`.
- The 3D canvas is `data-live-ignore`; a re-render only changes the `scene` value, and the
  `configurator_viewer_controller.js` updates the scene without moving the camera.

## The 3D view

`SceneRenderer` (`assets/configurator/scene_renderer.js`) draws the scene JSON; frames are rendered
on demand (after an update or while the camera moves). For an aerial site the outdoor environment
becomes a city:

- **Façades** are procedural canvas textures (`procedural_textures.js`, `ProceduralTexture::Facade*`):
  near-white sheets tinted by the building colour, 4 × 4 window bays per repeat (12 m), each window
  a little different (glass tone, blinds, curtains). Upright extrusions get per-wall UVs
  (`applyUprightUVs`: u along the wall, v up from the ground), so window rows sit on the storeys of
  every wall, and each building is shifted by whole bays so neighbours differ. Lit windows at night
  come from a separate, coarser glow sheet (7 × 5 bays), so the lit pattern does not repeat visibly.
- **Around the site**: the real city (`RealCity`, from the surroundings endpoint: one merged mesh per
  façade kind, one for roofs, two for streets, street trees along carriageways), and beyond its
  radius a generic one (`CityBlocks`: instanced perimeter blocks, or detached houses with gable roofs
  for a `suburban` site). Flat ground, no mountains, broadleaf street trees, haze pushed back by the
  camera's distance (`OutdoorEnvironment::setFogOffset`). Generic and context buildings standing
  between the camera and the site sink into the ground (`fadeNearCamera`), so they never hide it;
  they skip passes with an override material (`skipOverridePasses`), otherwise ambient occlusion
  would draw them as ghosts. None of them cast shadows.
- **Site conditions**: `InfiltrationOverlay` (`site_conditions.js`) draws the infiltration areas
  (from `GET /plan/{id}/conditions`) as one translucent merged mesh 30 mm above the ground with
  polygon offset, coloured per class (legend in `PlanWorkspace`), hidden until the "Infiltration"
  button is pressed; the button appears only when there is something to show. Its colours are
  saturated on purpose: at the higher tiers the composer's OutputPass tone-maps the whole frame.
  In debug mode `configuratorShowConditions(true)` turns it on (`bin/render-loop --eval`).
- **Models**: `garden_trees.glb` was simplified to ~40 000 triangles (see
  `assets/configurator/models/README.md`); up to 30 OSM trees are drawn three times per frame
  (view, shadow map, ambient occlusion).

### Depth and flicker

Ground layers are millimetres apart: lawn −2 mm, plot 4 mm, carriageways 7 mm, footways 10 mm,
paving 10 mm, interventions 14 to 22 mm. To keep them apart from far away the near plane follows the
camera's distance to its target (4 %, 0.1 to 12 m; `NEAR_PER_DISTANCE`); with a fixed 0.1 m they
z-fought and flickered. Layers 2 mm apart or less were not enough at the edge of the view: keep new
ground layers at least 3 mm apart, or separate them with polygon offset as `RealCity` does for its
streets (footways over carriageways over the ground).

### Quality tiers and the governor

`quality_tiers.js` (low, standard, high, ultra) and `performance_governor.js`:

- Desktops start at the best tier allowed: every switch rebuilds and recompiles (a hitch of about
  0.1 s), so a capable machine should not climb into it.
- Too slow means dropped frames: a window of 40 frames with more than 10 % late (≥ 1.5 refresh
  intervals) steps down. A slow median alone missed 46 fps stutter.
- Three frames in a row over 250 ms step down at once (very slow GPUs), and a frame loop that
  restarts right after the last frame keeps measuring; otherwise a device whose every frame
  outlasted `KEEP_RENDERING_MS` was never measured.
- Judging pauses for 1 s after a switch and 1.5 s after new scenery arrives (built and compiled
  with `compileAsync`): one-off work is not a reason to lower the quality.
- Ultra renders at 1.5× (not 2×): on a retina Mac 2× cost about 2.2× the GPU time for a difference
  SMAA mostly hides.

Measured with `bin/render-loop --perf --dpr=2` ([render-loop.md](render-loop.md)) on an M4 MacBook (Brecht-Schule and Iserbrook plans,
the page choosing the tier): 60 fps at ultra, worst frame 16.8 ms. With 2.6× the pixels (roughly
an M1's load) it settles on standard, which holds 60 fps (worst frame 16.8 ms pinned). Not yet
measured on a real older Mac.

## Render loop

`bin/render-loop <plan id or URL> [options]` renders the 3D view of a plan in Chrome on the real GPU
and writes screenshots and a report (errors, meshes, frame times, flicker) to
`var/render-loop/latest/`. Options, output, how to read the numbers and recipes:
[render-loop.md](render-loop.md).

## Conventions

- No inline JavaScript: Stimulus controllers only. Use Stimulus `data-action`, not manual listeners.
- No magic strings: parameter names, SKUs, object types and statuses are constants.
- Translations in `translations/spongecity.<locale>.yaml` (domain `spongecity`); inside `<twig:…>`
  bodies pass the domain to `|trans`.
- Run Composer and the console through the Symfony CLI (`symfony composer …`, `symfony console …`).
- UI: Tailwind v4 with shadcn token names, the UX Toolkit components in `templates/components/`.
  Build CSS with `symfony console tailwind:build`.

## Running it

```bash
symfony composer install
symfony console importmap:install
symfony console tailwind:build
# .env.local: ANTHROPIC_API_KEY=…  OSM_USER_AGENT="YourApp contact@example.com"
symfony server:start --no-tls      # also starts the Messenger workers (import, SimStadt)
docker pull simstadt/simstadt:cli  # building simulations (about 1.3 GB)
symfony console app:plan:demo      # offline demo plan (synthetic sealed schoolyard)
symfony console app:plan:reimport <id>   # re-import site and surroundings, keeping interventions
bin/render-loop <id> --perf --dpr=2      # renders and frame times (installs puppeteer-core on first run)
symfony php vendor/bin/phpunit
```

## Known limitations and next steps

- Plans live in the cache pool; sessions hold the chat history. Not for production as is.
- The plot's ground is assumed to be asphalt. Reading `surface` tags from OSM would fix parks;
  in Hamburg the Bodenversiegelung dataset would, once its service is back.
- LoD2 roofs are drawn as simple flat, gable or hip roofs over the footprint, not the model's own
  roof geometry (its vertices come quantised to whole metres from the API; the glTF output is
  precise but carries no per-building ids).
- The terrain is drawn and given to the agent, but the impact calculation still treats the plot
  as level: rain gardens catch the sealed area around them, not what flows downhill to them.
  Routing runoff over the terrain (or the Fließwegekarte) is the next step.
- Outside Hamburg the ground is flat (no terrain source).
- The terrain model is bare earth: ramps, stairwells and light wells appear as hollows; kerbs,
  gutters and drains are below its resolution.
- SimStadt results rest on an assumed construction year for every building. A better source
  (e.g. the heat-planning dataset's building-age classes, per block) would sharpen heat demand.
- The geocoder takes the first hit; a pick-list for ambiguous names would help.
- Public Overpass/Nominatim instances are rate limited; self-host Overpass for reliability.
- The chat shows the final answer only; streaming the agent's tool steps (Symfony AI
  `onProgress`) is a natural next step. A turn runs inside the request (up to 180 s); Messenger
  would be the place for it in production.
- `get_site` gives the agent building centres only, not footprints, so it places interventions
  without knowing exactly where walls are.
- Areas mapped as multipolygon relations (some schools, parks) are not found as the plot yet: only
  ways are.
- The real surroundings reach 350 m; beyond that the city is generic. They live in the cache pool.
- Lit windows do not light the ground at night.
- Speed is measured on an M4 only (plus simulated heavier loads); older Macs are untested. On slow
  hardware the photoreal trees and the ambient-occlusion pass are the next things to cut.
- A flicker reported behind the Brecht-Schule site was not reproduced in renders; the likely causes
  (street layers at one height, too little depth precision) are fixed.
