#!/usr/bin/env node
/**
 * Render loop for the 3D plan view: opens a plan in Chrome, renders a set of
 * camera views to PNG and writes a report (console errors, failed requests,
 * WebGL renderer, frame stats). With --watch it re-renders whenever assets/,
 * lib/, src/ or templates/ change, keeping the browser open between runs.
 *
 *   bin/render-loop <plan id or URL> [options]
 *
 * Options:
 *   --views=overview,top,front,left,right,surroundings   views to render (default: all)
 *   --orbit=N        also render N frames on a slow orbit (look for flicker / z-fighting)
 *   --perf=SECONDS   orbit continuously and measure frame time and GPU time (report.perf)
 *   --flicker=VIEW   render a view a few times with a tiny camera jitter and map the pixels that flip
 *                    (z-fighting): flicker.png, and report.flicker with the share of flipping pixels
 *   --tier=0..3|auto pin a quality tier (default: ultra = 3); auto lets the performance governor choose
 *   --mood=day|sunset|evening
 *   --width=1600 --height=1000
 *   --dpr=2          device pixel ratio (2 = a Retina Mac display)
 *   --gl=metal|swiftshader   GPU backend (default metal = the real GPU, like desktop Chrome)
 *   --eval=JS        run JS in the page before rendering (experiments: window.configuratorScene …)
 *   --headed         show the browser window
 *   --watch          re-render on file changes
 *   --out=DIR        output directory (default var/render-loop/latest)
 *   --base=URL       app base URL (default http://127.0.0.1:8000)
 */
import { existsSync, mkdirSync, rmSync, watch, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';
import { PNG } from 'pngjs';

const PROJECT_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const CHROME_PATHS = [
    process.env.CHROME_PATH,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
].filter(Boolean);
const ALL_VIEWS = ['overview', 'top', 'front', 'left', 'right', 'surroundings'];
const WATCHED_DIRS = ['assets', 'lib', 'src', 'templates'];
const WATCH_IGNORE = /(^|\/)(vendor|\.DS_Store)|~$/;
const DEBOUNCE_MS = 400;
const READY_TIMEOUT_MS = 30_000;
/** the renderer keeps drawing ~200 ms after a camera change; wait a bit longer */
const SETTLE_MS = 500;
const SURROUNDINGS_SETTLE_MS = 2000;
/** a flip: a pixel changes this much (0–255, summed over r, g, b / 3) between two nearly identical views */
const FLIP_THRESHOLD = 70;
const FLICKER_FRAMES = 6;
const JITTER_RADIANS = 0.0009;
const ORBIT_DEGREES = Number(process.env.ORBIT_DEGREES ?? 24);
const VIEWER_SELECTOR = '[data-controller~="configurator-viewer"]';
/** the debug toolbar and the stats overlay (its numbers go into report.json) stay out of the pictures */
const HIDE_CHROME_CSS = '.sf-toolbar, .sf-minitoolbar, [data-configurator-viewer-target="stats"] { display: none !important; }';

const args = parseArgs(process.argv.slice(2));
if (!args.target || args.help) {
    console.log('Usage: bin/render-loop <plan id or URL> [--views=…] [--orbit=N] [--tier=0..3] [--mood=…] [--watch] [--headed]');
    process.exit(args.help ? 0 : 1);
}

const options = {
    url: planUrl(args.target, args.base ?? 'http://127.0.0.1:8000', args.tier ?? '3'),
    views: (args.views ?? ALL_VIEWS.join(',')).split(',').map((v) => v.trim()).filter(Boolean),
    orbit: Number(args.orbit ?? 0),
    perf: Number(args.perf === true ? 6 : (args.perf ?? 0)),
    flicker: typeof args.flicker === 'string' ? args.flicker : (args.flicker ? 'overview' : null),
    mood: args.mood ?? null,
    width: Number(args.width ?? 1600),
    height: Number(args.height ?? 1000),
    dpr: Number(args.dpr ?? 1),
    out: resolve(PROJECT_DIR, args.out ?? 'var/render-loop/latest'),
    gl: args.gl ?? 'metal',
    headed: Boolean(args.headed),
    watch: Boolean(args.watch),
    eval: typeof args.eval === 'string' ? args.eval : null,
};

const browser = await puppeteer.launch({
    executablePath: CHROME_PATHS.find((path) => existsSync(path)),
    headless: !options.headed,
    defaultViewport: { width: options.width, height: options.height, deviceScaleFactor: options.dpr },
    args: [
        `--use-angle=${options.gl}`,
        ...(options.gl === 'swiftshader' ? ['--enable-unsafe-swiftshader'] : ['--enable-gpu', '--ignore-gpu-blocklist']),
        `--window-size=${options.width},${options.height + 120}`,
        '--hide-scrollbars',
    ],
});
const page = await browser.newPage();
await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]); // instant camera moves

