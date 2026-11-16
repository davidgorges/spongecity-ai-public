import { Controller } from '@hotwired/stimulus';

/**
 * Generic disclosure widget. Pairs a trigger button with a panel; toggles
 * the panel's `hidden` attribute and keeps `aria-expanded` in sync.
 *
 * Usage:
 *   <div data-controller="disclosure">
 *       <button data-disclosure-target="trigger"
 *               data-action="disclosure#toggle"
 *               aria-expanded="false">…</button>
 *       <div data-disclosure-target="panel" hidden>…</div>
 *   </div>
 *
 * Add `data-disclosure-open-value="true"` to render initially open.
 */
export default class extends Controller {
    static targets = ['trigger', 'panel'];
    static values = { open: { type: Boolean, default: false } };

    connect() {
        this.sync();
    }

    toggle() {
        this.openValue = !this.openValue;
    }

    open() {
        this.openValue = true;
    }

    close() {
        this.openValue = false;
    }

    openValueChanged() {
        this.sync();
    }

    sync() {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = !this.openValue;
        }
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', this.openValue ? 'true' : 'false');
        }
    }
}
