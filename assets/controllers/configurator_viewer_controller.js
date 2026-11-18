import { Controller } from '@hotwired/stimulus';
import { SceneRenderer } from '../configurator/scene_renderer.js';
import { setPhotoTextureUrls } from '../configurator/photo_textures.js';
import { TIER_STANDARD, TIER_ULTRA } from '../configurator/quality_tiers.js';
import { MOODS_IN_ORDER } from '../configurator/outdoor_environment.js';
import { webGlAvailable } from '../configurator/webgl_support.js';
import { press } from '../configurator/press.js';

const MILLIMETRES_PER_METRE = 1000;

/**
 * 3D view of a configuration. The server renders the scene JSON into the
 * `scene` value; when a LiveComponent re-render changes it, the view updates
 * without losing the camera position. The canvas lives in the `canvas` target,
 * which must carry data-live-ignore so the re-render leaves it alone.
 *
 * Quality adapts to the device (quality_tiers.js, performance_governor.js).
 * The `quality` target caps it: pressed = up to "ultra", released = up to
 * "standard"; the choice is remembered in this browser. With the `debug`
 * value (?debug=1) the `stats` target shows frame rate, GPU time and tier;
 * the `tier` value (?tier=0…3, debugging) pins a tier.
 *
 * `mood` targets are buttons for the light moods (day, sunset, evening with
 * glowing lamps), each with data-configurator-viewer-mood-param and the action
 * configurator-viewer#setMood; the current one is aria-pressed. `header`
 * targets get data-mood for their styling.
 *
 * The `decor` target (action configurator-viewer#toggleDecor, aria-pressed =
 * shown) fades the staging (furniture, fire pit) in and out, so the customer
 * can look at the bare product. Every visit starts furnished.
 *
 * With a mouse, hovering a part outlines it and shows its name and article
 * number in the `tooltip` target.
 *
 * The `photo` target starts photo mode (configurator-viewer#takePhoto): the
 * current view is path traced into a near-photographic still under the
 * mood's sky (`skies` value). `photoPanel` (data-live-ignore) shows progress
 * in `photoStatus`/`photoBar` and offers the `photoDownload` button once done;
 * configurator-viewer#closePhoto, or any interaction with the view, ends it.
 *
 * configurator-viewer#highlight marks a stretch of the product, and
 * configurator-viewer#unhighlight removes the mark. Any parameter editor can
 * ask for it: dispatch `configurator:highlight` on window (detail
 * {from, to, height}: plan x/z and height in metres) and
 * `configurator:unhighlight`; the configurator template wires both.
 *
 * AI agents use the view through the WebMCP tools (configurator_tools_controller.js):
 * configurator:view@window → agentView (camera, mood, furniture),
 * configurator:photo@window → agentPhoto; both answer through detail.respond/fail.
 *
 * Without WebGL (GPU disabled, e.g. some headless cloud browsers) there is no
 * 3D view: the `unavailable` target (data-live-ignore, hidden) is shown, the
 * canvas and the `controls` targets (view buttons, key hints) are hidden, and
 * agent requests fail with UNAVAILABLE_MESSAGE; configurator:view-unavailable is
 * dispatched on window (the AI photo offer hides itself). Settings, price and the quote
 * request do not need the view.
 *
 * While the canvas target has focus, W/A/S/D walk and Q/E turn the camera
 * (assets/configurator/keyboard_navigation.js).
 *
 *   <div {{ stimulus_controller('configurator-viewer', { scene: scene, debug: false }) }}>
 *       <div data-configurator-viewer-target="canvas" data-live-ignore tabindex="0"
 *            data-action="keydown->configurator-viewer#keydown keyup->configurator-viewer#keyup blur->configurator-viewer#releaseKeys pointerdown->configurator-viewer#focus"></div>
 *       <button data-action="configurator-viewer#resetView">…</button>
 *       <button data-configurator-viewer-target="quality" data-action="configurator-viewer#toggleQuality" aria-pressed="false">…</button>
 *       <pre data-configurator-viewer-target="stats" data-live-ignore hidden></pre>
 *   </div>
 */
