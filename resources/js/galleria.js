// Carosello delle foto (1.0.17): i link con data-galleria="gruppo" si aprono sopra la pagina,
// senza scaricare il file. Frecce/scorrimento per passare da una foto all'altra, Esc per chiudere.
// Ctrl/Cmd-clic o clic centrale: comportamento normale del browser (foto in una nuova scheda).

let overlay = null;
let immagine = null;
let contatore = null;
let didascalia = null;
let scarica = null;
let foto = [];
let indice = 0;
let ultimoFocus = null;
let inizioTocco = null;

function crea() {
    overlay = document.createElement('div');
    overlay.className = 'pa-galleria';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Foto della segnalazione');
    overlay.innerHTML = `
        <div class="pa-galleria-barra">
            <span class="pa-galleria-contatore" aria-live="polite"></span>
            <span class="pa-galleria-azioni">
                <a class="pa-galleria-tasto" data-azione="scarica" download><span><i class="fas fa-download" aria-hidden="true"></i></span><span class="pa-galleria-testo">Scarica</span></a>
                <button type="button" class="pa-galleria-tasto" data-azione="chiudi" aria-label="Chiudi"><span><i class="fas fa-xmark" aria-hidden="true"></i></span></button>
            </span>
        </div>
        <button type="button" class="pa-galleria-freccia pa-galleria-prec" data-azione="prec" aria-label="Foto precedente"><span><i class="fas fa-chevron-left" aria-hidden="true"></i></span></button>
        <figure class="pa-galleria-figura">
            <img alt="">
            <figcaption></figcaption>
        </figure>
        <button type="button" class="pa-galleria-freccia pa-galleria-succ" data-azione="succ" aria-label="Foto successiva"><span><i class="fas fa-chevron-right" aria-hidden="true"></i></span></button>`;
    document.body.appendChild(overlay);

    immagine = overlay.querySelector('img');
    contatore = overlay.querySelector('.pa-galleria-contatore');
    didascalia = overlay.querySelector('figcaption');
    scarica = overlay.querySelector('[data-azione="scarica"]');

    overlay.addEventListener('click', (e) => {
        const azione = e.target.closest('[data-azione]')?.dataset.azione;
        if (azione === 'chiudi') chiudi();
        else if (azione === 'prec') vai(-1);
        else if (azione === 'succ') vai(1);
        else if (azione !== 'scarica' && !e.target.closest('img')) chiudi(); // clic sullo sfondo
    });
    overlay.addEventListener('touchstart', (e) => { inizioTocco = e.touches[0]?.clientX ?? null; }, { passive: true });
    overlay.addEventListener('touchend', (e) => {
        const fine = e.changedTouches[0]?.clientX;
        if (inizioTocco !== null && fine !== undefined && Math.abs(fine - inizioTocco) > 50) {
            vai(fine < inizioTocco ? 1 : -1);
        }
        inizioTocco = null;
    });
}

function mostra() {
    const voce = foto[indice];
    immagine.src = voce.href;
    immagine.alt = voce.alt;
    didascalia.textContent = voce.didascalia;
    contatore.textContent = foto.length > 1 ? `${indice + 1} di ${foto.length}` : '';
    scarica.href = voce.scarica || voce.href;
    overlay.querySelectorAll('.pa-galleria-freccia').forEach((b) => { b.hidden = foto.length < 2; });
    // Precarica la successiva: scorrere è immediato anche su rete lenta.
    if (foto.length > 1) new Image().src = foto[(indice + 1) % foto.length].href;
}

function vai(passo) {
    if (foto.length < 2) return;
    indice = (indice + passo + foto.length) % foto.length;
    mostra();
}

function apri(gruppo, link) {
    if (!overlay) crea();
    const visti = new Set();
    foto = [...document.querySelectorAll(`a[data-galleria="${CSS.escape(gruppo)}"]`)]
        .filter((a) => !visti.has(a.href) && visti.add(a.href))
        .map((a) => ({
            href: a.href,
            scarica: a.dataset.scarica || '',
            didascalia: a.dataset.didascalia || '',
            alt: a.querySelector('img')?.alt || '',
        }));
    indice = Math.max(0, foto.findIndex((f) => f.href === link.href));
    ultimoFocus = document.activeElement;
    mostra();
    overlay.hidden = false;
    document.documentElement.classList.add('pa-galleria-aperta');
    overlay.querySelector('[data-azione="chiudi"]').focus();
}

function chiudi() {
    overlay.hidden = true;
    immagine.removeAttribute('src');
    document.documentElement.classList.remove('pa-galleria-aperta');
    ultimoFocus?.focus?.();
}

export function avviaGalleria() {
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        const link = e.target.closest('a[data-galleria]');
        if (!link) return;
        e.preventDefault();
        apri(link.dataset.galleria, link);
    });
    document.addEventListener('keydown', (e) => {
        if (!overlay || overlay.hidden) return;
        if (e.key === 'Escape') chiudi();
        else if (e.key === 'ArrowLeft') vai(-1);
        else if (e.key === 'ArrowRight') vai(1);
        else if (e.key === 'Tab') {
            // Focus dentro il carosello finché è aperto.
            const tasti = [...overlay.querySelectorAll('a[href], button:not([hidden])')];
            const primo = tasti[0];
            const ultimo = tasti[tasti.length - 1];
            if (e.shiftKey && document.activeElement === primo) { e.preventDefault(); ultimo.focus(); }
            else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primo.focus(); }
        }
    });
}
