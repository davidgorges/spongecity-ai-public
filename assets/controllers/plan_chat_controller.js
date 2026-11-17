import { Controller } from '@hotwired/stimulus';

/** the error key of a message the server did not accept */
const INVALID = 'plan.chat.invalid';

/**
 * Chat with the planning agent. A message is posted to the plan's chat endpoint; while the
 * agent works it changes the plan through MCP tools, so the plan is refreshed when it
 * answers (the `plan:changed` event on window re-renders the PlanWorkspace live component),
 * and after a while during the wait, so the user watches the design appear.
 *
 *   <section data-controller="plan-chat" data-plan-chat-url-value="/plan/…/chat" data-live-ignore>
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submit', 'status', 'template'];
    static values = {
        url: String,
        errors: Object,
        refreshMs: { type: Number, default: 2500 },
    };

    #timer = null;

    connect() {
        this.#scrollDown();
    }

    disconnect() {
        this.#stopRefreshing();
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
        this.#startRefreshing();

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ message }),
            });
            const data = await response.json();
            if (response.ok && data.reply) {
                this.#append('assistant', data.reply);
            } else {
                // 422: the message did not pass validation (empty, or too long)
                const error = data.error ?? (422 === response.status ? INVALID : null);
                this.#append('error', this.errorsValue[error] ?? this.errorsValue.failed ?? '');
            }
        } catch {
            this.#append('error', this.errorsValue.failed ?? '');
        } finally {
            this.#stopRefreshing();
            this.#busy(false);
            this.#planChanged();
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

    #append(role, text) {
        const node = this.templateTarget.content.firstElementChild.cloneNode(true);
        node.dataset.role = role;
        node.querySelector('[data-text]').textContent = text;
        this.messagesTarget.append(node);
        this.#scrollDown();
    }

    #busy(busy) {
        this.submitTarget.disabled = busy;
        this.statusTarget.hidden = !busy;
        this.element.setAttribute('aria-busy', String(busy));
    }

    #startRefreshing() {
        this.#timer = setInterval(() => this.#planChanged(), this.refreshMsValue);
    }

    #stopRefreshing() {
        clearInterval(this.#timer);
        this.#timer = null;
    }

    #planChanged() {
        window.dispatchEvent(new CustomEvent('plan:changed'));
    }

    #scrollDown() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
}
