import * as THREE from 'three';

/**
 * Surface patterns drawn on a canvas, so products need no image files. Names
 * match Core\Configurator\Scene\ProceduralTexture. Each pattern yields a
 * near-white colour map (the material colour tints it) and a bump map; one
 * repeat covers `textureSize` metres, see applyMetricUVs().
 */
const SIZE = 512;
const cache = new Map();

export const PAVING = 'paving';
export const PLASTER = 'plaster';
export const PLANKS = 'planks';
export const POWDER_COAT = 'powder_coat';
export const ROOF_TILES = 'roof_tiles';
export const INTERIOR = 'interior';
export const FOLIAGE = 'foliage';
export const LAWN = 'lawn';
export const STONES = 'stones';
export const WOOD_GRAIN = 'wood_grain';
export const FACADE_PLASTER = 'facade_plaster';
export const FACADE_RENDER = 'facade_render';
export const GLAZING = 'glazing';
export const FACADE_BRICK = 'facade_brick';
export const FACADE_RIBBON = 'facade_ribbon';
export const FACADE_CLADDING = 'facade_cladding';
export const ROOF_GRAVEL = 'roof_gravel';
export const ASPHALT = 'asphalt';

/**
 * Façade patterns cover FACADE_BAYS × FACADE_BAYS window bays of BAY_METRES per repeat
 * (Site\\Facade: one repeat = 12 m), so neighbouring windows differ (blinds, curtains,
 * which ones are lit in the evening) instead of repeating one window everywhere.
 */
const BAY_METRES = 3;
const FACADE_BAYS = 4;
const FACADE_SIZE = 1024;
const BAY = FACADE_SIZE / FACADE_BAYS;
const PX = BAY / BAY_METRES;
/** share of windows lit in the evening */
const LIT_SHARE = 0.55;
/**
 * The evening glow has its own, coarser sheet of GLOW_COLUMNS × GLOW_ROWS bays: with the
 * colour sheet's 4 × 4 the lit windows and the blinds only line up again every 28 bays
 * and 20 storeys, so no regular pattern shows on a long façade.
 */
const GLOW_COLUMNS = 7;
const GLOW_ROWS = 5;
const GLOW_BAY = 96;

/**
 * @returns {{map: THREE.Texture, bumpMap: THREE.Texture, glowMap: THREE.Texture|null, bumpScale: number}|null}
 */
export function proceduralTexture(name) {
    if (!PATTERNS[name]) {
        console.warn(`procedural_textures: unknown texture "${name}"`);
        return null;
    }
    if (!cache.has(name)) {
        const { draw, bumpScale, size = SIZE, windows = null } = PATTERNS[name];
        const random = seededRandom(hash(name));
        const colour = createCanvas(size);
        const bump = createCanvas(size);
        draw(colour.getContext('2d'), bump.getContext('2d'), random);
        cache.set(name, {
            map: toTexture(colour, THREE.SRGBColorSpace),
            bumpMap: toTexture(bump, THREE.NoColorSpace),
            // lit windows only (black elsewhere): the evening emissive map of a façade
            glowMap: windows ? glowTexture(windows, random) : null,
            bumpScale,
        });
    }

    return cache.get(name);
}

/**
 * UVs for an upright extrusion (a building footprint lying flat, see Slab::onGround):
 * on every wall u runs along that wall and v up from its foot, in metres, so window
 * rows sit on the storeys of every wall whatever its direction. The caps keep plan
 * coordinates. `depth` is the extrusion's height; local z runs from +depth/2 at the
 * foot to -depth/2 at the top (the part is turned by +90° about x). `seed` shifts the
 * pattern by whole bays and storeys, so neighbouring buildings differ.
 */
/** @param {number} textureHeight metres one repeat covers upwards (a storey per window row); textureSize when not given */
export function applyUprightUVs(geometry, depth, textureSize, seed = 0, textureHeight = textureSize) {
    const position = geometry.attributes.position;
    const normal = geometry.attributes.normal;
    const uv = geometry.attributes.uv;
    const corner = new THREE.Vector3();
    // each building starts at another bay and storey of the façade sheets (whole bays, so windows stay on the storeys)
    const shiftU = (Math.abs(seed) % 97) / FACADE_BAYS;
    const shiftV = (Math.abs(seed >> 7) % 89) / FACADE_BAYS;
    for (let i = 0; i < position.count; i += 3) {
        if (Math.abs(normal.getZ(i)) > 0.5) {
            for (let k = i; k < i + 3; k++) {
                uv.setXY(k, position.getX(k) / textureSize, position.getY(k) / textureSize);
            }
            continue;
        }
        // a wall triangle: its direction along the ground is perpendicular to its normal
        corner.set(-normal.getY(i), normal.getX(i), 0).normalize();
        for (let k = i; k < i + 3; k++) {
            const along = position.getX(k) * corner.x + position.getY(k) * corner.y;
            uv.setXY(k, along / textureSize + shiftU, (depth / 2 - position.getZ(k)) / textureHeight + shiftV);
        }
    }
    uv.needsUpdate = true;

    return geometry;
}

