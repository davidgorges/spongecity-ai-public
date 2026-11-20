import { Controller } from '@hotwired/stimulus';

/**
 * Re-renders the plan whenever it changes elsewhere: an agent's tool call, in the app's chat or
 * from an agent a person connected over MCP, publishes on the plan's Mercure topic
 * (App\SpongeCity\Plan\PlanUpdates); this dispatches the event the PlanWorkspace live component
 * re-renders on. An agent's tool calls come in bursts, so they are gathered into one re-render.
 *
 *   <div data-controller="plan-updates" data-plan-updates-stream-value="https://…/.well-known/mercure?match=…"
 *        data-plan-updates-event-value="plan:changed" data-live-ignore hidden>
 */
export default class extends Controller {
    static values = {
        stream: String,
        event: String,
        delayMs: { type: Number, default: 250 },
    };

    #source = null;
    #timer = null;

    connect() {
        this.#source = new EventSource(this.streamValue);
        this.#source.onmessage = () => {
            clearTimeout(this.#timer);
            this.#timer = setTimeout(() => window.dispatchEvent(new CustomEvent(this.eventValue)), this.delayMsValue);
        };
    }

    disconnect() {
        clearTimeout(this.#timer);
        this.#source?.close();
        this.#source = null;
    }
}
