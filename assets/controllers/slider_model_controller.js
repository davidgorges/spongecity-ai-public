import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const LIVE_ROOT = '[data-controller~="live"]';

/**
 * Binds the Toolkit's Slider to a writable LiveProp of the live component around it. The slider
 * announces a new value with an input event on its own element, which has no value a data-model
 * could read (and data-model on a component tag means binding to a child component): this sets
 * the prop once the thumb has rested for a moment, which re-renders the component.
 *
 *   <twig:Slider data-controller="slider slider-model" data-slider-model-name-value="storm" data-action="input->slider-model#sync" … />
 */
export default class extends Controller {
    static values = {
        name: String,
        debounce: { type: Number, default: 300 },
    };

    #timer = null;

    disconnect() {
        clearTimeout(this.#timer);
    }

    sync() {
        const input = this.element.querySelector('[data-slider-target="input"]');
        if (!input) {
            return;
        }
        clearTimeout(this.#timer);
        this.#timer = setTimeout(async () => {
            const component = await getComponent(this.element.closest(LIVE_ROOT));
            component.set(this.nameValue, Number(input.value), true);
        }, this.debounceValue);
    }
}
