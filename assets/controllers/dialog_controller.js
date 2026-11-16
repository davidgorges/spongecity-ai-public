import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'dialog'];

    static values = {
        open: Boolean,
    };

    connect() {
        if (this.openValue) {
            this.open();
        }
    }

    open() {
        this.dialogTarget.showModal();

        // the dialog's state as of now: waiting for the end of its transition is unreliable (the event bubbles from children, or never comes)
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'true');
        }
    }

    closeOnClickOutside({ target }) {
        if (target === this.dialogTarget) {
            this.close();
        }
    }

    close() {
        this.dialogTarget.close();

        // the dialog's state as of now: waiting for the end of its transition is unreliable (the event bubbles from children, or never comes)
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }
    }
}