/**
 * Replaces a geometry's UVs with planar coordinates in metres, projected along
 * each face's dominant normal axis, so a texture keeps its real-world scale on
 * parts of any size.
 */
export function applyMetricUVs(geometry, textureSize) {
    const position = geometry.attributes.position;
    const normal = geometry.attributes.normal;
    const uv = geometry.attributes.uv;
    for (let i = 0; i < position.count; i++) {
        const nx = Math.abs(normal.getX(i));
        const ny = Math.abs(normal.getY(i));
        const nz = Math.abs(normal.getZ(i));
        const [u, v] = ny >= nx && ny >= nz
            ? [position.getX(i), position.getZ(i)]
            : nx >= nz
                ? [position.getZ(i), position.getY(i)]
                : [position.getX(i), position.getY(i)];
        uv.setXY(i, u / textureSize, v / textureSize);
    }
    uv.needsUpdate = true;

    return geometry;
}

const PATTERNS = {
    // 2 × 2 large slabs per repeat, each slightly different, with sunken joints
    [PAVING]: {
        bumpScale: 2,
        draw(colour, bump, random) {
            const slabs = 2;
            const slab = SIZE / slabs;
            const joint = 6;
            fill(colour, '#8a8780');
            fill(bump, '#000');
            for (let x = 0; x < slabs; x++) {
                for (let y = 0; y < slabs; y++) {
                    const shade = 225 + Math.round(random() * 22);
                    colour.fillStyle = `rgb(${shade},${shade - 3},${shade - 8})`;
                    colour.fillRect(x * slab + joint / 2, y * slab + joint / 2, slab - joint, slab - joint);
                    bump.fillStyle = '#fff';
                    bump.fillRect(x * slab + joint / 2, y * slab + joint / 2, slab - joint, slab - joint);
                }
            }
            speckle(colour, random, 9000, 0.08);
            speckle(bump, random, 4000, 0.15);
        },
    },
    // fine render with soft blotches
    [PLASTER]: {
        bumpScale: 1.5,
        draw(colour, bump, random) {
            fill(colour, '#f4f4f4');
            fill(bump, '#808080');
            blotches(colour, random, 40, 0.018);
            speckle(colour, random, 30000, 0.06);
            speckle(bump, random, 40000, 0.5);
        },
    },
    // boards along the width with grain
    [PLANKS]: {
        bumpScale: 1.5,
        draw(colour, bump, random) {
            const boards = 4;
            const board = SIZE / boards;
            fill(bump, '#fff');
            for (let i = 0; i < boards; i++) {
                const shade = 205 + Math.round(random() * 40);
                colour.fillStyle = `rgb(${shade},${shade - 12},${shade - 30})`;
                colour.fillRect(0, i * board, SIZE, board);
                for (let g = 0; g < 60; g++) {
                    colour.strokeStyle = `rgba(80,50,20,${random() * 0.12})`;
                    colour.beginPath();
                    const y = i * board + random() * board;
                    colour.moveTo(0, y);
                    colour.bezierCurveTo(SIZE / 3, y + random() * 6 - 3, (2 * SIZE) / 3, y + random() * 6 - 3, SIZE, y);
                    colour.stroke();
                }
                bump.fillStyle = '#000';
                bump.fillRect(0, i * board, SIZE, 4);
            }
        },
    },
    // flat concrete tiles in staggered rows, each slightly different, with a shadow line under every row
    [ROOF_TILES]: {
        bumpScale: 3,
        draw(colour, bump, random) {
            const rows = 4;
            const columns = 3;
            const rowHeight = SIZE / rows;
            const tileWidth = SIZE / columns;
            fill(bump, '#000');
            for (let row = 0; row < rows; row++) {
                const offset = row % 2 ? tileWidth / 2 : 0;
                for (let column = -1; column <= columns; column++) {
                    const shade = 200 + Math.round(random() * 40);
                    const x = column * tileWidth + offset;
                    const y = row * rowHeight;
                    const gradient = colour.createLinearGradient(0, y, 0, y + rowHeight);
                    gradient.addColorStop(0, `rgb(${shade - 45},${shade - 47},${shade - 50})`);
                    gradient.addColorStop(0.15, `rgb(${shade},${shade - 2},${shade - 5})`);
                    gradient.addColorStop(1, `rgb(${shade - 15},${shade - 17},${shade - 20})`);
                    colour.fillStyle = gradient;
                    colour.fillRect(x + 2, y, tileWidth - 4, rowHeight);
                    const ramp = bump.createLinearGradient(0, y, 0, y + rowHeight);
                    ramp.addColorStop(0, '#202020');
                    ramp.addColorStop(1, '#ffffff');
                    bump.fillStyle = ramp;
                    bump.fillRect(x + 2, y, tileWidth - 4, rowHeight);
                }
            }
            speckle(colour, random, 12000, 0.07);
            blotches(colour, random, 25, 0.08, [40, 60, 30]);
        },
    },
    // a lit room seen through glass: brighter ceiling, a lamp's hotspot, a curtain, dark furniture below
    [INTERIOR]: {
        bumpScale: 0,
        draw(colour, bump, random) {
            const room = colour.createLinearGradient(0, 0, 0, SIZE);
            room.addColorStop(0, '#ffffff');
            room.addColorStop(0.55, '#c9b9a6');
            room.addColorStop(1, '#4a4038');
            colour.fillStyle = room;
            colour.fillRect(0, 0, SIZE, SIZE);
            for (let i = 0; i < 2; i++) {
                const x = SIZE * (0.25 + 0.5 * i) + (random() - 0.5) * 60;
                const lamp = colour.createRadialGradient(x, SIZE * 0.3, 0, x, SIZE * 0.3, SIZE * 0.35);
                lamp.addColorStop(0, 'rgba(255,255,240,0.9)');
                lamp.addColorStop(1, 'rgba(255,255,240,0)');
                colour.fillStyle = lamp;
                colour.fillRect(0, 0, SIZE, SIZE);
            }
            // curtains: soft vertical folds at both edges
            for (const left of [0, SIZE * 0.82]) {
                for (let fold = 0; fold < 6; fold++) {
                    colour.fillStyle = fold % 2 ? 'rgba(120,96,80,0.55)' : 'rgba(175,150,130,0.55)';
                    colour.fillRect(left + fold * (SIZE * 0.03), 0, SIZE * 0.03, SIZE);
                }
            }
            // a sofa back and a shelf, as silhouettes
            colour.fillStyle = 'rgba(40,32,28,0.75)';
            colour.fillRect(SIZE * 0.3, SIZE * 0.72, SIZE * 0.4, SIZE * 0.28);
            colour.fillRect(SIZE * 0.08, SIZE * 0.45, SIZE * 0.1, SIZE * 0.55);
            fill(bump, '#808080');
        },
    },
    // dense leaves: overlapping small ellipses in light and dark greens, deep gaps between them
    [FOLIAGE]: {
        bumpScale: 2.5,
        draw(colour, bump, random) {
            fill(colour, '#7d8f6a');
            fill(bump, '#000');
            for (let i = 0; i < 2600; i++) {
                const x = random() * SIZE;
                const y = random() * SIZE;
                const size = 6 + random() * 9;
                const angle = random() * Math.PI;
                const light = 0.75 + random() * 0.5;
                for (const [dx, dy] of [[0, 0], [SIZE, 0], [-SIZE, 0], [0, SIZE], [0, -SIZE]]) {
                    colour.fillStyle = `rgb(${Math.round(200 * light)},${Math.round(225 * light)},${Math.round(175 * light)})`;
                    colour.beginPath();
                    colour.ellipse(x + dx, y + dy, size, size * 0.55, angle, 0, Math.PI * 2);
                    colour.fill();
                    bump.fillStyle = `rgb(${Math.round(255 * light * 0.8)},${Math.round(255 * light * 0.8)},${Math.round(255 * light * 0.8)})`;
                    bump.beginPath();
                    bump.ellipse(x + dx, y + dy, size, size * 0.55, angle, 0, Math.PI * 2);
                    bump.fill();
                }
            }
        },
    },
    // barely visible orange peel of a powder coating, mostly felt in highlights
    [POWDER_COAT]: {
        bumpScale: 0.3,
        draw(colour, bump, random) {
            fill(colour, '#ffffff');
            fill(bump, '#808080');
            speckle(colour, random, 20000, 0.03);
            speckle(bump, random, 60000, 0.6);
        },
    },
    // broken stones packed in a gabion: irregular polygons, each a little different,
    // overlapping so only deep crevices stay dark; drawn wrapped so the texture tiles
    [STONES]: {
        bumpScale: 4,
        draw(colour, bump, random) {
            fill(colour, '#3b3935');
            fill(bump, '#000');
            for (let i = 0; i < 260; i++) {
                const x = random() * SIZE;
                const y = random() * SIZE;
                const r = 22 + random() * 22;
                const corners = 6 + Math.floor(random() * 4);
                const turn = random() * Math.PI;
                const outline = Array.from({ length: corners }, (_, c) => {
                    const a = turn + (c / corners) * Math.PI * 2;
                    const d = r * (0.7 + random() * 0.35);

                    return [Math.cos(a) * d, Math.sin(a) * d * (0.75 + random() * 0.2)];
                });
                const shade = 150 + Math.round(random() * 85);
                const warm = Math.round(random() * 10);
                for (const [dx, dy] of [[0, 0], [SIZE, 0], [-SIZE, 0], [0, SIZE], [0, -SIZE], [SIZE, SIZE], [-SIZE, -SIZE], [SIZE, -SIZE], [-SIZE, SIZE]]) {
                    const cx = x + dx;
                    const cy = y + dy;
                    if (cx < -r || cx > SIZE + r || cy < -r || cy > SIZE + r) {
                        continue;
                    }
                    const path = new Path2D();
                    outline.forEach(([px, py], c) => (c ? path.lineTo(cx + px, cy + py) : path.moveTo(cx + px, cy + py)));
                    path.closePath();
                    // lit from above: lighter top, darker bottom, a dark seam around
                    const light = colour.createLinearGradient(cx, cy - r, cx, cy + r);
                    light.addColorStop(0, `rgb(${shade + 15},${shade + 12 - warm},${shade + 6 - warm})`);
                    light.addColorStop(1, `rgb(${shade - 45},${shade - 47 - warm},${shade - 52 - warm})`);
                    colour.fillStyle = light;
                    colour.fill(path);
                    colour.strokeStyle = 'rgba(30,28,25,0.6)';
                    colour.lineWidth = 2;
                    colour.stroke(path);
                    const dome = bump.createRadialGradient(cx, cy, 0, cx, cy, r);
                    dome.addColorStop(0, '#fff');
                    dome.addColorStop(1, '#383838');
                    bump.fillStyle = dome;
                    bump.fill(path);
                }
            }
            speckle(colour, random, 25000, 0.08);
            speckle(bump, random, 20000, 0.25);
        },
    },
    // co-extruded WPC: long, fine brushed grain along the width, a few darker streaks, no joints
    [WOOD_GRAIN]: {
        bumpScale: 1.6,
        draw(colour, bump, random) {
            fill(colour, '#e6e0d8');
            fill(bump, '#808080');
            for (let i = 0; i < 700; i++) {
                const y = random() * SIZE;
                const dark = random() < 0.15;
                colour.strokeStyle = dark ? `rgba(60,40,25,${0.08 + random() * 0.12})` : `rgba(255,250,240,${random() * 0.15})`;
                colour.lineWidth = dark ? 1 + random() * 2 : 1;
                colour.beginPath();
                colour.moveTo(0, y);
                colour.bezierCurveTo(SIZE / 3, y + random() * 3 - 1.5, (2 * SIZE) / 3, y + random() * 3 - 1.5, SIZE, y);
                colour.stroke();
                bump.strokeStyle = `rgba(${dark ? 0 : 255},${dark ? 0 : 255},${dark ? 0 : 255},0.25)`;
                bump.beginPath();
                bump.moveTo(0, y);
                bump.lineTo(SIZE, y);
                bump.stroke();
            }
            blotches(colour, random, 20, 0.05, [70, 50, 30]);
        },
    },
    // mown lawn: patches of green with blade-like streaks
    [LAWN]: {
        bumpScale: 1,
        draw(colour, bump, random) {
            fill(colour, '#e2edd4');
            fill(bump, '#808080');
            blotches(colour, random, 90, 0.05, [60, 90, 30]);
            for (let i = 0; i < 40000; i++) {
                const x = random() * SIZE;
                const y = random() * SIZE;
                const g = 150 + random() * 90;
                colour.strokeStyle = `rgba(${g * 0.55},${g},${g * 0.35},0.18)`;
                colour.beginPath();
                colour.moveTo(x, y);
                colour.lineTo(x + random() * 2 - 1, y - 3 - random() * 4);
                colour.stroke();
            }
            speckle(bump, random, 50000, 0.7);
        },
    },
    // rendered wall, a punched window per storey and bay
    [FACADE_PLASTER]: {
        bumpScale: 1.5,
        size: FACADE_SIZE,
        windows: (x, y, bay) => [punched(x, y, bay, 1.3, 1.5, 0.9)],
        draw(colour, bump, random) {
            fill(colour, '#f4f4f2');
            fill(bump, '#808080');
            blotches(colour, random, 80, 0.02);
            speckle(colour, random, 80000, 0.05);
            speckle(bump, random, 80000, 0.4);
            eachBay((x, y) => punchedWindow(colour, bump, random, PATTERNS[FACADE_PLASTER].windows(x, y, BAY)[0]));
        },
    },
    // a newer rendered wall: tall windows in dark (anthracite) frames, a slim metal sill
    [FACADE_RENDER]: {
        bumpScale: 1.2,
        size: FACADE_SIZE,
        windows: (x, y, bay) => [punched(x, y, bay, 1.5, 2.2, 0.55)],
        draw(colour, bump, random) {
            fill(colour, '#f6f5f2');
            fill(bump, '#808080');
            blotches(colour, random, 60, 0.015);
            speckle(colour, random, 60000, 0.04);
            speckle(bump, random, 60000, 0.3);
            eachBay((x, y) => punchedWindow(colour, bump, random, PATTERNS[FACADE_RENDER].windows(x, y, BAY)[0], DARK_FRAME, METAL_SILL));
        },
    },
    // a glazed storey behind a glass railing: four panels across between slim dark mullions,
    // a handrail at a third of the height; one repeat is 6 m wide and one storey (3.2 m) high
    [GLAZING]: {
        bumpScale: 0.5,
        size: FACADE_SIZE,
        draw(colour, bump, random) {
            fill(bump, '#808080');
            const panels = 4;
            const width = FACADE_SIZE / panels;
            for (let i = 0; i < panels; i++) {
                glass(colour, i * width, 0, width, FACADE_SIZE, random);
                // a few blinds half down, as in any office or classroom
                if (random() < 0.3) {
                    colour.fillStyle = 'rgba(220,216,206,0.55)';
                    colour.fillRect(i * width + 10, 10, width - 20, FACADE_SIZE * (0.15 + random() * 0.3));
                }
            }
            colour.fillStyle = '#2f2c2a';
            for (let i = 0; i <= panels; i++) {
                colour.fillRect(i * width - 7, 0, 14, FACADE_SIZE);
            }
            // the floor slab's edge at the top and bottom, the railing's handrail and its glass
            colour.fillRect(0, 0, FACADE_SIZE, 18);
            colour.fillRect(0, FACADE_SIZE - 18, FACADE_SIZE, 18);
            colour.fillStyle = '#5a5552';
            colour.fillRect(0, FACADE_SIZE * 0.66, FACADE_SIZE, 10);
            colour.fillStyle = 'rgba(190,210,215,0.25)';
            colour.fillRect(0, FACADE_SIZE * 0.66 + 10, FACADE_SIZE, FACADE_SIZE * 0.34 - 18);
        },
    },
    // facing brick in running bond, a soldier course over each punched window
    [FACADE_BRICK]: {
        bumpScale: 2,
        size: FACADE_SIZE,
        windows: (x, y, bay) => [punched(x, y, bay, 1.25, 1.45, 0.95)],
        draw(colour, bump, random) {
            const rows = 42 * FACADE_BAYS;
            const columns = 12 * FACADE_BAYS;
            const rowHeight = FACADE_SIZE / rows;
            const brickWidth = FACADE_SIZE / columns;
            const joint = 1.5;
            fill(colour, '#ffffff');
            fill(bump, '#000');
            for (let row = 0; row < rows; row++) {
                const offset = row % 2 ? brickWidth / 2 : 0;
                for (let column = -1; column <= columns; column++) {
                    const shade = 168 + Math.round(random() * 52);
                    colour.fillStyle = `rgb(${shade},${shade - 4},${shade - 8})`;
                    colour.fillRect(column * brickWidth + offset + joint / 2, row * rowHeight + joint / 2, brickWidth - joint, rowHeight - joint);
                    bump.fillStyle = '#fff';
                    bump.fillRect(column * brickWidth + offset + joint / 2, row * rowHeight + joint / 2, brickWidth - joint, rowHeight - joint);
                }
            }
            speckle(colour, random, 50000, 0.08);
            eachBay((x, y) => {
                const opening = PATTERNS[FACADE_BRICK].windows(x, y, BAY)[0];
                punchedWindow(colour, bump, random, opening);
                // soldier course: upright bricks over the opening, a little darker
                colour.fillStyle = 'rgba(0,0,0,0.16)';
                colour.fillRect(opening.x - 4, opening.y - rowHeight * 2, opening.width + 8, rowHeight * 2 - 1);
            });
        },
    },
    // a window band per storey: parapet panel below, panes with mullions, slab edge above
    [FACADE_RIBBON]: {
        bumpScale: 1,
        size: FACADE_SIZE,
        windows: (x, y, bay) => {
            const metre = bay / BAY_METRES;
            const top = y + bay - 2.65 * metre;

            return [0, 1].map((pane) => ({ x: x + (pane * bay) / 2, y: top, width: bay / 2, height: 1.6 * metre }));
        },
        draw(colour, bump, random) {
            fill(colour, '#f2f2f0');
            fill(bump, '#909090');
            speckle(colour, random, 40000, 0.04);
            eachBay((x, y) => {
                const panes = PATTERNS[FACADE_RIBBON].windows(x, y, BAY);
                for (const pane of panes) {
                    glass(colour, pane.x, pane.y, pane.width, pane.height, random);
                    blind(colour, pane.x, pane.y, pane.width, pane.height, random);
                    colour.fillStyle = '#d6d8d8';
                    colour.fillRect(pane.x - 2, pane.y, 4, pane.height);
                }
                const { y: top, height } = panes[0];
                bump.fillStyle = '#000';
                bump.fillRect(x, top, BAY, height);
                // a transom near the top, shadow under the slab edge, a light sill line
                colour.fillStyle = '#d6d8d8';
                colour.fillRect(x, top + height * 0.22, BAY, 2);
                colour.fillStyle = 'rgba(0,0,0,0.25)';
                colour.fillRect(x, top, BAY, 3);
                colour.fillStyle = 'rgba(255,255,255,0.7)';
                colour.fillRect(x, top + height, BAY, 2);
                // panel joints in the parapet
                colour.fillStyle = 'rgba(0,0,0,0.08)';
                colour.fillRect(x + BAY / 2, top + height, 1, BAY - (top - y) - height);
            });
        },
    },
    // vertical profiled sheets, a seam per storey, a strip of high windows
    [FACADE_CLADDING]: {
        bumpScale: 2,
        size: FACADE_SIZE,
        windows: (x, y, bay) => {
            const metre = bay / BAY_METRES;

            return [{ x, y: y + bay - 2.7 * metre, width: bay, height: 0.45 * metre }];
        },
        draw(colour, bump, random) {
            const ribs = 16 * FACADE_BAYS;
            const rib = FACADE_SIZE / ribs;
            for (let i = 0; i < ribs; i++) {
                const ramp = colour.createLinearGradient(i * rib, 0, (i + 1) * rib, 0);
                ramp.addColorStop(0, '#d8d8d8');
                ramp.addColorStop(0.3, '#ffffff');
                ramp.addColorStop(0.75, '#ececec');
                ramp.addColorStop(1, '#bcbcbc');
                colour.fillStyle = ramp;
                colour.fillRect(i * rib, 0, rib, FACADE_SIZE);
                const height = bump.createLinearGradient(i * rib, 0, (i + 1) * rib, 0);
                height.addColorStop(0, '#000');
                height.addColorStop(0.5, '#fff');
                height.addColorStop(1, '#000');
                bump.fillStyle = height;
                bump.fillRect(i * rib, 0, rib, FACADE_SIZE);
            }
            blotches(colour, random, 40, 0.05);
            eachBay((x, y) => {
                colour.fillStyle = 'rgba(0,0,0,0.18)';
                colour.fillRect(x, y + BAY - 2, BAY, 2);
                const strip = PATTERNS[FACADE_CLADDING].windows(x, y, BAY)[0];
                glass(colour, strip.x, strip.y, strip.width, strip.height, random);
                colour.fillStyle = '#c8cacb';
                for (let i = 0; i < 4; i++) {
                    colour.fillRect(x + (i * BAY) / 4, strip.y, 3, strip.height);
                }
            });
        },
    },
    // worn asphalt: fine grain with light stones, darker stains, the odd lighter repair patch
    [ASPHALT]: {
        bumpScale: 0.8,
        draw(colour, bump, random) {
            fill(colour, '#d6d6d4');
            fill(bump, '#808080');
            blotches(colour, random, 60, 0.12);
            blotches(colour, random, 6, 0.08, [255, 255, 255]);
            speckle(colour, random, 70000, 0.16);
            speckle(bump, random, 50000, 0.5);
        },
    },
    // gravel on a flat roof: dense pebbles, faint seams of the membrane strips below
    [ROOF_GRAVEL]: {
        bumpScale: 1.5,
        draw(colour, bump, random) {
            fill(colour, '#e6e5e1');
            fill(bump, '#808080');
            blotches(colour, random, 50, 0.06);
            speckle(colour, random, 60000, 0.14);
            speckle(bump, random, 60000, 0.6);
            colour.fillStyle = 'rgba(0,0,0,0.05)';
            for (let i = 1; i < 4; i++) {
                colour.fillRect(0, (i * SIZE) / 4, SIZE, 2);
            }
        },
    },
};

