/* Sauvegardes : confirmation de relance + rafraîchissement automatique tant qu'une tâche tourne ou qu'une relance est en attente. */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-jobs-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.jobsConfirm)) {
                event.preventDefault();
            }
        });
    });

    const page = document.querySelector('[data-jobs-page]');
    if (!page || page.dataset.jobsBusy !== '1') {
        return;
    }

    // La relance démarre au plus tard à la minute suivante : on interroge l'état toutes les 6 s (max ~10 min).
    let tries = 0;
    const poll = async () => {
        tries += 1;
        try {
            const res = await fetch(page.dataset.jobsStateUrl, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            const data = await res.json();
            const busy = Object.values(data.jobs).some((j) => j[0] === 'running' || j[2] === true);
            if (!busy) {
                window.location.reload();
                return;
            }
        } catch (e) {
            // réseau indisponible : on réessaie
        }
        if (tries < 100) {
            window.setTimeout(poll, 6000);
        }
    };
    window.setTimeout(poll, 6000);
});
