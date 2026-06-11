# Product

## Register

product

## Users

Operatori e amministratori di associazioni italiane (volontari e impiegati, non tecnici), che gestiscono soci, tessere, quote, eventi e comunicazioni durante l'orario d'ufficio, su monitor spesso mediocri e con luce ambiente diurna. Il super admin (gestore della piattaforma) amministra più associazioni. I soci accedono saltuariamente a un'area dedicata da mobile per tessera, quote ed eventi.

Il lavoro è ripetitivo e data-driven: cercare un socio, rinnovare una tessera, registrare una quota, inviare una comunicazione. La velocità di scansione delle tabelle e la chiarezza degli stati contano più di qualsiasi decorazione.

## Product Purpose

CRM gestionale multi-tenant per associazioni: anagrafica soci, tesseramento con QR, quote e pagamenti online, eventi con check-in, email massive, documenti e verbali. Successo = l'operatore completa il task senza pensare all'interfaccia e si fida dei dati che vede.

## Brand Personality

Preciso, affidabile, sobrio. La qualità percepita di uno strumento finanziario (riferimento: Stripe Dashboard), con un solo gesto di calore: l'arancio brand #FF7B11 usato chirurgicamente. Tre parole: chirurgico, calmo, concreto.

## Anti-references

- Il look "Bootstrap default": card grigie identiche, badge primari ovunque, blu #0d6efd.
- Dashboard SaaS con hero-metric a gradiente e icone giganti colorate.
- Gestionali italiani anni 2010: tabelle zebrate pesanti, bottoni lucidi, troppi colori di stato.
- Gradient decorativi, glassmorphism, ombre profonde.

## Design Principles

1. **La tabella è il prodotto.** Ottimizzare prima la lettura dei dati: densità controllata, numeri tabulari, stati scansionabili.
2. **Un solo accento.** L'arancio segnala azione primaria e selezione corrente, mai decorazione. Tutto il resto è neutro tintato.
3. **Familiarità guadagnata.** Pattern standard (sidebar, breadcrumb, form) eseguiti con precisione assoluta, nessuna invenzione di affordance.
4. **Gli stati parlano.** Ogni entità (socio, tessera, quota) ha stati semantici coerenti e identici in tutta l'app.
5. **Il tema segue l'ambiente.** Default chiaro per l'ufficio diurno; scuro disponibile via toggle, mai imposto.

## Accessibility & Inclusion

Contrasto WCAG AA minimo su testo e stati; focus visibile su ogni controllo; `prefers-reduced-motion` rispettato; UI utilizzabile da utenti non tecnici di ogni età (target 50+ frequente nelle associazioni: niente testi sotto 13px, hit target ≥40px).
