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
- Le vulnerabilità nelle dipendenze sono già monitorate automaticamente (Dependabot security updates e aggiornamenti giornalieri, `composer audit`/`npm audit` e Trivy su dipendenze e immagini Docker a ogni PR, Trivy bloccante prima di ogni pubblicazione; risultati nella tab [Security](https://github.com/Comune-di-Montesilvano/ProntoPA/security)).

## Verifica delle immagini

Le immagini pubblicate su GHCR sono firmate con [cosign](https://docs.sigstore.dev/) (keyless, identità OIDC del workflow di rilascio) e hanno SBOM e provenance SLSA allegate. Prima del deploy:

```bash
cosign verify \
  --certificate-identity-regexp 'https://github.com/Comune-di-Montesilvano/ProntoPA/.github/workflows/release.yml@.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/comune-di-montesilvano/pronto-pa:latest
```