let running = false;
let queued = false;
await run();

if (options.watch) {
    console.log(`\nWatching ${WATCHED_DIRS.join(', ')} … (Ctrl+C to stop)`);
    let timer = null;
    for (const dir of WATCHED_DIRS) {
        watch(join(PROJECT_DIR, dir), { recursive: true }, (_event, file) => {
            if (!file || WATCH_IGNORE.test(file)) {
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(() => {
                console.log(`\n↻ ${dir}/${file}`);
                run();
            }, DEBOUNCE_MS);
        });
    }
    process.on('SIGINT', async () => {
        await browser.close();
        process.exit(0);
    });
} else {
    await browser.close();
}

async function run() {
    if (running) {
        queued = true;
        return;
    }
    running = true;
    const started = Date.now();
    try {
        await renderAll();
        console.log(`✓ done in ${((Date.now() - started) / 1000).toFixed(1)} s → ${options.out}`);
    } catch (error) {
        console.error(`✗ ${error.message}`);
    } finally {
        running = false;
        if (queued) {
            queued = false;
            run();
        }
    }
}

async function renderAll() {
    rmSync(options.out, { recursive: true, force: true });
    mkdirSync(options.out, { recursive: true });
    const report = { url: options.url, console: [], errors: [], failedRequests: [], views: {} };

    const onConsole = async (message) => {
        if (!['error', 'warn', 'warning'].includes(message.type())) {
            return;
        }
        // Error objects passed to console.error only print as [object Object]: keep their stacks
        const stacks = await Promise.all(message.args().map(
            (arg) => arg.evaluate((value) => (value instanceof Error ? value.stack : null)).catch(() => null),
        ));
        report.console.push([`[${message.type()}] ${message.text()}`, ...stacks.filter(Boolean)].join('\n'));
    };
    const onError = (error) => report.errors.push(error.stack ?? error.message);
    const onFailed = (request) => report.failedRequests.push(`${request.failure()?.errorText} ${request.url()}`);
    const onResponse = (response) => {
        if (response.status() >= 400) {
            report.failedRequests.push(`${response.status()} ${response.url()}`);
        }
    };
    page.on('console', onConsole).on('pageerror', onError).on('requestfailed', onFailed).on('response', onResponse);

    try {
        await page.goto(options.url, { waitUntil: 'networkidle2', timeout: READY_TIMEOUT_MS });
        await page.addStyleTag({ content: HIDE_CHROME_CSS });
        await page.waitForFunction(
            () => typeof window.configuratorShowView === 'function'
                && document.querySelector('[data-configurator-viewer-target="canvas"] canvas')?.style.opacity === '1',
            { timeout: READY_TIMEOUT_MS },
        );
        await page.waitForNetworkIdle({ idleTime: 500, timeout: READY_TIMEOUT_MS }).catch(() => {});
        report.webgl = await page.evaluate(webGlInfo);
        // the surroundings load and compile after the first frame: let them settle like a viewer would
        await sleep(SURROUNDINGS_SETTLE_MS);

        if (options.eval) {
            await page.evaluate(options.eval);
            await settle();
            if (options.views.length === 0) {
                // no named views: keep the view the snippet set up
                await (await page.$(VIEWER_SELECTOR)).screenshot({ path: join(options.out, 'eval.png') });
            }
        }

        if (options.mood) {
            await page.click(`[data-configurator-viewer-mood-param="${options.mood}"]`);
            await sleep(SETTLE_MS);
        }

        const viewer = await page.$(VIEWER_SELECTOR);
        for (const view of options.views) {
            const surroundings = view === 'surroundings';
            const result = await page.evaluate(
                (name, withSurroundings) => window.configuratorShowView({ view: name, surroundings: withSurroundings }),
                surroundings ? 'overview' : view,
                surroundings,
            );
            await settle();
            const file = join(options.out, `${view}.png`);
            await viewer.screenshot({ path: file });
            report.views[view] = { ...result, camera: await page.evaluate(cameraInfo) };
            console.log(`  ${view.padEnd(13)} ${file}`);
        }

        if (options.orbit > 0) {
            if (!options.eval) {
                // an --eval snippet may have set up the camera to orbit from
                await page.evaluate(() => window.configuratorShowView({ view: 'overview' }));
                await settle();
            }
            for (let frame = 0; frame < options.orbit; frame++) {
                const angle = (ORBIT_DEGREES * Math.PI / 180) * (frame / Math.max(1, options.orbit - 1) - 0.5);
                await page.evaluate(orbitTo, angle);
                await settle();
                const file = join(options.out, `orbit-${String(frame).padStart(2, '0')}.png`);
                await viewer.screenshot({ path: file });
            }
            console.log(`  orbit         ${options.orbit} frames`);
        }

        if (options.flicker) {
            await page.evaluate(() => { window.__orbitBase = undefined; });
            report.flicker = await measureFlicker(viewer, options.flicker, join(options.out, 'flicker.png'));
            console.log(`  flicker       ${(report.flicker.share * 100).toFixed(3)} % of pixels flip (${report.flicker.pixels} px) → flicker.png`);
        }

        if (options.perf > 0) {
            await page.evaluate(() => window.configuratorShowView({ view: 'overview' }));
            await settle();
            report.perf = await page.evaluate(measurePerformance, options.perf * 1000);
            const { frameMs, gpuMs, fps } = report.perf;
            console.log(`  perf          ${fps} fps  frame p50 ${frameMs.p50} / p95 ${frameMs.p95} ms  GPU p50 ${gpuMs.p50} / p95 ${gpuMs.p95} ms  tier ${report.perf.tier}`);
        }

        report.stats = await page.evaluate(() => window.configuratorStats?.());
        report.scene = await page.evaluate(sceneSummary);
        report.heaviest = await page.evaluate(heaviestMeshes);
    } finally {
        page.off('console', onConsole).off('pageerror', onError).off('requestfailed', onFailed).off('response', onResponse);
        writeFileSync(join(options.out, 'report.json'), JSON.stringify(report, null, 2));
        for (const line of [...report.errors, ...report.console, ...report.failedRequests]) {
            console.log(`  ! ${line}`);
        }
    }
}

/**
 * Renders the view FLICKER_FRAMES times, the camera turned by a hair each time, and
 * counts the pixels that change a lot between neighbouring frames. A hair of camera
 * motion moves edges by less than a pixel; z-fighting flips whole surfaces.
 */
async function measureFlicker(viewer, view, file) {
    // "surroundings": the widest overview (site and everything drawn around it)
    await page.evaluate((name) => window.configuratorShowView(name === 'surroundings' ? { view: 'overview', surroundings: true } : { view: name }), view);
    await settle();
    const frames = [];
    for (let i = 0; i < FLICKER_FRAMES; i++) {
        await page.evaluate(orbitTo, (i % 2 ? 1 : -1) * JITTER_RADIANS * (1 + i));
        await settle();
        frames.push(PNG.sync.read(await viewer.screenshot()));
    }
    const { width, height } = frames[0];
    const heat = new PNG({ width, height });
    let flipped = 0;
    for (let p = 0; p < width * height; p++) {
        let flips = 0;
        for (let f = 1; f < frames.length; f++) {
            const [a, b] = [frames[f - 1].data, frames[f].data];
            const i = p * 4;
            if ((Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2])) / 3 > FLIP_THRESHOLD) {
                flips++;
            }
        }
        // the frame, darkened, with flipping pixels in red
        const i = p * 4;
        const base = frames[0].data;
        const hot = flips >= 2;
        flipped += hot ? 1 : 0;
        heat.data[i] = hot ? 255 : base[i] * 0.4;
        heat.data[i + 1] = hot ? 0 : base[i + 1] * 0.4;
        heat.data[i + 2] = hot ? 0 : base[i + 2] * 0.4;
        heat.data[i + 3] = 255;
    }
    writeFileSync(file, PNG.sync.write(heat));

    return { view, share: flipped / (width * height), pixels: flipped };
}

