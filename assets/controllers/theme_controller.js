import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'buddy-theme';

/*
 * Choix du thème clair / sombre / automatique (suit le système).
 * Le thème est appliqué via l'attribut data-theme sur <html>.
 */
export default class extends Controller {
    static targets = ['option'];

    connect() {
        const current = readTheme();
        this.optionTargets.forEach((input) => {
            input.checked = input.value === current;
        });
    }

    choose(event) {
        const theme = event.target.value;
        try {
            if (theme === 'system') {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, theme);
            }
        } catch (e) {}

        if (theme === 'system') {
            document.documentElement.removeAttribute('data-theme');
        } else {
            document.documentElement.setAttribute('data-theme', theme);
        }
    }
}

function readTheme() {
    try {
        return localStorage.getItem(STORAGE_KEY) || 'system';
    } catch (e) {
        return 'system';
    }
}