export default class extends Controller {
    static targets = ['canvas', 'unavailable', 'controls', 'quality', 'mood', 'decor', 'header', 'stats', 'tooltip', 'photo', 'photoPanel', 'photoStatus', 'photoBar', 'photoDownload', 'conditions', 'legend'];
    static values = {
        scene: Object,
        debug: Boolean,
        tier: { type: Number, default: -1 },
        /** mood → URL of its sky (HDRI) for photo mode */
        skies: Object,
        /** texture name → URLs of its photographed maps (ultra tier, photo mode) */
        textures: Object,
        /** model libraries of the surroundings (garden_trees: ultra tier) */
        environmentModels: Object,
        /** file name of a downloaded photo, without extension */
        photoName: { type: String, default: 'configuration' },
        /** status texts with %percent%: { running, done } */
        photoTexts: Object,
        /** JSON of the real buildings and streets around a site (aerial views): fetched once, drawn as context */
        surroundingsUrl: String,
        /** JSON of what official data says about the site's ground (infiltration potential): an overlay the `conditions` button toggles */
        conditionsUrl: String,
        /** JSON of the site's ground (height grids): loaded first, so the city is built on it once */
        terrainUrl: String,
        /** badges over the objects the agent explained: {id, x, z, lift (plan millimetres), by, title} */
        markers: { type: Array, default: [] },
    };

    static STORAGE_KEY = 'configurator-quality';
    static QUALITY_HIGH = 'high';
    static QUALITY_STANDARD = 'standard';
    static STATS_INTERVAL_MS = 500;
    static UNAVAILABLE_EVENT = 'configurator:view-unavailable';
    /** a right-click on the view, and a click, with what lies under it */
    static CONTEXT_EVENT = 'configurator:context';
    static SELECT_EVENT = 'configurator:select';
    /** what the mouse is over (detail as above, null: nothing); the context menu names the object */
    static HOVER_EVENT = 'configurator:hover';
    /** a finger held this long (milliseconds) without moving opens the context menu */
    static LONG_PRESS_MS = 550;
    static LONG_PRESS_BUZZ_MS = 10;
    static POINTER_MOUSE = 'mouse';
    static POINTER_TOUCH = 'touch';
    /** the hover tooltip sits this far (CSS pixels) from the pointer */
    static TOOLTIP_OFFSET = 14;
    /** a press that comes up within this many CSS pixels and milliseconds is a click, not an orbit */
    static CLICK_SLOP = 5;
    static CLICK_MS = 600;
    static NOT_READY_MESSAGE = 'The 3D view is not ready.';
    static UNAVAILABLE_MESSAGE = 'The 3D view is not available in this browser (WebGL is disabled). '
        + 'The settings, the price and the quote request work without it.';

    #renderer = null;
    #statsTimer = null;
    /** a menu is open over the view: no hover tooltip meanwhile */
    #hoverHeld = false;
    /** where and when the primary button went down on the view */
    #press = null;
    /** a finger held on the view: its timer; fired once the menu opened */
    #longPress = null;
    /** the mouse's last position on the view, looked up once per frame */
    #hoverAt = null;
    #hoverFrame = 0;
    /** the object the hover mark shows (its id), so a move over the same one changes nothing */
    #hovered = null;
    /** the ids of the objects pointed at and selected: their badges are shown in full */
    #hoveredId = null;
    #selectedId = null;
    #unavailable = false;

