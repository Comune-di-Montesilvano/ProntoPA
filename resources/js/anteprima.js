// Anteprima in sola lettura delle segnalazioni negli elenchi (1.0.6).
// Link con data-anteprima="URL": dopo una breve sosta del mouse (o col focus da tastiera)
// carica il frammento dal server una volta sola e lo mostra in un riquadro accanto al link.
// Sui dispositivi senza hover (telefono) non fa nulla: si apre la scheda.

const ATTESA_MS = 450;
const CHIUSURA_MS = 150;
const cache = new Map();
let riquadro = null;
let timerApri = null;
let timerChiudi = null;
let corrente = null;

function crea() {
    riquadro = document.createElement('div');
    riquadro.className = 'pa-anteprima';
    riquadro.setAttribute('role', 'tooltip');
    riquadro.id = 'pa-anteprima';
    riquadro.hidden = true;
    riquadro.addEventListener('mouseenter', () => clearTimeout(timerChiudi));
    riquadro.addEventListener('mouseleave', chiudiPresto);
    document.body.appendChild(riquadro);
}

function posiziona(link) {
    const r = link.getBoundingClientRect();
    const larghezza = riquadro.offsetWidth;
    const altezza = riquadro.offsetHeight;
    const margine = 8;
    let left = Math.min(r.left, window.innerWidth - larghezza - margine);
    left = Math.max(margine, left);
    let top = r.bottom + 6;
    if (top + altezza > window.innerHeight - margine) {
        top = Math.max(margine, r.top - altezza - 6);
    }
    riquadro.style.left = `${left}px`;
    riquadro.style.top = `${top}px`;
}

async function carica(url) {
    if (!cache.has(url)) {
        cache.set(url, fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
            .catch((e) => { cache.delete(url); throw e; }));
    }
    return cache.get(url);
}

async function apri(link) {
    const url = link.dataset.anteprima;
    corrente = link;
    let html;
    try {
        html = await carica(url);
    } catch {
        return;
    }
    if (corrente !== link) return;
    if (!riquadro) crea();
    riquadro.innerHTML = html;
    riquadro.hidden = false;
    link.setAttribute('aria-describedby', riquadro.id);
    posiziona(link);
}

function chiudi() {
    clearTimeout(timerApri);
    if (corrente) corrente.removeAttribute('aria-describedby');
    corrente = null;
    if (riquadro) riquadro.hidden = true;
}

function chiudiPresto() {
    clearTimeout(timerApri);
    clearTimeout(timerChiudi);
    timerChiudi = setTimeout(chiudi, CHIUSURA_MS);
}

function programma(link) {
    clearTimeout(timerChiudi);
    clearTimeout(timerApri);
    if (corrente === link && riquadro && !riquadro.hidden) return;
    timerApri = setTimeout(() => apri(link), ATTESA_MS);
}

export function avviaAnteprime() {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    document.addEventListener('mouseover', (e) => {
        const link = e.target.closest?.('[data-anteprima]');
        if (link) programma(link);
    });
    document.addEventListener('mouseout', (e) => {
        const link = e.target.closest?.('[data-anteprima]');
        if (link && !link.contains(e.relatedTarget)) chiudiPresto();
    });
    document.addEventListener('focusin', (e) => {
        const link = e.target.closest?.('[data-anteprima]');
        if (link) programma(link);
    });
    document.addEventListener('focusout', (e) => {
        if (e.target.closest?.('[data-anteprima]')) chiudiPresto();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') chiudi(); });
    // Lo scroll sposta la riga: si chiude il riquadro già aperto, non quello in attesa.
    window.addEventListener('scroll', () => { if (riquadro && !riquadro.hidden) chiudi(); }, { passive: true, capture: true });
}
