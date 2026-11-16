# Render loop

`bin/render-loop` renders the 3D view of a plan in a real Chrome, writes screenshots and a report,
and can measure frame times and look for flicker. Use it to iterate on the viewer (scene renderer,
textures, environment, site builder) without clicking through the browser, and to check that a
change looks right and stays fast.

```bash
bin/render-loop 01M7J3FYGWEPKTAH2SJ16A1TY4                 # all views → var/render-loop/latest/
bin/render-loop 01M7J3FYGWEPKTAH2SJ16A1TY4 --watch         # re-render on every file change
bin/render-loop 01M7J3FYGWEPKTAH2SJ16A1TY4 --perf --dpr=2  # frame times as on a retina Mac
```

## Requirements

- The app running (`symfony server:start`, default `http://127.0.0.1:8000`) and the plan **ready**
  (imported). The render waits for the 3D view and fails after 30 s if there is none.
- Google Chrome or Chromium installed. Found at the usual macOS and Linux paths, or set
  `CHROME_PATH`.
- Node.js. The first run installs the tool's own dependencies (`puppeteer-core`, `pngjs`) into
  `tools/render-loop/node_modules` (git-ignored). The project itself has no `package.json`.

## How it works

`bin/render-loop` runs `tools/render-loop/render.mjs`, which:

1. Starts Chrome headless (`--headed` to watch) with the window size given, on the **real GPU**
   through ANGLE Metal by default, so what it renders matches desktop Chrome on a Mac.
2. Opens `/plan/<id>?debug=1&tier=<tier>`. Debug mode exposes the viewer to the script
   (`window.configuratorScene`, `configuratorView`, `configuratorShowView`, `configuratorStats`,
   `configuratorShowConditions`);
   `tier` pins a quality tier (see `PlanWorkspace::QUERY_DEBUG`, `QUERY_TIER`).
3. Emulates `prefers-reduced-motion`, so camera moves are instant; hides the Symfony toolbar and
   the stats overlay (their numbers go into the report).
4. Waits until the canvas has drawn, the network is idle, and two more seconds for the real
   surroundings to load and compile.
5. Runs the steps asked for (eval, mood, views, flicker, orbit, perf), screenshots the viewer
   element, and writes `report.json`.

The output directory is **emptied** at the start of every run.

## Options

| Option | Default | Does |
|---|---|---|
| `<plan id or URL>` | (required) | A plan id, or a full URL to the plan page. |
| `--views=a,b,…` | all | Named views to render, each to `<view>.png`: `overview`, `top`, `front`, `left`, `right`, `surroundings` (the overview including everything drawn around the site). `--views=` renders none. |
| `--orbit=N` | 0 | N frames on a slow orbit around the target (`orbit-00.png` …), spanning `ORBIT_DEGREES` (env, default 24°). Starts from the overview, or from the camera an `--eval` snippet set up. |
| `--perf[=SECONDS]` | off (6 s with no value) | Orbits continuously and measures every frame (see "Measuring speed"). |
| `--flicker[=VIEW]` | off (`overview` with no value) | Renders VIEW six times with a tiny camera jitter and maps the pixels that flip (see "Looking for flicker"). |
| `--tier=0..3\|auto` | `3` (ultra) | Pins a quality tier: 0 low, 1 standard, 2 high, 3 ultra. `auto` lets the page's performance governor choose, as for a real visitor. |
| `--dpr=N` | `1` | Device pixel ratio. `2` renders like a retina Mac: four times the pixels. |
| `--width=…`, `--height=…` | `1600`, `1000` | Browser viewport in CSS pixels. The 3D view is the part left of the 480 px side panel. |
| `--mood=day\|sunset\|evening` | day | Light mood, set through the mood buttons. |
| `--gl=metal\|swiftshader` | `metal` | GPU backend. `swiftshader` is Chrome's software renderer: a very slow GPU. |
| `--eval=JS` | none | JavaScript run in the page after loading, before anything else (experiments, camera set-ups). With `--views=` and no orbit, the result is saved as `eval.png`. |
| `--watch` | off | Keeps the browser open and renders again 400 ms after a change under `assets/`, `lib/`, `src/` or `templates/`. Changes during a run queue one more run. |
| `--out=DIR` | `var/render-loop/latest` | Output directory, relative to the project. |
| `--base=URL` | `http://127.0.0.1:8000` | The app's address, for plan ids. |
| `--headed` | off | Shows the browser window. |
| `--help` | | Prints the usage. |

## Output

- `<view>.png`, `orbit-NN.png`, `eval.png`, `flicker.png`: screenshots of the viewer area, at the
  device pixel ratio given.
- `report.json`:

| Key | Holds |
|---|---|
| `url` | The page rendered. |
| `console` | Console errors and warnings, each with the stack of an `Error` passed to it. |
| `errors` | Uncaught page errors with stacks. |
| `failedRequests` | Requests that failed or answered 400 and above. |
| `webgl` | The GPU Chrome used, e.g. `ANGLE (Apple, ANGLE Metal Renderer: Apple M4…)`. |
| `views` | Per view: what `showView` answered and the camera (position, target, near, far). |
| `stats` | The viewer's own numbers after the run: tier, pixel ratio, draw calls, triangles, geometries, textures. |
| `scene` | The 25 largest visible meshes by ground area, with bounds: finds misplaced planes. |
| `heaviest` | The 12 meshes with the most triangles (instances counted): where the GPU's time goes. |
| `perf` | See below (with `--perf`). |
| `flicker` | `{view, share, pixels}` (with `--flicker`). |

