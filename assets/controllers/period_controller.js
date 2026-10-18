import { Controller } from '@hotwired/stimulus';

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/*
 * Période (dépense fixe, revenu…) : dates de début/fin, « pendant N mois » à partir du début, ou ce mois-ci seulement.
 * Le calcul de la fin est refait côté serveur ; ici on ne fait qu'afficher l'aperçu.
 */
export default class extends Controller {
    static targets = ['modeBtn', 'start', 'end', 'endField', 'duration', 'durationField', 'hint'];
    static values = { mode: String };

    connect() {
        this.defaultHint = this.hintTarget.textContent;
        this.apply();
    }

    select(event) {
        this.modeValue = event.currentTarget.dataset.mode;
        if (this.modeValue === 'duration') {
            if (!this.startTarget.value) this.startTarget.value = this.currentMonth();
            this.durationTarget.focus();
        } else {
            this.durationTarget.value = '';
        }
        this.apply();
    }

    thisMonth() {
        this.modeValue = 'duration';
        this.startTarget.value = this.currentMonth();
        this.durationTarget.value = '1';
        this.apply();
        this.element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    refresh() {
        this.apply();
    }

    apply() {
        const byDuration = this.modeValue === 'duration';
        this.modeBtnTargets.forEach((btn) => btn.setAttribute('aria-pressed', String(btn.dataset.mode === this.modeValue)));
        this.endFieldTarget.hidden = byDuration;
        this.durationFieldTarget.hidden = !byDuration;
        this.hintTarget.textContent = byDuration ? this.preview() : this.defaultHint;
    }

    preview() {
        const months = parseInt(this.durationTarget.value, 10);
        const [year, month] = (this.startTarget.value || this.currentMonth()).split('-').map(Number);
        if (!months || months < 1 || !year || !month) {
            return 'Indique le nombre de mois, à partir du mois de début.';
        }
        const end = new Date(year, month - 1 + months - 1, 1);
        // On garde la fin calculée dans le champ « À » pour que l'affichage reste cohérent
        this.endTarget.value = `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}`;

        if (months === 1) {
            return `Seulement en ${MONTHS[month - 1]} ${year}.`;
        }

        return `Soit de ${MONTHS[month - 1]} ${year} à ${MONTHS[end.getMonth()]} ${end.getFullYear()} (${months} mois).`;
    }

    currentMonth() {
        const now = new Date();

        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    }
}
