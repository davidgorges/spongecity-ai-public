import { Controller } from '@hotwired/stimulus';

/**
 * Marks the form as busy while it is being sent (a place search takes a few seconds:
 * geocoding and OpenStreetMap) and keeps the button from being pressed twice.
 *
 *   <form {{ stimulus_controller('form-submit-disable') }} data-action="submit->form-submit-disable#busy">
 */
export default class extends Controller {
    static targets = ['button'];

    busy() {
        this.buttonTargets.forEach((button) => {
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('opacity-60', 'pointer-events-none');
        });
        this.element.setAttribute('aria-busy', 'true');
    }
}