async function settle() {
    await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    await sleep(SETTLE_MS);
}

/* --- evaluated in the page --- */

function webGlInfo() {
    const gl = document.createElement('canvas').getContext('webgl2');
    const ext = gl?.getExtension('WEBGL_debug_renderer_info');

    return ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : 'unknown';
}

function cameraInfo() {
    const { camera, controls } = window.configuratorView;
    const round = (v) => v.toArray().map((n) => Math.round(n * 100) / 100);

    return { position: round(camera.position), target: round(controls.target), near: camera.near, far: camera.far };
}

/** keeps distance and height, turns the camera around the target by `angle` radians from the overview */
function orbitTo(angle) {
    const { camera, controls, render } = window.configuratorView;
    window.__orbitBase ??= camera.position.clone().sub(controls.target);
    const offset = window.__orbitBase.clone().applyAxisAngle({ x: 0, y: 1, z: 0, isVector3: true }, angle);
    camera.position.copy(controls.target).add(offset);
    controls.update();
    render();
}

/**
 * Orbits the camera for `duration` ms, a frame per animation frame, and returns the
 * distribution of frame intervals (what the user feels) and of GPU time per frame.
 */
function measurePerformance(duration) {
    const { camera, controls, render } = window.configuratorView;
    const base = camera.position.clone().sub(controls.target);
    const intervals = [];
    const gpu = [];
    const percentile = (values, p) => {
        const sorted = values.filter((v) => Number.isFinite(v)).sort((a, b) => a - b);
        return sorted.length ? Math.round(sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))] * 10) / 10 : null;
    };

    return new Promise((done) => {
        const start = performance.now();
        let last = start;
        const step = (now) => {
            intervals.push(now - last);
            last = now;
            const gpuMs = Number(window.configuratorStats()?.gpuMs);
            gpu.push(gpuMs);
            const angle = ((now - start) / duration) * Math.PI * 0.6 - Math.PI * 0.3;
            camera.position.copy(controls.target).add(base.clone().applyAxisAngle({ x: 0, y: 1, z: 0, isVector3: true }, angle));
            controls.update();
            render();
            if (now - start < duration) {
                requestAnimationFrame(step);
                return;
            }
            const stats = window.configuratorStats();
            const frames = intervals.slice(5); // skip the warm-up
            done({
                fps: Math.round(1000 / (frames.reduce((a, b) => a + b, 0) / frames.length)),
                frameMs: { p50: percentile(frames, 0.5), p95: percentile(frames, 0.95), max: percentile(frames, 1) },
                gpuMs: { p50: percentile(gpu, 0.5), p95: percentile(gpu, 0.95) },
                tier: stats.tier,
                pixelRatio: stats.pixelRatio,
                drawCalls: stats.drawCalls,
                triangles: stats.triangles,
                textures: stats.textures,
            });
        };
        requestAnimationFrame(step);
    });
}

