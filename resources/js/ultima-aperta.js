// Evidenzia negli elenchi la segnalazione aperta per ultima (1.0.7): tornando indietro
// dalla scheda si ritrova la riga da cui si era entrati.
// Righe con data-segnalazione="ID"; l'id resta in sessionStorage (solo questa scheda del browser).

const CHIAVE = 'pa-ultima-aperta';

function leggi() {
    try { return sessionStorage.getItem(CHIAVE); } catch { return null; }
}

function scrivi(id) {
    try { sessionStorage.setItem(CHIAVE, id); } catch { /* storage non disponibile */ }
}

function evidenzia() {
    document.querySelectorAll('tr.pa-riga-ultima').forEach((r) => r.classList.remove('pa-riga-ultima'));
    const id = leggi();
    if (!id) return;
    const riga = document.querySelector(`tr[data-segnalazione="${CSS.escape(id)}"]`);
    if (!riga) return;
    riga.classList.add('pa-riga-ultima');
    const r = riga.getBoundingClientRect();
    if (r.bottom < 0 || r.top > window.innerHeight) {
        riga.scrollIntoView({ block: 'center' });
    }
}

export function avviaUltimaAperta() {
    document.addEventListener('click', (e) => {
        const riga = e.target.closest?.('tr[data-segnalazione]');
        if (riga && e.target.closest('a[href]')) scrivi(riga.dataset.segnalazione);
    });
    // anche il clic col tasto centrale (nuova scheda del browser)
    document.addEventListener('auxclick', (e) => {
        const riga = e.target.closest?.('tr[data-segnalazione]');
        if (riga && e.target.closest('a[href]')) scrivi(riga.dataset.segnalazione);
    });
    evidenzia();
    // "Indietro" dalla cache del browser (bfcache) non ricarica la pagina
    window.addEventListener('pageshow', (e) => { if (e.persisted) evidenzia(); });
}
