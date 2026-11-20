import { Controller } from '@hotwired/stimulus';

/**
 * Copies the value of its source (an input) to the clipboard, and says so for a moment on the
 * button (its `label` target).
 *
 *   <div data-controller="clipboard" data-clipboard-done-value="Copied">
 *       <input data-clipboard-target="source" value="…" readonly data-action="focus->clipboard#select">
 *       <button data-action="clipboard#copy"><span data-clipboard-target="label">Copy</span></button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['source', 'label'];
    static values = { done: String, resetMs: { type: Number, default: 1500 } };

    #timer = null;

    /** the whole value, ready to copy by hand */
    select() {
        this.sourceTarget.select();
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.value);
        } catch {
            // no clipboard (an insecure page, or refused): leave it selected to copy by hand
            this.sourceTarget.select();

            return;
        }
        if (!this.hasLabelTarget) {
            return;
        }
        const label = this.labelTarget.dataset.label ??= this.labelTarget.textContent;
        this.labelTarget.textContent = this.doneValue;
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => (this.labelTarget.textContent = label), this.resetMsValue);
    }

    disconnect() {
        clearTimeout(this.#timer);
    }
}
