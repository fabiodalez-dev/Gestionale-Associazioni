# Design

Sistema visivo "Stripe-grade" per gestionale associativo. Registro: product. Tema chiaro di default, scuro via toggle (`data-bs-theme="dark"` + token override). Implementato in `public/assets/css/design-system.css` (token + re-theme Bootstrap 5.3), caricato per ULTIMO (dopo Bootstrap e `style.css`): ridefinisce le variabili legacy di `style.css` e vince sulle regole precedenti.

## Color

Strategia: **Restrained**. Neutri tintati verso il hue dell'arancio brand (OKLCH hue ≈ 55), accento ≤10% della superficie. Mai #000/#fff puri.

### Token (light)
- `--ds-bg`: oklch(0.982 0.004 75) — fondo pagina, caldo appena percettibile
- `--ds-surface`: oklch(0.998 0.002 75) — card, tabelle, form
- `--ds-surface-2`: oklch(0.965 0.005 75) — sidebar, thead, righe hover
- `--ds-border`: oklch(0.91 0.006 70) — bordi 1px ovunque
- `--ds-text`: oklch(0.25 0.012 60)
- `--ds-text-muted`: oklch(0.52 0.014 60)
- `--ds-accent`: #FF7B11 = oklch(0.72 0.185 55) — SOLO azioni primarie, selezione, focus
- `--ds-accent-hover`: oklch(0.67 0.185 55)
- `--ds-accent-subtle`: oklch(0.95 0.035 65) — sfondo selezione/badge accent
- Semantici: success oklch(0.62 0.13 150), danger oklch(0.58 0.18 25), warning oklch(0.74 0.13 80), info oklch(0.60 0.10 230). Versione `-subtle` per badge/alert (tinta al 10-12%).

### Token (dark)
- `--ds-bg`: oklch(0.165 0.008 60) · `--ds-surface`: oklch(0.205 0.009 60) · `--ds-surface-2`: oklch(0.24 0.010 60)
- `--ds-border`: oklch(0.30 0.010 60) · `--ds-text`: oklch(0.93 0.005 75) · `--ds-text-muted`: oklch(0.66 0.010 70)
- Accent invariato (#FF7B11 regge su scuro); semantici alzati di lightness (+0.08) e abbassati di chroma.

## Typography

- Famiglia unica: **Inter** (woff2 variabile locale in `assets/fonts/`, fallback `system-ui, -apple-system, "Segoe UI", sans-serif`). Niente display font.
- Scala fissa rem, ratio 1.2: 12.5 / 13.5 / 14 (body) / 16 / 19 / 23 / 28.
- Pesi: 400 body, 500 label/nav, 600 heading e valori chiave. Mai 700+.
- Dati numerici: `font-variant-numeric: tabular-nums` su tabelle e metriche.
- Heading pagina: 23px/600 con sottotitolo muted 13.5px. Letter-spacing -0.01em sui titoli.

## Layout

- Shell: sidebar fissa 248px (surface-2, bordo destro 1px), contenuto max 1320px con padding 32px; mobile = sidebar off-canvas.
- Spaziatura: scala 4/8/12/16/24/32/48; sezioni di pagina separate da 32px, blocchi interni 16px.
- Page header standard: titolo + sottotitolo a sinistra, azione primaria a destra, bordo inferiore 1px, niente card wrapper.
- Tabelle: senza card contenitore quando sono il contenuto principale; thead surface-2 con label 12.5px/500 uppercase tracking 0.04em; righe 44px; hover surface-2; bordo orizzontale 1px solo.

## Components

- **Bottoni**: radius 8px, 1px bordo. Primario = accent pieno, testo bianco; secondario = surface + bordo; ghost per azioni di riga. Altezza 36px (32px nelle tabelle). Stati: hover (-5% lightness), focus ring 2px accent al 35%, disabled 45% opacità.
- **Badge stato**: pillola subtle-bg + testo semantico scuro, 12.5px/500, pallino 6px a sinistra. Mai badge pieni saturi.
- **Form**: input 38px, radius 8px, bordo 1px, focus = bordo accent + ring 3px accent al 20%; label 13px/500 sopra il campo; help text muted 12.5px.
- **Card**: solo quando raggruppano davvero (widget dashboard, dettaglio): radius 12px, bordo 1px, shadow `0 1px 2px oklch(0 0 0 / .05)`. Mai card annidate, mai side-stripe.
- **Sidebar**: voci 38px, radius 6px, icona 16px; voce attiva = accent-subtle bg + testo accent + icona accent; gruppi con label 11.5px uppercase muted.
- **Alert/flash**: bordo pieno 1px semantico + bg subtle, icona inline, niente barre laterali.
- **Empty state**: icona outline 28px muted, frase che insegna l'azione, bottone secondario.

## Motion

- Transizioni 150ms ease-out su colori/ombre; 200ms cubic-bezier(0.22,1,0.36,1) su sidebar off-canvas e dropdown.
- Nessuna animazione su layout, nessuna sequenza al load. `prefers-reduced-motion: reduce` azzera tutto.

## Theme toggle

Bottone in fondo alla sidebar (icona sole/luna), persistenza `localStorage('theme')`, applicato su `<html data-bs-theme>` prima del paint (snippet inline nel `<head>` per evitare flash).
