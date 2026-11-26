// Foto dei form (x-pa.foto, 1.0.20). Il reverse proxy dell'ente rifiuta le richieste oltre 1 MB:
// ogni foto viene ridotta nel browser e caricata da sola, a blocchi da 512 KB, appena scelta
// (AllegatiTemporanei lato server). Il form invia solo gli id in `allegati_caricati[]`.

const LATO_MASSIMO = 2048;
const QUALITA = 0.82;
const BLOCCO = 512 * 1024;
const TENTATIVI = 3;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** Foto ridotta (lato lungo 2048 px, JPEG) se serve e se pesa meno; altrimenti il file com'è. */
export async function comprimi(file, mimeAmmessi) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !mimeAmmessi.includes('image/jpeg')) return file;
    let immagine;
    try {
        immagine = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (e) {
        return file;
    }
    const scala = Math.min(1, LATO_MASSIMO / Math.max(immagine.width, immagine.height));
    if (scala === 1 && file.size <= BLOCCO && file.type === 'image/jpeg') {
        immagine.close?.();
        return file;
    }
    const tela = document.createElement('canvas');
    tela.width = Math.round(immagine.width * scala);
    tela.height = Math.round(immagine.height * scala);
    const ctx = tela.getContext('2d');
    ctx.fillStyle = '#fff'; // PNG trasparenti: sfondo bianco, non nero
    ctx.fillRect(0, 0, tela.width, tela.height);
    ctx.drawImage(immagine, 0, 0, tela.width, tela.height);
    immagine.close?.();
    const blob = await new Promise((ok) => tela.toBlob(ok, 'image/jpeg', QUALITA));
    if (!blob || (blob.size >= file.size && file.type === 'image/jpeg')) return file;
    const nome = (file.name.replace(/\.[^.]+$/, '') || 'foto') + '.jpg';
    return new File([blob], nome, { type: 'image/jpeg', lastModified: file.lastModified });
}

function nuovoId() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
        (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
}

/** Carica il file a blocchi; restituisce l'id da inviare col form. */
export async function carica(file, url, progresso) {
    const id = nuovoId();
    const totale = Math.max(1, Math.ceil(file.size / BLOCCO));
    for (let i = 0; i < totale; i++) {
        const dati = new FormData();
        dati.append('id', id);
        dati.append('indice', i);
        dati.append('totale', totale);
        dati.append('nome', file.name);
        dati.append('blocco', file.slice(i * BLOCCO, (i + 1) * BLOCCO), 'blocco');
        for (let tentativo = 1; ; tentativo++) {
            let r;
            try {
                r = await fetch(url, {
                    method: 'POST', body: dati, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                });
            } catch (e) {
                // Rete instabile (telefono): riprova lo stesso blocco.
                if (tentativo < TENTATIVI) { await new Promise((ok) => setTimeout(ok, 1000 * tentativo)); continue; }
                throw new Error('Connessione assente: riprova.');
            }
            if (r.ok) break;
            const esito = await r.json().catch(() => ({}));
            if (r.status === 419) throw new Error('Sessione scaduta: ricarica la pagina.');
            throw new Error(esito.errore ?? 'Caricamento non riuscito: riprova.');
        }
        progresso?.(Math.round(((i + 1) / totale) * 100));
    }
    return id;
}

export function avviaFoto(Alpine) {
    Alpine.data('paFoto', (cfg) => ({
        foto: [], // { chiave, nome, anteprima, stato: 'caricamento'|'ok'|'errore', id, errore, percentuale, file }
        avviso: '',
        inAttesa: null, // pulsante che ha inviato il form mentre le foto si caricavano

        init() {
            const form = this.$el.closest('form');
            if (!form) return;
            form.addEventListener('formdata', (e) => {
                if (this.$refs.vero.disabled) return;
                this.foto.filter((f) => f.stato === 'ok').forEach((f) => e.formData.append(cfg.campo, f.id));
            });
            form.addEventListener('submit', (e) => {
                if (this.$refs.vero.disabled) return;
                if (this.foto.some((f) => f.stato === 'errore')) {
                    e.preventDefault();
                    this.avviso = 'Una foto non è stata caricata: riprova o toglila.';
                } else if (this.foto.some((f) => f.stato === 'caricamento')) {
                    e.preventDefault();
                    this.inAttesa = e.submitter ?? null;
                    this.avviso = 'Invio appena finisce il caricamento delle foto…';
                }
            });
        },

        get caricando() { return this.foto.some((f) => f.stato === 'caricamento'); },

        aggiungi(lista) {
            this.avviso = '';
            for (const file of Array.from(lista)) {
                if (this.foto.length >= cfg.massimo) {
                    this.avviso = `Puoi allegare al massimo ${cfg.massimo} foto.`;
                    break;
                }
                const voce = Alpine.reactive({
                    chiave: nuovoId(), nome: file.name, anteprima: '', stato: 'caricamento', id: null, errore: '', percentuale: 0, file,
                });
                this.foto.push(voce);
                this.prepara(voce);
            }
            this.cambiate();
        },

        async prepara(voce) {
            try {
                voce.file = await comprimi(voce.file, cfg.mime);
            } catch (e) { /* resta l'originale */ }
            // data: e non blob: (la CSP ammette solo data: per le immagini locali).
            if (voce.file.type.startsWith('image/')) {
                const r = new FileReader();
                r.onload = () => { voce.anteprima = r.result; };
                r.readAsDataURL(voce.file);
            }
            await this.invia(voce);
        },

        async invia(voce) {
            voce.stato = 'caricamento';
            voce.errore = '';
            voce.percentuale = 0;
            try {
                voce.id = await carica(voce.file, cfg.url, (p) => { voce.percentuale = p; });
                if (!this.foto.includes(voce)) { this.scarta(voce.id); return; } // tolta durante il caricamento
                voce.stato = 'ok';
            } catch (e) {
                voce.stato = 'errore';
                voce.errore = e.message;
            }
            this.forseInvia();
        },

        riprova(voce) {
            this.avviso = '';
            this.invia(voce);
        },

        togli(i) {
            const [voce] = this.foto.splice(i, 1);
            if (voce?.stato === 'ok') this.scarta(voce.id);
            this.avviso = '';
            this.cambiate();
            this.forseInvia();
        },

        scarta(id) {
            fetch(cfg.url + '/' + encodeURIComponent(id), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            }).catch(() => {});
        },

        forseInvia() {
            if (this.inAttesa === null || this.caricando) return;
            const pulsante = this.inAttesa;
            this.inAttesa = null;
            if (this.foto.some((f) => f.stato === 'errore')) {
                this.avviso = 'Una foto non è stata caricata: riprova o toglila.';
                return;
            }
            this.avviso = '';
            const form = this.$el.closest('form');
            pulsante && pulsante.form === form ? form.requestSubmit(pulsante) : form.requestSubmit();
        },

        cambiate() {
            this.$dispatch('foto-cambiate', this.foto.length);
        },
    }));
}
