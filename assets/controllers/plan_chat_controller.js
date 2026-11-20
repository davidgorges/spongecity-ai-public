import { Controller } from '@hotwired/stimulus';

/** the error key of a message the server did not accept */
const INVALID = 'plan.chat.invalid';
/** the error key of a turn that failed, or never came back */
const FAILED = 'failed';

/**
 * Chat with the planning agent. A message is posted to the plan's chat endpoint, which hands it to
 * a worker and answers at once (202); the agent's reply, or that it failed, arrives on the plan's
 * Mercure topic (App\SpongeCity\Chat\ChatStream: {reply} or {error}). While the agent works it
 * changes the plan through MCP tools, and each change re-renders the plan (plan_updates_controller.js),
 * so the user watches the design appear; once more when it answers (the `plan:changed` event on
 * window re-renders the PlanWorkspace live component).
 *
 *   <section data-controller="plan-chat" data-plan-chat-url-value="/plan/…/chat"
 *            data-plan-chat-stream-value="https://…/.well-known/mercure?topic=…" data-live-ignore>
 *
 * A new message is a copy of the template for its role (user, assistant, error: a Bubble each),
 * its text put into the element marked data-text.
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submit', 'status', 'template'];
    static values = {
        url: String,
        stream: String,
        errors: Object,
        /** a turn that has not answered by then is given up on (the agent's turn limit is lower) */
        timeoutMs: { type: Number, default: 300000 },
    };

    #source = null;
    #timeout = null;

    connect() {
        this.#scrollDown();
        // listening from the start: a reply is not missed however fast it comes
        this.#source = new EventSource(this.streamValue);
        this.#source.onmessage = (event) => this.#answered(JSON.parse(event.data));
    }

    disconnect() {
        this.#source?.close();
        this.#source = null;
        this.#done();
    }

    async send(event) {
        event.preventDefault();
        const message = this.inputTarget.value.trim();
        if ('' === message || this.submitTarget.disabled) {
            return;
        }
        this.#append('user', message);
        this.inputTarget.value = '';
        this.#busy(true);
        this.#timeout = setTimeout(() => this.#answered({ error: FAILED }), this.timeoutMsValue);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ message }),
            });
            if (!response.ok) {
                // 422: the message did not pass validation (empty, or too long)
                this.#answered({ error: 422 === response.status ? INVALID : FAILED });
            }
        } catch {
            this.#answered({ error: FAILED });
        }
    }

    /** Enter sends, Shift+Enter breaks the line. */
    keydown(event) {
        if ('Enter' === event.key && !event.shiftKey && !event.isComposing) {
            this.send(event);
        }
    }

    suggest(event) {
        this.inputTarget.value = event.params.text;
        this.inputTarget.focus();
    }

    /** the agent's reply, or an error key: ends the wait */
    #answered(data) {
        if (data.reply) {
            this.#append('assistant', data.reply);
        } else {
            this.#append('error', this.errorsValue[data.error] ?? this.errorsValue[FAILED] ?? '');
        }
        this.#done();
        this.#planChanged();
    }

    #done() {
        clearTimeout(this.#timeout);
        this.#timeout = null;
        this.#busy(false);
    }

    #append(role, text) {
        const template = this.templateTargets.find((candidate) => candidate.dataset.role === role) ?? this.templateTarget;
        const node = template.content.firstElementChild.cloneNode(true);
        node.querySelector('[data-text]').textContent = text;
        this.messagesTarget.append(node);
        this.#scrollDown();
    }

    #busy(busy) {
        this.submitTarget.disabled = busy;
        this.statusTarget.hidden = !busy;
        this.element.setAttribute('aria-busy', String(busy));
    }

    #planChanged() {
        window.dispatchEvent(new CustomEvent('plan:changed'));
    }

    #scrollDown() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
}