/** calls draw(x, y) with the top-left pixel of every window bay of a façade sheet */
function eachBay(draw) {
    for (let row = 0; row < FACADE_BAYS; row++) {
        for (let column = 0; column < FACADE_BAYS; column++) {
            draw(column * BAY, row * BAY);
        }
    }
}

/** a window in the middle of a bay of `bay` pixels: sizes in metres, the sill from the foot of the storey */
function punched(bayX, bayY, bay, width, height, sill) {
    const metre = bay / BAY_METRES;

    return { x: bayX + (bay - width * metre) / 2, y: bayY + bay - (sill + height) * metre, width: width * metre, height: height * metre };
}

const LIGHT_FRAME = '#eceae6';
const DARK_FRAME = '#3a3735';
const STONE_SILL = '#ffffff';
const METAL_SILL = '#cfd0ce';

/** A punched window: reveal shadow, frame, glass with a mullion (blinds or curtains in some), a sill. */
function punchedWindow(colour, bump, random, { x, y, width: w, height: h }, frameColour = LIGHT_FRAME, sillColour = STONE_SILL) {
    const frame = 4;
    colour.fillStyle = frameColour;
    colour.fillRect(x, y, w, h);
    glass(colour, x + frame, y + frame, w - 2 * frame, h - 2 * frame, random);
    blind(colour, x + frame, y + frame, w - 2 * frame, h - 2 * frame, random);
    colour.fillStyle = frameColour;
    colour.fillRect(x + w / 2 - frame / 2, y, frame, h);
    // the reveal: shade from the lintel and the left jamb
    colour.fillStyle = 'rgba(0,0,0,0.28)';
    colour.fillRect(x, y, w, 5);
    colour.fillRect(x, y, 4, h);
    colour.fillStyle = sillColour;
    colour.fillRect(x - 5, y + h, w + 10, 4);
    colour.fillStyle = 'rgba(0,0,0,0.2)';
    colour.fillRect(x - 5, y + h + 4, w + 10, 2);
    bump.fillStyle = '#000';
    bump.fillRect(x, y, w, h);
    bump.fillStyle = '#fff';
    bump.fillRect(x - 5, y + h, w + 10, 4);
}

