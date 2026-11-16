/**
 * Watches frame times while the view animates and decides when to step the
 * rendering tier down (frames take too long) or up (frames are on time and
 * the GPU has headroom).
 *
 * Frame times come from requestAnimationFrame deltas, which are capped by the
 * display's refresh rate, so they show trouble but not headroom. Headroom
 * comes from GPU timer queries (EXT_disjoint_timer_query_webgl2) where the
 * browser offers trustworthy ones; otherwise the governor probes: after
 * STABLE_WINDOWS on-time windows it tries the next tier and steps back if
 * that one is too slow.
 *
 * Too slow means dropped frames, not a slow median: at 46 fps half the frames are
 * still on time, so the median looks perfect while the motion stutters. A window
 * with more than DROPPED_SHARE_SLOW of its frames late steps down; only one with
 * at most DROPPED_SHARE_ON_TIME of them late counts as on time.
 *
 * Hysteresis: a tier that was too slow is not tried again in this session.
 */
export class PerformanceGovernor {
    static WINDOW = 40;
    static SLOW_FACTOR = 1.5;
    static GPU_HEADROOM = 0.45;
    static COOLDOWN_MS = 2500;
    static STABLE_WINDOWS = 3;
    /** a frame taking this many refresh intervals or more was dropped (shown late) */
    static DROPPED_FACTOR = 1.5;
    static DROPPED_SHARE_SLOW = 0.1;
    static DROPPED_SHARE_ON_TIME = 0.05;
    static SWITCH_SETTLE_MS = 1000;
    static STALL_MS = 250;
    static STALLS_TO_STEP_DOWN = 3;

    #tier;
    #maxTier;
    #ceiling;
    #deltas = [];
    #gpuTimes = [];
    #refreshMs = 16.7;
    #lastChange = 0;
    #stableWindows = 0;
    #onChange;
    #pinned;
    #pausedUntil = 0;
    #stalls = 0;

    constructor(startTier, maxTier, onChange, pinned = false) {
        this.#pinned = pinned;
        this.#tier = Math.min(startTier, maxTier);
        this.#maxTier = maxTier;
        this.#ceiling = maxTier;
        this.#onChange = onChange;
    }

    get tier() {
        return this.#tier;
    }

    /**
     * Stops judging frame times for a while: one-off work (new scenery being built,
     * its shaders compiled) makes frames late without the tier being too costly.
     */
    pause(ms) {
        this.#pausedUntil = performance.now() + ms;
        this.#deltas = [];
        this.#gpuTimes = [];
    }

    /** The user's choice: never go above this tier. */
    setMaxTier(maxTier) {
        this.#maxTier = maxTier;
        this.#ceiling = maxTier;
        if (this.#tier > maxTier) {
            this.#change(maxTier);
        }
    }

    /** Raises the tier to the maximum again (e.g. the user asked for high quality). */
    reset(tier) {
        this.#ceiling = this.#maxTier;
        this.#change(Math.min(tier, this.#maxTier));
    }

    get refreshMs() {
        return this.#refreshMs;
    }

    /**
     * @param {number} deltaMs time since the previous frame of the same animation
     * @param {number|null} gpuMs GPU time of a frame, when measurable
     */
    sample(deltaMs, gpuMs) {
        if (deltaMs <= 0 || performance.now() < this.#pausedUntil) {
            return; // one-off work going on
        }
        if (deltaMs > PerformanceGovernor.STALL_MS) {
            // one such frame is a pause (first frame after idling, a background tab); several in a row
            // mean the device cannot draw this tier at all: step down without waiting for a full window
            this.#stalls += 1;
            if (this.#stalls >= PerformanceGovernor.STALLS_TO_STEP_DOWN && !this.#pinned && this.#tier > 0) {
                this.#stalls = 0;
                this.#ceiling = this.#tier - 1;
                this.#change(this.#tier - 1);
            }

            return;
        }
        this.#stalls = 0;
        // the shortest frame we have seen approximates the display's refresh interval
        this.#refreshMs = Math.max(6.5, Math.min(this.#refreshMs, deltaMs * 1.02));
        this.#deltas.push(deltaMs);
        if (gpuMs !== null) {
            this.#gpuTimes.push(gpuMs);
        }
        if (this.#deltas.length >= PerformanceGovernor.WINDOW) {
            if (!this.#pinned) {
                this.#evaluate();
            }
            this.#deltas = [];
            this.#gpuTimes = [];
        }
    }

