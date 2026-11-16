import { Controller } from '@hotwired/stimulus';
import { SceneRenderer } from '../configurator/scene_renderer.js';
import { setPhotoTextureUrls } from '../configurator/photo_textures.js';
import { TIER_STANDARD, TIER_ULTRA } from '../configurator/quality_tiers.js';
import { MOODS_IN_ORDER } from '../configurator/outdoor_environment.js';
import { webGlAvailable } from '../configurator/webgl_support.js';

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
    };

    static STORAGE_KEY = 'configurator-quality';
    static QUALITY_HIGH = 'high';
    static QUALITY_STANDARD = 'standard';
    static STATS_INTERVAL_MS = 500;
    static UNAVAILABLE_EVENT = 'configurator:view-unavailable';
    static NOT_READY_MESSAGE = 'The 3D view is not ready.';
    static UNAVAILABLE_MESSAGE = 'The 3D view is not available in this browser (WebGL is disabled). '
        + 'The settings, the price and the quote request work without it.';

    #renderer = null;
    #statsTimer = null;
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
        this.#renderer?.dispose();
        this.#renderer = null;
    }

    sceneValueChanged() {
        this.closePhoto();
        this.#renderer?.update(this.sceneValue);
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

    hover(event) {
        if (!this.#renderer || !this.hasTooltipTarget) {
            return;
        }
        // mouse only, and not while orbiting
        if (event.pointerType !== 'mouse' || event.buttons !== 0) {
            this.unhover();
            return;
        }
        const part = this.#renderer.pickAt(event.clientX, event.clientY);
        if (!part) {
            this.unhover();
            return;
        }
        const bounds = this.element.getBoundingClientRect();
        this.tooltipTarget.textContent = `${part.label} · ${part.sku}`;
        this.tooltipTarget.style.left = `${event.clientX - bounds.left + 14}px`;
        this.tooltipTarget.style.top = `${event.clientY - bounds.top + 14}px`;
        this.tooltipTarget.hidden = false;
    }

    unhover() {
        this.#renderer?.clearPick();
        if (this.hasTooltipTarget) {
            this.tooltipTarget.hidden = true;
        }
    }

    /** A stretch an editor is working on (event configurator:highlight, detail from/to/height). */
    highlight(event) {
        this.#renderer?.highlightStretch(event.detail);
    }

    unhighlight() {
        this.#renderer?.clearHighlight();
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
        this.decorTargets.forEach((button) => button.setAttribute('aria-pressed', this.#renderer.decorVisible ? 'true' : 'false'));
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
        this.conditionsTargets.forEach((button) => button.setAttribute('aria-pressed', visible ? 'true' : 'false'));
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
            button.setAttribute('aria-pressed', button.dataset.configuratorViewerMoodParam === mood ? 'true' : 'false');
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
        this.qualityTargets.forEach((button) => button.setAttribute('aria-pressed', high ? 'true' : 'false'));
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
