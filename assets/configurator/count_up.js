/**
 * The numbers a count-up shows on its way from one amount to the next
 * (configurator_price_controller.js): fast at first, settling on the target.
 */

/** 0…1 → 0…1, decelerating */
export function easeOutCubic(progress) {
    return 1 - (1 - progress) ** 3;
}

/**
 * The amount (in cents, whole) shown `elapsed` ms into a count-up of
 * `duration` ms from `from` to `to`; exactly `to` once the time is up.
 */
export function amountAt(from, to, elapsed, duration) {
    if (duration <= 0 || elapsed >= duration) {
        return to;
    }

    return Math.round(from + (to - from) * easeOutCubic(Math.max(0, elapsed) / duration));
}

/** A frame longer than this is a stall (the 3D view compiling shaders after a change), not the device's pace. */
export const STALL_MS = 100;

/**
 * The count-up's time after one more frame of `frameMs`: a stalled frame
 * pauses it, so the count-up is seen once the page responds again instead of
 * having run out unseen.
 */
export function advance(elapsed, frameMs) {
    return frameMs > STALL_MS ? elapsed : elapsed + Math.max(0, frameMs);
}
