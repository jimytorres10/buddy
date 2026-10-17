import { Controller } from '@hotwired/stimulus';

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const DEFAULT_HINT = 'Laisse vide pour « tous les mois ». Ex. : rappel d\'impôts de septembre à décembre.';

/*
 * Période d'une dépense fixe : dates de début/fin, ou « pendant N mois » à partir du début.
 * Le calcul de la fin est refait côté serveur ; ici on ne fait qu'afficher l'aperçu.
 */
export default class extends Controller {
    static targets = ['modeBtn', 'start', 'end', 'endField', 'duration', 'durationField', 'hint'];
    static values = { mode: String };

    connect() {
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

    refresh() {
        this.apply();
    }

    apply() {
        const byDuration = this.modeValue === 'duration';
        this.modeBtnTargets.forEach((btn) => btn.setAttribute('aria-pressed', String(btn.dataset.mode === this.modeValue)));
        this.endFieldTarget.hidden = byDuration;
        this.durationFieldTarget.hidden = !byDuration;
        this.hintTarget.textContent = byDuration ? this.preview() : DEFAULT_HINT;
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

        return `Soit de ${MONTHS[month - 1]} ${year} à ${MONTHS[end.getMonth()]} ${end.getFullYear()} (${months} mois).`;
    }

    currentMonth() {
        const now = new Date();

        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    }
}