/** the meshes with the most triangles (instances counted), to see where the GPU's time goes */
function heaviestMeshes() {
    const totals = new Map();
    window.configuratorScene.traverse((object) => {
        if (!object.isMesh || !object.visible || !object.geometry) {
            return;
        }
        const geometry = object.geometry;
        const triangles = (geometry.index ? geometry.index.count : geometry.attributes.position.count) / 3;
        const instances = object.isInstancedMesh ? object.count : 1;
        const name = object.name || object.parent?.name || geometry.type;
        const entry = totals.get(name) ?? { name, meshes: 0, triangles: 0, castShadow: object.castShadow };
        entry.meshes += 1;
        entry.triangles += triangles * instances;
        totals.set(name, entry);
    });

    return [...totals.values()].sort((a, b) => b.triangles - a.triangles).slice(0, 12);
}

/** big meshes and their bounds, to spot misplaced planes */
function sceneSummary() {
    const meshes = [];
    window.configuratorScene.traverse((object) => {
        if (!object.isMesh || !object.visible || !object.geometry) {
            return;
        }
        object.geometry.computeBoundingBox?.();
        const box = object.geometry.boundingBox?.clone().applyMatrix4(object.matrixWorld);
        if (!box) {
            return;
        }
        const size = box.max.clone().sub(box.min);
        const round = (v) => v.toArray().map((n) => Math.round(n * 10) / 10);
        meshes.push({
            name: object.name || object.parent?.name || object.geometry.type,
            material: object.material?.name || object.material?.type,
            min: round(box.min),
            max: round(box.max),
            area: Math.round(size.x * size.z),
        });
    });

    return meshes.sort((a, b) => b.area - a.area).slice(0, 25);
}

/* --- helpers --- */

function planUrl(target, base, tier) {
    const url = new URL(/^https?:\/\//.test(target) ? target : `/plan/${target}`, base);
    url.searchParams.set('debug', '1');
    if (tier !== 'auto') {
        url.searchParams.set('tier', tier);
    }

    return url.toString();
}

function parseArgs(argv) {
    const parsed = {};
    for (const arg of argv) {
        const match = arg.match(/^--([^=]+)(?:=(.*))?$/);
        if (match) {
            parsed[match[1]] = match[2] ?? true;
        } else {
            parsed.target = arg;
        }
    }

    return parsed;
}

function sleep(ms) {
    return new Promise((done) => setTimeout(done, ms));
}
