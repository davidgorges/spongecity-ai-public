/**
 * Presses a button, or releases it, for a controller other than its own: aria-pressed, and for
 * the UX Toolkit's Toggle (toggle_controller.js) its pressed value too, which the Toggle writes
 * back to aria-pressed when it connects and would otherwise reset to "not pressed".
 *
 * @param {HTMLElement} button
 * @param {boolean} pressed
 */
export function press(button, pressed) {
    button.setAttribute('aria-pressed', String(pressed));
    button.setAttribute('data-toggle-pressed-value', String(pressed));
}