/**
 * The evening glow of a façade: its own sheet of GLOW_COLUMNS × GLOW_ROWS bays with
 * some windows lit, repeated so one bay covers as much wall as on the colour sheet.
 */
function glowTexture(windows, random) {
    const canvas = createCanvas(GLOW_COLUMNS * GLOW_BAY, GLOW_ROWS * GLOW_BAY);
    const glow = canvas.getContext('2d');
    fill(glow, '#000');
    for (let row = 0; row < GLOW_ROWS; row++) {
        for (let column = 0; column < GLOW_COLUMNS; column++) {
            for (const pane of windows(column * GLOW_BAY, row * GLOW_BAY, GLOW_BAY)) {
                light(glow, pane, random);
            }
        }
    }
    const texture = toTexture(canvas, THREE.SRGBColorSpace);
    texture.repeat.set(FACADE_BAYS / GLOW_COLUMNS, FACADE_BAYS / GLOW_ROWS);

    return texture;
}

/** dark glass reflecting a bright sky at the top and the ground at the bottom */
function glass(colour, x, y, width, height, random) {
    const tone = random() * 22;
    const gradient = colour.createLinearGradient(0, y, 0, y + height);
    gradient.addColorStop(0, `rgb(${118 + tone},${134 + tone},${150 + tone})`);
    gradient.addColorStop(0.45, `rgb(${56 + tone},${68 + tone},${80 + tone})`);
    gradient.addColorStop(1, `rgb(${40 + tone},${46 + tone},${52 + tone})`);
    colour.fillStyle = gradient;
    colour.fillRect(x, y, width, height);
}

