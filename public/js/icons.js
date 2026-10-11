/**
 * Icônes pour le JavaScript : les SVG sont rendus par Twig (templates/layout/modern/components/_js_icons.html.twig)
 * puis lus ici par nom. Évite de recopier des SVG dans les scripts et garde UNE seule icône par action.
 *   CentralIcons.get('close')          → '<svg …>…</svg>'
 *   CentralIcons.get('close', '×')     → texte de repli si l'icône n'existe pas
 */
const CentralIcons = {

    config: {
        version: 'v1.0.0',
        appName: 'Central-Icons'
    },

    cache: null,

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
