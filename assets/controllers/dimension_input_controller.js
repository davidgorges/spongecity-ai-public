import { Controller } from '@hotwired/stimulus';

/**
 * A dimension as a slider plus a text field that stay in sync.
 *
 * The slider carries the LiveComponent model (data-model="debounce(…)|values.x")
 * and works in base units (millimetres). Dragging updates the field at once,
 * without waiting for the server; the debounced model update then moves the
 * 3D view. The field shows the value in display units (metres, `scale` = 1000)
 * with the page language's decimal separator and accepts "5,25", "5.25",
 * "525 cm" or "5250 mm". Enter or leaving the field snaps the value to the
 * slider's step and bounds and hands it to the slider; ArrowUp/ArrowDown step
 * by one step, with Shift by ten.
 *
 *   <div {{ stimulus_controller('dimension-input', {scale: 1000, decimals: 2, unit: 'm'}) }}>
 *       <input type="text" inputmode="decimal" data-dimension-input-target="field"
 *              data-action="keydown->dimension-input#key change->dimension-input#commit focus->dimension-input#select">
 *       <input type="range" min="2000" max="7000" step="10" data-dimension-input-target="slider"
 *              data-action="input->dimension-input#slide" data-model="debounce(150)|values.width">
 *   </div>
 */
export default class extends Controller {
    static targets = ['field', 'slider'];
    static values = { scale: { type: Number, default: 1 }, decimals: { type: Number, default: 0 }, unit: String };

    static SUFFIX_FACTORS = { mm: 1, cm: 10, m: 1000 };
    static BIG_STEP = 10;

    #format;

    connect() {
        this.#format = new Intl.NumberFormat(document.documentElement.lang || undefined, {
            minimumFractionDigits: this.decimalsValue,
            maximumFractionDigits: this.decimalsValue,
            useGrouping: false,
        });
    }

    /** While dragging: show the value right away. */
    slide() {
        this.#show(this.sliderTarget.valueAsNumber);
    }

    select() {
        this.fieldTarget.select();
    }

    key(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.commit();
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            const steps = (event.shiftKey ? this.constructor.BIG_STEP : 1) * (event.key === 'ArrowUp' ? 1 : -1);
            const current = this.#parse(this.fieldTarget.value) ?? this.sliderTarget.valueAsNumber;
            this.#apply(current + steps * this.#step());
        }
    }

    /** Field → slider: snapped to step and bounds; unreadable input restores the current value. */
    commit() {
        const value = this.#parse(this.fieldTarget.value);
        this.#apply(value ?? this.sliderTarget.valueAsNumber);
    }

    #apply(value) {
        const slider = this.sliderTarget;
        const min = Number(slider.min);
        const max = Number(slider.max);
        const step = this.#step();
        const snapped = Math.min(max, Math.max(min, min + Math.round((value - min) / step) * step));
        this.#show(snapped);
        if (snapped !== slider.valueAsNumber) {
            slider.value = String(snapped);
            // the LiveComponent listens for input on the model element
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    #show(value) {
        const text = this.#format.format(value / this.scaleValue);
        this.fieldTarget.value = text;
        this.sliderTarget.setAttribute('aria-valuetext', `${text} ${this.unitValue}`.trim());
    }

    #step() {
        return Number(this.sliderTarget.step) || 1;
    }

    /** "5,25" / "5.25" (display units), "525 cm", "5250 mm" → base units; null if unreadable. */
    #parse(text) {
        const match = text.trim().toLowerCase().match(/^(-?\d+(?:[.,]\d*)?)\s*(mm|cm|m)?$/);
        if (!match) {
            return null;
        }
        if (match[2] && this.unitValue !== 'm') {
            return null; // length suffixes only make sense for lengths
        }
        const number = Number(match[1].replace(',', '.'));
        const factor = match[2] ? this.constructor.SUFFIX_FACTORS[match[2]] : this.scaleValue;

        return Number.isFinite(number) ? number * factor : null;
    }
}
