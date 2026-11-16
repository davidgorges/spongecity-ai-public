import { Controller } from '@hotwired/stimulus';

/**
 * Puts a suggestion into a text field (by id) and focuses it.
 *
 *   <button data-controller="fill-input" data-action="fill-input#fill" data-fill-input-target-value="place" data-fill-input-text-value="Berlin">
 */
export default class extends Controller {
    static values = { target: String, text: String };

    fill() {
        const field = document.getElementById(this.targetValue);
        if (field) {
            field.value = this.textValue;
            field.focus();
        }
    }
}
