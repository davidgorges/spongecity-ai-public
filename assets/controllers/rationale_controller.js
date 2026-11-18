import { Controller } from '@hotwired/stimulus';

/** dispatched as rationale:preview, detail {points} (plan millimetres, or null): the view shows it */
const EVENT_PREVIEW = 'preview';

/**
 * The alternatives the agent had for an object (the object details window): pointing at one, or
 * moving the focus to it, shows its outline in the 3D view next to the object; leaving it, hides it.
 *
 *   <ul data-controller="rationale">
 *       <li data-rationale-points-param="[[x, z], …]" data-action="mouseenter->rationale#preview mouseleave->rationale#unpreview">…</li>
 *   </ul>
 */
export default class extends Controller {
    preview({ params: { points } }) {
        this.dispatch(EVENT_PREVIEW, { detail: { points: Array.isArray(points) && points.length >= 3 ? points : null } });
    }

    unpreview(event) {
        // the focus moving within the same alternative keeps it shown
        if (event.type === 'focusout' && event.currentTarget.contains(event.relatedTarget)) {
            return;
        }
        this.dispatch(EVENT_PREVIEW, { detail: { points: null } });
    }

    disconnect() {
        this.dispatch(EVENT_PREVIEW, { detail: { points: null } });
    }
}
