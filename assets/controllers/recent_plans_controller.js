import { Controller } from '@hotwired/stimulus';

/** how many plans are remembered */
const MAX_PLANS = 8;

/**
 * The plans opened in this browser, newest first, kept in localStorage (there are no accounts: a
 * plan is reached by its link, and this keeps the links).
 *
 * On a plan's page it records the plan (a `plan` value: {id, title, url}); on the home page it lists
 * them, each a copy of the `template` target with the title and link filled in ([data-text],
 * [data-link]), and shows the `section` target only when there are any.
 *
 *   <div data-controller="recent-plans" data-recent-plans-storage-key-value="…" data-recent-plans-plan-value="{…}"></div>
 *   <section data-controller="recent-plans" data-recent-plans-target="section" hidden>
 *       <ul data-recent-plans-target="list"></ul>
 *       <template data-recent-plans-target="template"><li><a data-link><span data-text></span></a></li></template>
 *   </section>
 */
export default class extends Controller {
    static targets = ['section', 'list', 'template'];
    static values = { storageKey: String, plan: Object };

    connect() {
        if (this.planValue.id) {
            this.#record(this.planValue);
        }
        if (this.hasListTarget && this.hasTemplateTarget) {
            this.#show(this.#load());
        }
    }

    #record({ id, title, url }) {
        const plans = this.#load().filter((plan) => plan.id !== id);
        this.#save([{ id, title, url }, ...plans].slice(0, MAX_PLANS));
    }

    #show(plans) {
        const items = plans.map(({ title, url }) => {
            const node = this.templateTarget.content.firstElementChild.cloneNode(true);
            const link = node.querySelector('[data-link]') ?? node;
            link.setAttribute('href', url);
            (node.querySelector('[data-text]') ?? link).textContent = title;

            return node;
        });
        this.listTarget.replaceChildren(...items);
        if (this.hasSectionTarget) {
            this.sectionTarget.hidden = items.length === 0;
        }
    }

    #load() {
        try {
            const stored = JSON.parse(localStorage.getItem(this.storageKeyValue) ?? '[]');

            return Array.isArray(stored) ? stored.filter((plan) => plan?.id && plan?.url) : [];
        } catch {
            return [];
        }
    }

    #save(plans) {
        try {
            localStorage.setItem(this.storageKeyValue, JSON.stringify(plans));
        } catch {
            // storage blocked (private mode): nothing is remembered
        }
    }
}
