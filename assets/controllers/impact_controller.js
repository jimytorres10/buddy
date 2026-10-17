import { Controller } from '@hotwired/stimulus';

const money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

/*
 * Recalcule en direct la répartition des revenus d'un mois (fixes / dépensé / reste des budgets / épargne)
 * pendant qu'on ajuste un budget ou une dépense fixe, et affiche l'écart avec la situation actuelle.
 * Même calcul que StatsReport::incomeSplit ; montants en centimes.
 *
 * Modes :
 *  - month-budgets : budgets d'un mois (champ vide = budget habituel)
 *  - budgets       : budgets habituels (un ajustement du mois reste prioritaire)
 *  - fixed         : liste complète des dépenses fixes
 *  - fixed-add     : ajout d'une dépense fixe
 */
export default class extends Controller {
    static targets = ['panel', 'part', 'seg', 'note'];
    static values = { mode: String, data: Object };

    connect() {
        this.before = this.split(this.dataValue.fixed, this.budgetLeft((id, c) => c.override ?? c.usual ?? 0));
        this.update = this.update.bind(this);
        this.element.addEventListener('input', this.update);
        this.element.addEventListener('change', this.update);
        // Lignes ajoutées ou retirées (dépenses fixes, nouvelles catégories)
        // (on ignore les changements de l'aperçu lui-même, sinon il se relancerait en boucle)
        this.observer = new MutationObserver((mutations) => {
            if (mutations.some((m) => !this.panelTarget.contains(m.target))) this.update();
        });
        this.observer.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        this.element.removeEventListener('input', this.update);
        this.element.removeEventListener('change', this.update);
        this.observer.disconnect();
    }

    update() {
        if (!this.hasPartTarget) return;
        const after = this.split(this.fixedAfter(), this.budgetLeftAfter());
        const income = this.dataValue.income;

        this.partTargets.forEach((li) => {
            const key = li.dataset.key;
            const delta = after.parts[key] - this.before.parts[key];
            li.querySelector('[data-role=value]').textContent = money.format(after.parts[key] / 100);
            li.querySelector('[data-role=percent]').textContent = `${Math.round(after.parts[key] * 100 / income)} %`;

            const badge = li.querySelector('[data-role=delta]');
            badge.hidden = Math.abs(delta) < 1;
            badge.textContent = `${delta > 0 ? '+' : '−'}${money.format(Math.abs(delta) / 100)}`;
            // L'épargne qui baisse est en rouge, qui monte en vert ; le reste est neutre
            badge.className = `impact-delta${key === 'saving' ? (delta > 0 ? ' is-up' : ' is-down') : ''}`;
        });
        this.segTargets.forEach((seg) => {
            seg.style.width = `${after.parts[seg.dataset.key] * 100 / after.base}%`;
        });
        if (this.hasNoteTarget) {
            this.noteTarget.hidden = after.overflow === 0;
            this.noteTarget.textContent = `Dépassement de ${money.format(after.overflow / 100)} sur tes revenus.`;
        }
        this.panelTarget.classList.toggle('is-changed', after.parts.saving !== this.before.parts.saving || after.parts.fixed !== this.before.parts.fixed || after.parts.left !== this.before.parts.left);
    }

    split(fixed, budgetLeft) {
        const { income, spent } = this.dataValue;
        const outgoing = fixed + spent;
        const available = Math.max(0, income - outgoing);

        return {
            parts: {
                fixed,
                spent,
                left: Math.min(budgetLeft, available),
                saving: Math.max(0, available - budgetLeft),
            },
            base: Math.max(income, outgoing, 1),
            overflow: Math.max(0, outgoing - income),
        };
    }

    /** Reste des budgets : somme de (budget − dépensé) par catégorie, sans négatif. */
    budgetLeft(budgetOf) {
        return Object.entries(this.dataValue.categories)
            .reduce((sum, [id, c]) => sum + Math.max(0, budgetOf(id, c) - c.spent), 0);
    }

    budgetLeftAfter() {
        if (this.modeValue === 'month-budgets' || this.modeValue === 'budgets') {
            const inputs = {};
            this.element.querySelectorAll('input').forEach((input) => {
                const match = input.name.match(/\[c(\d+)\]$/);
                if (match) inputs[match[1]] = input.value.trim();
            });
            const usual = this.modeValue === 'budgets';
            let left = this.budgetLeft((id, c) => {
                const value = inputs[id];
                if (usual) return c.override ?? (value ? this.cents(value) : 0);
                return value === undefined || value === '' ? (c.usual ?? 0) : this.cents(value);
            });
            // Nouvelles catégories créées avec un budget (rien de dépensé encore)
            this.element.querySelectorAll('input[name*="[newCategories]"][name$="[amount]"]').forEach((input) => {
                left += this.cents(input.value);
            });

            return left;
        }

        return this.budgetLeft((id, c) => c.override ?? c.usual ?? 0);
    }

    fixedAfter() {
        if (this.modeValue === 'fixed') {
            return [...this.element.querySelectorAll('.item-row')].reduce((sum, row) => sum + this.activeAmount(row), 0);
        }
        if (this.modeValue === 'fixed-add') {
            return this.dataValue.fixed + this.activeAmount(this.element);
        }

        return this.dataValue.fixed;
    }

    /** Montant d'une dépense fixe si elle est active le mois concerné (période « De / À » ou « pendant N mois »). */
    activeAmount(scope) {
        const field = (name) => scope.querySelector(`[name$="[${name}]"]`)?.value.trim() || '';
        const amount = this.cents(field('amount'));
        const month = this.dataValue.month;
        const duration = parseInt(field('duration'), 10);
        let start = field('startMonth');
        let end = field('endMonth');
        if (duration > 0) {
            start ||= this.currentMonth();
            end = this.addMonths(start, duration - 1);
        }

        return (!start || start <= month) && (!end || end >= month) ? amount : 0;
    }

    cents(value) {
        const number = parseFloat(String(value).replace(/\s/g, '').replace(',', '.'));

        return Number.isFinite(number) && number > 0 ? Math.round(number * 100) : 0;
    }

    addMonths(ym, count) {
        const [year, month] = ym.split('-').map(Number);
        const date = new Date(year, month - 1 + count, 1);

        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    }

    currentMonth() {
        const now = new Date();

        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    }
}
