import { Controller } from '@hotwired/stimulus';

const THRESHOLD = 110;

/*
 * Tri des opérations importées façon « Tinder ».
 * - glisser à droite : valider (bouton cible « right » : suggestion ou revenu)
 * - glisser à gauche : ignorer (« left »)
 * - glisser vers le haut : ouvrir le choix « dépense fixe » (« fixed »)
 * Clavier : flèches, 1-9 pour une catégorie, Retour arrière pour annuler.
 * Chaque geste ne fait que cliquer le vrai bouton du formulaire : tout fonctionne aussi sans JS.
 */
export default class extends Controller {
    static targets = ['card', 'right', 'left', 'fixed', 'category'];

    connect() {
        this.onKey = this.onKey.bind(this);
        document.addEventListener('keydown', this.onKey);

        this.cardTarget.addEventListener('pointerdown', (e) => this.start(e));
        this.cardTarget.addEventListener('pointermove', (e) => this.move(e));
        this.cardTarget.addEventListener('pointerup', (e) => this.end(e));
        this.cardTarget.addEventListener('pointercancel', () => this.cancel());
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    start(event) {
        // On ne glisse pas depuis un champ, un bouton ou le panneau « dépense fixe »
        if (event.button !== 0 || event.target.closest('input, select, button, summary, details[open], a')) return;
        this.origin = { x: event.clientX, y: event.clientY };
        this.delta = { x: 0, y: 0 };
        this.cardTarget.setPointerCapture(event.pointerId);
        this.cardTarget.classList.add('is-dragging');
    }

    move(event) {
        if (!this.origin) return;
        this.delta = { x: event.clientX - this.origin.x, y: event.clientY - this.origin.y };
        const { x, y } = this.delta;
        this.cardTarget.style.transform = `translate(${x}px, ${Math.min(y, 40)}px) rotate(${x / 18}deg)`;
        this.cardTarget.dataset.direction = this.direction() || '';
    }

    end() {
        if (!this.origin) return;
        const direction = this.direction();
        this.origin = null;
        this.cardTarget.classList.remove('is-dragging');
        if (direction) {
            this.go(direction);
        } else {
            this.cancel();
        }
    }

    cancel() {
        this.origin = null;
        this.cardTarget.classList.remove('is-dragging');
        this.cardTarget.style.transform = '';
        delete this.cardTarget.dataset.direction;
    }

    direction() {
        const { x, y } = this.delta;
        if (-y > THRESHOLD && Math.abs(y) > Math.abs(x) && this.hasFixedTarget) return 'up';
        if (x > THRESHOLD) return 'right';
        if (x < -THRESHOLD) return 'left';
        return null;
    }

    go(direction) {
        if (direction === 'up') {
            this.cancel();
            this.openFixed();
            return;
        }
        const button = direction === 'right' ? (this.hasRightTarget ? this.rightTarget : null) : (this.hasLeftTarget ? this.leftTarget : null);
        if (!button) {
            // Pas de suggestion : il faut choisir une catégorie
            this.cancel();
            this.cardTarget.classList.add('is-shaking');
            setTimeout(() => this.cardTarget.classList.remove('is-shaking'), 400);
            return;
        }
        this.submit(button, direction);
    }

    submit(button, direction) {
        const card = this.cardTarget;
        card.style.transform = '';
        card.classList.add(`is-leaving-${direction}`);
        setTimeout(() => card.requestSubmit(button), 180);
    }

    openFixed() {
        if (!this.hasFixedTarget) return;
        this.fixedTarget.open = true;
        this.fixedTarget.querySelector('select')?.focus();
    }

    onKey(event) {
        if (event.target.closest('input, select, textarea') || event.ctrlKey || event.metaKey || event.altKey) return;

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.go('right');
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.go('left');
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.go('up');
        } else if (event.key === 'Backspace') {
            const undo = document.getElementById('undo-form');
            if (undo) {
                event.preventDefault();
                undo.requestSubmit();
            }
        } else if (/^[1-9]$/.test(event.key)) {
            const button = this.categoryTargets[Number(event.key) - 1];
            if (button) {
                event.preventDefault();
                this.submit(button, 'right');
            }
        }
    }
}
