# Promemoria di inattività, archiviazione e sollecito del segnalante — design

Stato: approvato in chat · 2026-10-26

## Perché
Segnalazioni ferme da settimane restano aperte senza che nessuno le muova. Affidare una
segnalazione (ditta, operaio) serve a sgravare il comune: deve essere chi ce l'ha in carico a
tenerla viva, non il comune a rincorrere. Il segnalante oggi non può sollecitare.

## Decisioni del committente
1. Superata una soglia di inattività che dipende dalla priorità, chi ha aperto e chi ha in carico
   ricevono un avviso: se nessuno fa nulla la segnalazione verrà archiviata. Stesso avviso ai supervisori.
2. Vale in tutti gli stati aperti, anche quando è affidata a ditta o operaio ("affidare serve a sgravare il comune").
3. Il segnalatore può sollecitare.

## Comportamento
- **Attività** = nota, cambio di stato, allegato, adesione (anche il sollecito, che è una nota).
  Ultima attività = la più recente tra queste e la data di segnalazione.
- **Soglie** per regola SLA (stessa ricerca di `SlaConfigurazione::applicabileA`):
  `giorni_avviso_inattivita` e `giorni_archiviazione_inattivita`, in giorni lavorativi
  (`CalendarioUfficio`). Vuote = funzione spenta per quella regola; archiviazione vuota = solo avviso.
  *(scelta del progettista)* Default vuoti dopo l'aggiornamento: l'admin le attiva in Admin → SLA,
  così il primo avvio non manda centinaia di email né archivia in blocco. Valori suggeriti nel form:
  Critica 2+5, Alta 5+10, Media 15+15, Bassa 30+15.
- **Avviso**: ultima attività + soglia avviso superata → `segnalazioni.avviso_inattivita_at = now`.
  Email per segnalazione a chi ha aperto e a chi ha in carico (ditta: delegati + email aziendale;
  operaio/caposquadra; nello stato Nuova/In carico il gestore assegnato se c'è). Ai supervisori un
  solo riepilogo per esecuzione. Sospese escluse.
- **Archiviazione**: avviso + soglia archiviazione superati senza attività successiva → stato
  Archiviata, nota "Archiviata per inattività" (autore sistema), email a chi ha aperto e a chi aveva
  in carico, riepilogo ai supervisori. Si può riaprire come oggi.
- Qualsiasi attività dopo l'avviso lo azzera.
- Comando giornaliero `segnalazioni:inattivita` (07:15, dopo `sla:check`).
- **Scheda**: con avviso attivo, riquadro in tutte le letture "Ferma da N giorni: se nessuno la
  aggiorna entro il GG/MM verrà archiviata".
- **Sollecito del segnalante**: chi ha aperto la segnalazione può sollecitare quando è in ritardo
  (scadenza superata) o ha ricevuto l'avviso di inattività, al massimo uno ogni 3 giorni lavorativi;
  non sulle sospese. Va a chi ha in carico (ditta/operaio), o al comune se non è affidata
  (`DestinatariComune`). Nota visibile al segnalante e alla ditta.

## Rilascio
Patch 0.9.3, CHANGELOG + publiccode nella stessa PR. Nota operativa: impostare le soglie in Admin → SLA.