/** some windows have a blind half or fully down, some light curtains at the sides */
function blind(colour, x, y, width, height, random) {
    const pick = random();
    if (pick < 0.3) {
        const down = height * (0.25 + random() * 0.75);
        colour.fillStyle = random() < 0.5 ? 'rgba(225,222,214,0.92)' : 'rgba(150,146,140,0.9)';
        colour.fillRect(x, y, width, down);
        colour.fillStyle = 'rgba(0,0,0,0.12)';
        for (let slat = y + 3; slat < y + down; slat += 3) {
            colour.fillRect(x, slat, width, 1);
        }
    } else if (pick < 0.5) {
        colour.fillStyle = 'rgba(236,230,218,0.75)';
        colour.fillRect(x, y, width * 0.22, height);
        colour.fillRect(x + width * 0.78, y, width * 0.22, height);
    }
}

/** a lit room on the glow map: warm (now and then cool), brighter at the ceiling; most windows stay dark */
function light(glow, { x, y, width, height }, random) {
    if (random() > LIT_SHARE) {
        return;
    }
    const warm = random() < 0.75;
    const dim = 0.55 + random() * 0.45;
    const gradient = glow.createLinearGradient(0, y, 0, y + height);
    gradient.addColorStop(0, warm ? `rgb(${255 * dim},${214 * dim},${150 * dim})` : `rgb(${220 * dim},${232 * dim},${255 * dim})`);
    gradient.addColorStop(1, warm ? `rgb(${150 * dim},${100 * dim},${60 * dim})` : `rgb(${120 * dim},${130 * dim},${150 * dim})`);
    glow.fillStyle = gradient;
    glow.fillRect(x + 1, y + 1, width - 2, height - 2);
}

