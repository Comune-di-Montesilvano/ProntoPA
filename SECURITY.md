# Security Policy

## Segnalazione di una vulnerabilità

Se scopri una vulnerabilità di sicurezza in ProntoPA, **non aprire una issue pubblica**.

Segnala privatamente via [GitHub Security Advisories](https://github.com/Comune-di-Montesilvano/ProntoPA/security/advisories/new) — visibile solo ai maintainer finché non viene risolta e pubblicata.

In alternativa, contatta l'amministrazione del Comune di Montesilvano tramite i canali istituzionali indicati su [comune.montesilvano.pe.it](https://www.comune.montesilvano.pe.it).

## Versioni supportate

Solo l'ultima versione taggata (`vX.Y.Z`) riceve fix di sicurezza. Nessun supporto a versioni precedenti.

## Cosa aspettarsi

- Conferma di ricezione entro qualche giorno lavorativo.
- Nessun bug bounty: il progetto è software libero per la PA, non un prodotto commerciale.
- Le vulnerabilità nelle dipendenze sono già monitorate automaticamente (Dependabot security updates + Trivy su dipendenze composer/npm ad ogni push/PR + Trivy sulle immagini Docker ad ogni release, risultati nella tab [Security](https://github.com/Comune-di-Montesilvano/ProntoPA/security)).