Problems are also printed after every run as `! …` lines, so a broken change shows in the terminal
even when no screenshot is taken.

## Measuring speed

`--perf=SECONDS` turns the camera back and forth (±54° around the overview) for the given time, one
step per animation frame, the way orbiting with the mouse keeps the view busy. `report.perf` holds:

| Key | Means |
|---|---|
| `fps` | Average frames per second (the first five frames skipped). |
| `frameMs.p50`, `p95`, `max` | Intervals between frames: what a viewer feels. At 60 Hz every frame on time is 16.7 ms; 33 ms is a dropped frame. |
| `gpuMs.p50`, `p95` | GPU time per frame from the timer query. |
| `tier`, `pixelRatio` | The tier at the end (with `--tier=auto`, what the governor settled on) and the pixel ratio it draws at. |
| `drawCalls`, `triangles`, `textures` | Per frame, all passes counted. |

Read it like this:

- **Judge by frame times, not GPU time.** On Metal, Chrome's GPU timer queries are unreliable (the
  GPU time can be higher than the frame time). `p95` at 16.7 ms and `max` near it mean smooth.
- **Use `--tier=auto` for the default experience**: what a visitor gets, including the governor
  stepping up or down. A pinned tier (`--tier=3`, the default) measures that tier's steady cost.
  When `auto` shows drops, pin the tier it ended on: if that is smooth, the drops are the stepping
  itself (each tier switch rebuilds and recompiles), not the tier.
- **Use `--dpr=2`** for a retina Mac: four times the pixels of `--dpr=1`.
- **Heavier hardware load**: a larger window at `--dpr=2` (e.g. `--width=2560 --height=1600`,
  about 2.6× the pixels) approximates a GPU that much slower for fill-bound work (roughly an M1
  against an M4). It does not model a weaker GPU's other limits; it is an estimate, not a test on
  that machine.
- **`--gl=swiftshader`** checks that the governor steps down on a very slow device. It is far
  slower than any Mac GPU; do not read its frame rate as a target.
- Runs vary by a few percent; repeat a measurement before believing a small difference.

Measured on an M4 MacBook, plans Brecht-Schule and Iserbrook, `--tier=auto --perf=15 --dpr=2`:
60 fps at ultra, `p95` 16.7 ms, `max` 16.8 ms.

## Looking for flicker

`--flicker=VIEW` renders the view six times, turning the camera by 0.05° each time, and marks a
pixel red in `flicker.png` when it changes strongly in at least two of the frame pairs.
`report.flicker.share` is the share of such pixels.

The map shows where things change, not *why*: sharp edges (window frames, leaves) move by a fraction
of a pixel and flip too. A planted z-fight (the lawn lifted to the plot's height) did not raise the
share measurably. So:

- Look at **where** the red is: a street, a roof or the ground lit up in red points at z-fighting;
  window edges and foliage are normal.
- Compare **runs of the same view** before and after a change, not the share against zero.
- Confirm by eye with a slow orbit close to the spot (`--eval` to set the camera, `--orbit=4`,
  `ORBIT_DEGREES=3`) and compare the frames.

## Recipes

**Iterate on the viewer.** Leave `--watch` running with the views you care about and look at the PNGs
after each save:

```bash
bin/render-loop <id> --views=overview,front --watch
```

**After an importer change**, re-import the plan first (keeps its interventions):

```bash
symfony console app:plan:reimport <id> && bin/render-loop <id>
```

**A/B a cost**: hide something and measure again.

```bash
bin/render-loop <id> --views=overview --tier=3 --perf=6 --dpr=2 \
  "--eval=configuratorScene.getObjectByName('street_trees').visible = false"
```

**A close-up**: set the camera yourself.

```bash
bin/render-loop <id> --views= "--eval=const {camera, controls, render} = configuratorView;
  controls.target.set(-10, 0, -75); camera.position.set(60, 70, 40); controls.update(); render();"
```

**Check a slow orbit for flicker** around a spot:

```bash
ORBIT_DEGREES=3 bin/render-loop <id> --views= --orbit=4 --dpr=2 "--eval=…camera set-up…"
```

**The infiltration overlay** (Hamburg plans):

```bash
bin/render-loop <id> --views=overview,top "--eval=configuratorShowConditions(true)"
```

**Evening and quality tiers**: `--mood=evening`, `--tier=0` … `--tier=3`.

## Limits

- It renders one machine's GPU. Numbers from an M4 say nothing certain about an older Mac; measure
  there (`?debug=1` shows the same stats overlay in a normal browser).
- Headless Chrome on the real GPU behaves like desktop Chrome, but not every driver path is the same
  as in a visible window; `--headed` renders in a visible one.
- The orbit in `--perf` is a steady, synthetic motion. Real interaction (zooming, fast drags)
  can load the GPU differently.
- Screenshots wait for the view to settle; transient artefacts while the camera moves only show in
  orbit frames or in `--perf` numbers.