function createCanvas(width = SIZE, height = width) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    return canvas;
}

function toTexture(canvas, colorSpace) {
    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = colorSpace;
    texture.anisotropy = 8;

    return texture;
}

function fill(ctx, style) {
    ctx.fillStyle = style;
    ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
}

/** single-pixel noise, lighter and darker, `strength` as alpha */
function speckle(ctx, random, count, strength) {
    for (let i = 0; i < count; i++) {
        ctx.fillStyle = random() > 0.5 ? `rgba(255,255,255,${strength})` : `rgba(0,0,0,${strength})`;
        ctx.fillRect(Math.floor(random() * ctx.canvas.width), Math.floor(random() * ctx.canvas.height), 1 + Math.round(random()), 1);
    }
}

/** large soft stains that break up repetition; drawn wrapped so the texture tiles */
function blotches(ctx, random, count, strength, rgb = [0, 0, 0]) {
    const size = ctx.canvas.width;
    for (let i = 0; i < count; i++) {
        const x = random() * size;
        const y = random() * size;
        const r = 20 + random() * 80;
        const alpha = strength * random();
        for (const [dx, dy] of [[0, 0], [size, 0], [-size, 0], [0, size], [0, -size]]) {
            const gradient = ctx.createRadialGradient(x + dx, y + dy, 0, x + dx, y + dy, r);
            gradient.addColorStop(0, `rgba(${rgb.join(',')},${alpha})`);
            gradient.addColorStop(1, `rgba(${rgb.join(',')},0)`);
            ctx.fillStyle = gradient;
            ctx.fillRect(x + dx - r, y + dy - r, 2 * r, 2 * r);
        }
    }
}

/** mulberry32: the same pattern on every load */
function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function hash(text) {
    let h = 0;
    for (const char of text) {
        h = (Math.imul(31, h) + char.charCodeAt(0)) | 0;
    }

    return h;
}