    connect() {
        setPhotoTextureUrls(this.texturesValue);
        const high = this.#storedQuality() !== this.constructor.QUALITY_STANDARD;
        const touch = window.matchMedia('(pointer: coarse)').matches;
        const pinned = this.tierValue >= 0 && this.tierValue <= TIER_ULTRA;
        if (!webGlAvailable()) {
            this.#showUnavailable();
            return;
        }
        try {
            this.#renderer = new SceneRenderer(this.canvasTarget, {
                // desktops start at the best tier allowed: every later switch rebuilds and recompiles (a ~0.1 s
                // hitch), so a capable machine should not climb into it; a weaker one steps down once
                startTier: pinned ? this.tierValue : (touch ? TIER_STANDARD : (high ? TIER_ULTRA : TIER_STANDARD)),
                maxTier: high ? TIER_ULTRA : TIER_STANDARD,
                pinned,
                environmentModels: this.environmentModelsValue,
                setting: this.sceneValue.setting,
            });
        } catch (error) {
            // the probe passed, but the renderer's own context failed (blocklisted driver, context limit)
            console.warn('3D view unavailable', error);
            this.#showUnavailable();
            return;
        }
        this.#renderer.update(this.sceneValue);
        this.#applyMarkers();
        this.#loadScenery();
        this.#syncQualityButtons(high);
        this.#syncMoodButtons();
        if (this.debugValue && this.hasStatsTarget) {
            this.statsTarget.hidden = false;
            window.configuratorScene = this.#renderer.debugScene; // inspect from the console
            window.configuratorView = this.#renderer.debugView;
            // named camera views and readiness for scripted renders (bin/render-loop)
            window.configuratorShowView = (options) => this.#renderer.showView(options);
            window.configuratorStats = () => this.#renderer.stats();
            // the infiltration overlay on or off (bin/render-loop --eval)
            window.configuratorShowConditions = (visible = true) => this.#showConditions(visible);
            // the inputs of an AI image edit: the view and the product's mask (bin/console app:configurator:ai-trial)
            window.configuratorAiInputs = async () => ({
                view: await this.#renderer.viewBlob(),
                mask: await this.#renderer.productMaskBlob(),
            });
            this.#statsTimer = setInterval(() => this.#showStats(), this.constructor.STATS_INTERVAL_MS);
        }
    }

    disconnect() {
        clearInterval(this.#statsTimer);
        cancelAnimationFrame(this.#hoverFrame);
        this.#cancelLongPress();
        this.#renderer?.dispose();
        this.#renderer = null;
    }

    sceneValueChanged() {
        this.closePhoto();
        this.#renderer?.update(this.sceneValue);
    }

    /** the agent placed, or a person swapped, an object it explained */
    markersValueChanged() {
        this.#applyMarkers();
    }

    #applyMarkers() {
        this.#renderer?.setMarkers(this.markersValue.map((marker) => ({
            ...marker,
            x: marker.x / MILLIMETRES_PER_METRE,
            z: marker.z / MILLIMETRES_PER_METRE,
            lift: marker.lift / MILLIMETRES_PER_METRE,
        })));
    }

    /**
     * An alternative the agent had for the selected object (rationale:preview, detail {points}:
     * its outline in plan millimetres, or null): shown in amber next to it.
     */
    previewAlternative({ detail: { points } }) {
        this.#renderer?.previewAlternative(points ? { points: points.map(([x, z]) => [x / MILLIMETRES_PER_METRE, z / MILLIMETRES_PER_METRE]) } : null);
    }

    async takePhoto() {
        await this.#photo();
    }

    /**
     * An agent asks for a view (configurator:view@window, from configurator-tools):
     * detail {view, focus, mood, furniture, respond(result), fail(error)}.
     */
    async agentView({ detail }) {
        if (!this.#renderer) {
            detail.fail(this.#missingViewError());
            return;
        }
        this.closePhoto();
        if (detail.mood && MOODS_IN_ORDER.includes(detail.mood)) {
            this.#renderer.setMood(detail.mood);
            this.#syncMoodButtons();
        }
        if (typeof detail.furniture === 'boolean' && detail.furniture !== this.#renderer.decorVisible) {
            this.toggleDecor();
        }
        const result = await this.#renderer.showView({ view: detail.view, focus: detail.focus || null, surroundings: detail.surroundings === true });
        detail.respond({ ...result, mood: this.#renderer.mood, furniture: this.#renderer.decorVisible });
    }

    /**
     * The view as it is on screen, for an AI photo (configurator:capture@window, from
     * configurator-ai-photo): detail {respond(blob), fail(error)}.
     */
    async capture({ detail }) {
        if (!this.#renderer) {
            detail.fail(this.#missingViewError());
            return;
        }
        this.closePhoto();
        const blob = await this.#renderer.viewBlob();
        if (blob) {
            detail.respond(blob);
        } else {
            detail.fail(new Error('The view could not be captured.'));
        }
    }

    /** An agent asks for a photo (configurator:photo@window): answers once it can be downloaded. */
    async agentPhoto({ detail }) {
        if (!this.#renderer) {
            detail.fail(this.#missingViewError());
            return;
        }
        const done = await this.#photo();
        if (done) {
            detail.respond({ done: true, mood: this.#renderer.mood });
        } else {
            detail.fail(new Error('The photo could not be taken.'));
        }
    }

    /** @returns {Promise<boolean>} whether the photo is ready to download */
    async #photo() {
        if (!this.#renderer || !this.hasPhotoPanelTarget) {
            return false;
        }
        const skyUrl = this.skiesValue[this.#renderer.mood];
        if (!skyUrl) {
            return false;
        }
        this.#showPhotoProgress(0, false);
        this.photoPanelTarget.hidden = false;
        try {
            await new Promise((resolve, reject) => {
                this.#renderer.startPhoto({
                    skyUrl,
                    onProgress: (fraction) => this.#showPhotoProgress(fraction, false),
                    onDone: () => {
                        this.#showPhotoProgress(1, true);
                        this.photoDownloadTarget.focus();
                        resolve();
                    },
                }).catch(reject);
            });

            return true;
        } catch (error) {
            console.warn('Photo mode failed', error);
            this.closePhoto();

            return false;
        }
    }

    closePhoto() {
        if (!this.#renderer || !this.hasPhotoPanelTarget || this.photoPanelTarget.hidden) {
            return;
        }
        this.#renderer.stopPhoto();
        this.photoPanelTarget.hidden = true;
    }

    async downloadPhoto() {
        const blob = await this.#renderer?.photoBlob();
        if (!blob) {
            return;
        }
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${this.photoNameValue}.png`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    #showPhotoProgress(fraction, done) {
        const percent = Math.round(fraction * 100);
        const text = (done ? this.photoTextsValue.done : this.photoTextsValue.running) ?? '';
        this.photoStatusTarget.textContent = text.replace('%percent%', String(percent));
        this.photoBarTarget.style.width = `${percent}%`;
        this.photoBarTarget.parentElement.setAttribute('aria-valuenow', String(percent));
        this.photoDownloadTarget.hidden = !done;
    }

    qualityTargetConnected() {
        this.#syncQualityButtons(this.#storedQuality() !== this.constructor.QUALITY_STANDARD);
    }

    keydown(event) {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        this.closePhoto();
        if (this.#renderer?.keyDown(event.code)) {
            event.preventDefault();
        }
    }

    keyup(event) {
        this.#renderer?.keyUp(event.code);
    }

    releaseKeys() {
        this.#renderer?.releaseKeys();
    }

    /** OrbitControls prevent the default of pointerdown, so the click alone would not focus the view. */
    focus() {
        this.closePhoto();
        this.canvasTarget.focus({ preventScroll: true });
    }

    /**
     * The mouse moves over the view: once per frame, what lies under it is announced as
     * configurator:hover (detail as configurator:context); the context menu finds the object and
     * answers with plan-context-menu:hovered (showHovered). A finger moving cancels a long press.
     */
    hover(event) {
        if (event.pointerType === this.constructor.POINTER_TOUCH) {
            this.#moveLongPress(event);

            return;
        }
        if (!this.#renderer || this.#hoverHeld) {
            return;
        }
        // the mouse only, and not while orbiting
        if (event.pointerType !== this.constructor.POINTER_MOUSE || event.buttons !== 0) {
            this.unhover();
            return;
        }
        this.#hoverAt = { x: event.clientX, y: event.clientY };
        this.#hoverFrame ||= requestAnimationFrame(() => {
            this.#hoverFrame = 0;
            if (this.#hoverAt && this.#renderer && !this.#hoverHeld) {
                this.#announcePoint(this.constructor.HOVER_EVENT, this.#hoverAt.x, this.#hoverAt.y);
            }
        });
    }

    unhover() {
        cancelAnimationFrame(this.#hoverFrame);
        this.#hoverFrame = 0;
        this.#hoverAt = null;
        this.#showHover(null);
    }

    /**
     * The object under the mouse (plan-context-menu:hovered, detail {object: a PlanObjects row or
     * null, selected: whether it is the one already marked, marker: a badge's id or null}): marked
     * faintly unless selected, named in the tooltip.
     */
    showHovered({ detail: { object, selected, marker, clientX, clientY } }) {
        if (!this.#hoverAt || this.#hoverHeld) {
            return;
        }
        if (!object) {
            this.#showHover(null);

            return;
        }
        const key = `${object.id}:${selected}`;
        if (key !== this.#hovered) {
            this.#hovered = key;
            this.#renderer?.hoverObject(selected ? null : this.#metres(object));
            this.#hoveredId = object.id;
            this.#emphasize();
        }
        this.canvasTarget.style.cursor = 'pointer';
        if (!this.hasTooltipTarget) {
            return;
        }
        const badge = marker ? this.markersValue.find((candidate) => candidate.id === marker) : null;
        const bounds = this.element.getBoundingClientRect();
        this.tooltipTarget.textContent = badge?.title ?? object.name;
        this.tooltipTarget.style.left = `${clientX - bounds.left + this.constructor.TOOLTIP_OFFSET}px`;
        this.tooltipTarget.style.top = `${clientY - bounds.top + this.constructor.TOOLTIP_OFFSET}px`;
        this.tooltipTarget.hidden = false;
    }

    #showHover(object) {
        if (object === null && this.#hovered !== null) {
            this.#hovered = null;
            this.#hoveredId = null;
            this.#renderer?.hoverObject(null);
            this.#emphasize();
        }
        this.canvasTarget.style.cursor = '';
        if (this.hasTooltipTarget) {
            this.tooltipTarget.hidden = true;
        }
    }

    /**
     * Right-click, or the context menu key, on the view: what lies under the pointer (the middle
     * of the view from the keyboard) is announced as configurator:context on window, detail
     * {clientX, clientY, x, z (plan millimetres), above (metres over the ground), model (where the
     * model that was hit stands, plan millimetres, or null)}; a menu listens.
     */
    contextMenu(event) {
        if (!this.#renderer) {
            return;
        }
        event.preventDefault();
        // a held finger: the browser's own long press (Android) and ours open one menu between them
        if (this.#longPress) {
            const fired = this.#longPress.fired;
            this.#longPress.fired = true;
            clearTimeout(this.#longPress.timer);
            this.#press = null;
            if (fired) {
                return;
            }
        }
        const rect = this.canvasTarget.getBoundingClientRect();
        // the key gives no pointer position (0, 0, or somewhere off the view): the middle of the view
        const inside = event.clientX > rect.left && event.clientX < rect.right && event.clientY > rect.top && event.clientY < rect.bottom;
        const fromKeyboard = !inside || (event.clientX === 0 && event.clientY === 0);
        const clientX = fromKeyboard ? rect.left + rect.width / 2 : event.clientX;
        const clientY = fromKeyboard ? rect.top + rect.height / 2 : event.clientY;
        this.unhover();
        this.#announcePoint(this.constructor.CONTEXT_EVENT, clientX, clientY);
    }

    /** a press on the view: a click if it comes up where it went down (an orbit drag moves) */
    press(event) {
        this.#press = event.isPrimary && event.button === 0 ? { x: event.clientX, y: event.clientY, at: event.timeStamp } : null;
        this.#cancelLongPress();
        if (this.#press && event.pointerType === this.constructor.POINTER_TOUCH) {
            const { x, y } = this.#press;
            this.#longPress = {
                x, y, fired: false,
                timer: setTimeout(() => this.#fireLongPress(), this.constructor.LONG_PRESS_MS),
            };
        }
    }

    /** a second finger (a pinch), or the browser taking over the gesture: no long press */
    cancelPress() {
        this.#press = null;
        this.#cancelLongPress();
    }

    #moveLongPress(event) {
        if (this.#longPress && !this.#longPress.fired
            && (!event.isPrimary || Math.hypot(event.clientX - this.#longPress.x, event.clientY - this.#longPress.y) > this.constructor.CLICK_SLOP)) {
            this.#cancelLongPress();
        }
    }

    /** a finger held still on the view: its context menu, as a right-click there would open it */
    #fireLongPress() {
        const press = this.#longPress;
        if (!press || !this.#renderer) {
            return;
        }
        press.fired = true;
        // lifting the finger is no click
        this.#press = null;
        navigator.vibrate?.(this.constructor.LONG_PRESS_BUZZ_MS);
        this.#announcePoint(this.constructor.CONTEXT_EVENT, press.x, press.y);
    }

    #cancelLongPress() {
        clearTimeout(this.#longPress?.timer);
        this.#longPress = null;
    }

    /**
     * A click on the view (not a drag that orbits the camera) announces what lies under it as
     * configurator:select on window, with the same detail as configurator:context; the workspace
     * selects that object (an empty spot: none).
     */
    release(event) {
        const press = this.#press;
        this.#press = null;
        this.#cancelLongPress();
        if (!press || !this.#renderer || event.button !== 0) {
            return;
        }
        const moved = Math.hypot(event.clientX - press.x, event.clientY - press.y);
        if (moved > this.constructor.CLICK_SLOP || event.timeStamp - press.at > this.constructor.CLICK_MS) {
            return;
        }
        this.#announcePoint(this.constructor.SELECT_EVENT, event.clientX, event.clientY);
    }

    #announcePoint(name, clientX, clientY) {
        const point = this.#renderer.pointAt(clientX, clientY);
        window.dispatchEvent(new CustomEvent(name, {
            detail: point ? {
                clientX,
                clientY,
                x: Math.round(point.x * MILLIMETRES_PER_METRE),
                z: Math.round(point.z * MILLIMETRES_PER_METRE),
                above: point.above,
                // a model that was hit (a tree), by where it stands
                model: point.model ? { x: Math.round(point.model.x * MILLIMETRES_PER_METRE), z: Math.round(point.model.z * MILLIMETRES_PER_METRE) } : null,
                // a badge over an object the agent explained: that object
                marker: point.marker,
            } : null,
        }));
    }

    /** a menu opened over the view (plan-context-menu:opened): the tooltip and outline give way */
    holdHover() {
        this.#hoverHeld = true;
        this.unhover();
    }

    releaseHover() {
        this.#hoverHeld = false;
    }

    /**
     * Marks an object of the plan (plan-context-menu:highlight, detail {object}: its outline in
     * plan millimetres or a tree's position and crown, base and top over the ground; null: none).
     */
    highlightObject({ detail: { object } }) {
        this.#selectedId = object?.id ?? null;
        this.#emphasize();
        if (!object) {
            this.#renderer?.highlightObject(null);

            return;
        }
        this.#renderer?.highlightObject(this.#metres(object));
    }

    #emphasize() {
        this.#renderer?.emphasizeMarkers([this.#hoveredId, this.#selectedId]);
    }

    /** a PlanObjects row (millimetres) as the renderer's shape (metres) */
    #metres(object) {
        const metres = (value) => (value === undefined ? undefined : value / MILLIMETRES_PER_METRE);

        return {
            points: object.points?.map(([x, z]) => [metres(x), metres(z)]),
            x: metres(object.x),
            z: metres(object.z),
            r: metres(object.r),
            base: metres(object.base),
            top: metres(object.top),
        };
    }

    /** A stretch an editor is working on (event configurator:highlight, detail from/to/height). */
    highlight(event) {
        this.#renderer?.highlightStretch(event.detail);
    }

    unhighlight() {
        this.#renderer?.clearHighlight();
    }

    /**
     * The plot as the plot map is editing it (event plot-map:preview, detail points [x, z] in
     * millimetres or null for the saved one, editing: whether the map is in use): shown at once.
     */
    previewPlot({ detail: { points, editing } }) {
        this.#renderer?.previewPlot(points ? points.map(([x, z]) => [x / MILLIMETRES_PER_METRE, z / MILLIMETRES_PER_METRE]) : null, { editing });
    }

    resetView() {
        this.#renderer?.fitView({ animate: true });
    }

    toggleQuality() {
        this.closePhoto();
        if (!this.#renderer) {
            return;
        }
        const high = this.#storedQuality() === this.constructor.QUALITY_STANDARD;
        this.#renderer.setMaxTier(high ? TIER_ULTRA : TIER_STANDARD);
        try {
            localStorage.setItem(this.constructor.STORAGE_KEY, high ? this.constructor.QUALITY_HIGH : this.constructor.QUALITY_STANDARD);
        } catch {
            // storage blocked (private mode, third-party iframe): the choice lasts for this page only
        }
        this.#syncQualityButtons(high);
    }

    setMood({ params: { mood } }) {
        this.closePhoto();
        if (!this.#renderer || !MOODS_IN_ORDER.includes(mood)) {
            return;
        }
        this.#renderer.setMood(mood);
        this.#syncMoodButtons();
    }

    toggleDecor() {
        this.closePhoto();
        if (!this.#renderer) {
            return;
        }
        this.#renderer.setDecorVisible(!this.#renderer.decorVisible);
        this.decorTargets.forEach((button) => press(button, this.#renderer.decorVisible));
    }

    toggleConditions() {
        this.closePhoto();
        this.#showConditions(!this.#renderer?.conditionsVisible);
    }

    #showConditions(visible) {
        if (!this.#renderer) {
            return;
        }
        this.#renderer.setConditionsVisible(visible);
        this.conditionsTargets.forEach((button) => press(button, visible));
        this.legendTargets.forEach((legend) => { legend.hidden = !visible; });
    }

    /** The ground first (everything else is laid on it), then the city and the overlay. */
    async #loadScenery() {
        await this.#loadTerrain();
        this.#loadSurroundings();
        this.#loadConditions();
    }

    /** The real ground of the site; without it (outside Hamburg, older plans) the view stays flat. */
    async #loadTerrain() {
        if (!this.terrainUrlValue) {
            return;
        }
        try {
            const response = await fetch(this.terrainUrlValue, { headers: { Accept: 'application/json' } });
            if (response.status === 200) {
                await this.#renderer?.setTerrain(await response.json());
            }
        } catch (error) {
            console.warn('Terrain unavailable', error);
        }
    }

    /** The infiltration overlay: its button appears only when there is something to show (Hamburg sites). */
    async #loadConditions() {
        if (!this.conditionsUrlValue) {
            return;
        }
        try {
            const response = await fetch(this.conditionsUrlValue, { headers: { Accept: 'application/json' } });
            if (response.ok && this.#renderer?.setConditions(await response.json())) {
                this.conditionsTargets.forEach((button) => { button.hidden = false; });
            }
        } catch (error) {
            console.warn('Site conditions unavailable', error);
        }
    }

    /** The real city around the site; without it (not imported, offline) the view draws a generic one. */
    async #loadSurroundings() {
        if (!this.surroundingsUrlValue) {
            return;
        }
        try {
            const response = await fetch(this.surroundingsUrlValue, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                this.#renderer?.setSurroundings(await response.json());
            }
        } catch (error) {
            console.warn('Surroundings unavailable', error);
        }
    }

    /** No WebGL: a notice instead of the view, and no buttons that would do nothing. */
    #showUnavailable() {
        this.#unavailable = true;
        this.canvasTarget.hidden = true;
        this.controlsTargets.forEach((element) => { element.hidden = true; });
        if (this.hasUnavailableTarget) {
            this.unavailableTarget.hidden = false;
        }
        window.dispatchEvent(new CustomEvent(this.constructor.UNAVAILABLE_EVENT));
    }

    #missingViewError() {
        return new Error(this.#unavailable ? this.constructor.UNAVAILABLE_MESSAGE : this.constructor.NOT_READY_MESSAGE);
    }

    #syncMoodButtons() {
        const mood = this.#renderer.mood;
        this.moodTargets.forEach((button) => {
            press(button, button.dataset.configuratorViewerMoodParam === mood);
        });
        // the header (data-live-ignore) switches to light text on a dark backdrop in the evening
        this.headerTargets.forEach((header) => { header.dataset.mood = mood; });
    }

    #storedQuality() {
        try {
            return localStorage.getItem(this.constructor.STORAGE_KEY);
        } catch {
            return null;
        }
    }

    #syncQualityButtons(high) {
        this.qualityTargets.forEach((button) => press(button, high));
    }

    #showStats() {
        const stats = this.#renderer?.stats();
        if (!stats) {
            return;
        }
        this.statsTarget.textContent = [
            `${stats.fps} fps  ${stats.frameMs} ms  (display ${stats.refreshHz} Hz)`,
            `GPU ${stats.gpuMs} ms`,
            `tier ${stats.tier}  ×${stats.pixelRatio}`,
            `${stats.drawCalls} draws  ${(stats.triangles / 1000).toFixed(0)}k tris`,
            `${stats.geometries} geo  ${stats.textures} tex`,
        ].join('\n');
    }
}