    #evaluate() {
        const now = performance.now();
        if (now - this.#lastChange < PerformanceGovernor.COOLDOWN_MS) {
            return;
        }
        const frame = median(this.#deltas);
        const late = this.#refreshMs * PerformanceGovernor.DROPPED_FACTOR;
        const dropped = this.#deltas.filter((delta) => delta >= late).length / this.#deltas.length;
        const slow = frame > this.#refreshMs * PerformanceGovernor.SLOW_FACTOR || dropped > PerformanceGovernor.DROPPED_SHARE_SLOW;
        if (slow && this.#tier > 0) {
            this.#ceiling = this.#tier - 1;
            this.#stableWindows = 0;
            this.#change(this.#tier - 1);

            return;
        }
        const onTime = frame <= this.#refreshMs * 1.15 && dropped <= PerformanceGovernor.DROPPED_SHARE_ON_TIME;
        this.#stableWindows = onTime ? this.#stableWindows + 1 : 0;
        if (!onTime || this.#tier >= this.#ceiling) {
            return;
        }
        const gpu = this.#gpuTimes.length > 5 ? median(this.#gpuTimes) : null;
        const measuredHeadroom = gpu !== null && gpu < this.#refreshMs * PerformanceGovernor.GPU_HEADROOM;
        if (measuredHeadroom || this.#stableWindows >= PerformanceGovernor.STABLE_WINDOWS) {
            this.#stableWindows = 0;
            this.#change(this.#tier + 1);
        }
    }

    #change(tier) {
        if (tier === this.#tier) {
            return;
        }
        this.#tier = tier;
        this.#lastChange = performance.now();
        this.#onChange(tier);
        // the switch rebuilds and recompiles: its late frames say nothing about the new tier
        this.pause(PerformanceGovernor.SWITCH_SETTLE_MS);
    }
}

/**
 * GPU duration of whole frames via EXT_disjoint_timer_query_webgl2. Queries
 * resolve a few frames later; `latest` holds the most recent result.
 */
export class GpuTimer {
    #gl;
    #extension;
    #pending = [];
    #active = null;
    latest = null;

    constructor(gl) {
        this.#gl = gl;
        this.#extension = gl.getExtension('EXT_disjoint_timer_query_webgl2');
    }

    get available() {
        return Boolean(this.#extension);
    }

    begin() {
        if (!this.#extension || this.#active) {
            return;
        }
        this.#active = this.#gl.createQuery();
        this.#gl.beginQuery(this.#extension.TIME_ELAPSED_EXT, this.#active);
    }

    end() {
        if (!this.#active) {
            return;
        }
        this.#gl.endQuery(this.#extension.TIME_ELAPSED_EXT);
        this.#pending.push(this.#active);
        this.#active = null;
        this.#collect();
    }

    #collect() {
        const gl = this.#gl;
        const disjoint = gl.getParameter(this.#extension.GPU_DISJOINT_EXT);
        while (this.#pending.length > 0) {
            const query = this.#pending[0];
            if (!gl.getQueryParameter(query, gl.QUERY_RESULT_AVAILABLE)) {
                break;
            }
            if (!disjoint) {
                this.latest = gl.getQueryParameter(query, gl.QUERY_RESULT) / 1e6;
            }
            gl.deleteQuery(query);
            this.#pending.shift();
        }
    }
}

function median(values) {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
}
