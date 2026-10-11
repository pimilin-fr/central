/**
 * Icônes pour le JavaScript : les SVG sont rendus par Twig (templates/layout/modern/components/_js_icons.html.twig)
 * puis lus ici par nom. Évite de recopier des SVG dans les scripts et garde UNE seule icône par action.
 *   CentralIcons.get('close')          → '<svg …>…</svg>'
 *   CentralIcons.get('close', '×')     → texte de repli si l'icône n'existe pas
 *   CentralIcons.button('rc-up', '▲')  → le bouton complet rendu par le composant _btns (même apparence que les templates)
 */
const CentralIcons = {

    config: {
        version: 'v1.1.0',
        appName: 'Central-Icons'
    },

    cache: null,
    buttons: null,

    /** Bouton rendu par _btns (templates/layout/modern/components/_js_icons.html.twig, <template id="central-btns">). */
    button(name, fallback = '') {
        if (this.buttons === null) {
            this.buttons = {};
            const tpl = document.getElementById('central-btns');
            if (tpl) {
                tpl.content.querySelectorAll('[data-btn]').forEach(el => {
                    this.buttons[el.dataset.btn] = el.innerHTML.trim();
                });
            }
        }

        return this.buttons[name] || '<button type="button" class="btn btn-neutral btn-icon" data-' + name + '>' + fallback + '</button>';
    },

    get(name, fallback = '') {
        if (this.cache === null) {
            this.cache = {};
            const tpl = document.getElementById('central-icons');
            if (tpl) {
                tpl.content.querySelectorAll('[data-icon]').forEach(el => {
                    this.cache[el.dataset.icon] = el.innerHTML.trim();
                });
            }
        }

        return this.cache[name] || fallback;
    }
};

window.CentralIcons = CentralIcons;
