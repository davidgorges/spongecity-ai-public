import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'theme';
const SYSTEM = 'system';
const MODES = ['light', 'dark', SYSTEM];

/**
 * Cycles light → dark → system on <html>: data-theme is the applied scheme,
 * data-theme-mode the choice. "system" removes the saved choice, so the OS scheme
 * applies and is followed (see _theme_init.html.twig). `label` targets show the choice.
 * flip() is the two-state variant for icon-only buttons (landing page).
 */
export default class extends Controller {
    static targets = ['label'];

    // <html> outlives Turbo visits; the labels of each new page are filled in as they arrive.
    labelTargetConnected(label) {
        label.textContent = this.mode;
    }

    toggle() {
        this.apply(MODES[(MODES.indexOf(this.mode) + 1) % MODES.length]);
    }

    // Two-state switch for a plain sun/moon button: always shows the other scheme
    // on the first click, where toggle() may first step from "system" to the same look.
    flip() {
        this.apply(this.element.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    }

    apply(mode) {
        if (mode === SYSTEM) {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, mode);
        }

        const dark = mode === SYSTEM ? window.matchMedia('(prefers-color-scheme: dark)').matches : mode === 'dark';
        this.element.setAttribute('data-theme', dark ? 'dark' : 'light');
        this.element.setAttribute('data-theme-mode', mode);
        this.labelTargets.forEach((label) => (label.textContent = mode));
    }

    get mode() {
        return this.element.dataset.themeMode ?? SYSTEM;
    }
}
