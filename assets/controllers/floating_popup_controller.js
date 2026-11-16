import { Controller } from '@hotwired/stimulus';
import { computePosition, flip, shift, offset } from '@floating-ui/dom';

/**
 * Positions a popup (DropdownMenu, notification popup, mega menu) relative to its
 * button with Floating UI. Visibility is driven by the `opened` class
 * (_floating_popup.css); this controller toggles it, keeps `aria-expanded` in sync
 * and computes the position.
 *
 * Opening dispatches `floating-popup:opened` (bubbles), so other popups can close:
 *   data-action="floating-popup:opened@window->floating-popup#closeOther"
 *
 * Moving focus from the button into the popup fires `focusout` on the wrapper;
 * `close()` ignores a focusout whose next target is still inside, so keyboard users
 * tabbing into a mega menu keep it open.
 *
 * Hover menus open with `hover` instead of `open`: after a Turbo visit the new
 * element appears under a cursor that has not moved, and the browser fires
 * `mouseenter` on it. `hover` only opens once the pointer has been seen outside the
 * element (`arm`), so the menu waits until the user leaves and comes back. The
 * document `pointermove` is bound `:once`; the next `mouseleave` arms otherwise.
 *
 *   <div data-controller="floating-popup"
 *        data-action="mouseenter->floating-popup#hover mouseleave->floating-popup#arm mouseleave->floating-popup#close pointermove@document->floating-popup#arm:once focusin->floating-popup#open focusout->floating-popup#close">
 */
export default class extends Controller {
    static targets = ['button', 'popup'];
    static values = {
        placement: { type: String, default: 'bottom-start' },
        offset: { type: Number, default: 8 },
        closeOnOutsideClick: {
            type: Boolean,
            default: true,
        },
    };

    connect() {
        this.armed = false;
        if (this.isOpen) {
            this.#updatePosition();
        }
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    arm(event) {
        if (event.type === 'mouseleave' || !this.element.contains(event.target)) {
            this.armed = true;
        }
    }

    hover() {
        if (this.armed) {
            this.open();
        }
    }

    open() {
        if (!this.hasPopupTarget || this.isOpen) {
            return;
        }

        this.#setExpanded(true);
        this.popupTarget.classList.add('opened');
        this.#updatePosition();
        if (this.closeOnOutsideClickValue) {
            this.#outsideClickHandler = (event) => {
                if (!this.element.contains(event.target)) {
                    this.close();
                }
            };
            document.addEventListener('click', this.#outsideClickHandler, true);
        }
        this.dispatch('opened');
    }

    close(event) {
        if (!this.isOpen) {
            return;
        }

        if (event?.type === 'focusout' && this.element.contains(event.relatedTarget)) {
            return;
        }

        this.#setExpanded(false);
        this.popupTarget.classList.remove('opened');
        this.#removeOutsideClickHandler();
    }

    /** Closes this popup when another one opened (`floating-popup:opened@window`). */
    closeOther(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    disconnect() {
        this.#removeOutsideClickHandler();
    }

    get isOpen() {
        return this.hasPopupTarget && this.popupTarget.classList.contains('opened');
    }

    async #updatePosition() {
        const { x, y, placement } = await computePosition(this.buttonTarget, this.popupTarget, {
            placement: this.placementValue,
            middleware: [offset(this.offsetValue), flip(), shift({ padding: 8 })],
        });

        Object.assign(this.popupTarget.style, {
            left: `${x}px`,
            top: `${y}px`,
        });
        // The hover bridge across the offset gap (_floating_popup.css) sits on this side.
        this.popupTarget.style.setProperty('--floating-popup-offset', `${this.offsetValue}px`);
        this.popupTarget.dataset.floatingPopupSide = placement.split('-')[0];
    }

    #setExpanded(expanded) {
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    #removeOutsideClickHandler() {
        if (this.#outsideClickHandler) {
            document.removeEventListener('click', this.#outsideClickHandler, true);
            this.#outsideClickHandler = null;
        }
    }

    #outsideClickHandler = null;
}
