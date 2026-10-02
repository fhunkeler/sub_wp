# Polices du thème

**Installées.** Charte d'octobre 2026 (refonte d'après la maquette « Subalcatel – Site web ») :

| Fichier | Police | Graisse | Licence |
|---|---|---|---|
| `bricolage-grotesque-regular.woff` | Bricolage Grotesque (titres) | 400 | SIL OFL 1.1 — `OFL-BricolageGrotesque.txt` |
| `bricolage-grotesque-bold.woff` | Bricolage Grotesque (titres) | 700 | idem |
| `instrument-sans-regular.woff` | Instrument Sans (texte) | 400 | SIL OFL 1.1 — `OFL-InstrumentSans.txt` |
| `instrument-sans-italic.woff` | Instrument Sans (texte) | 400 italique | idem |
| `instrument-sans-bold.woff` | Instrument Sans (texte) | 700 | idem |

Environ **150 Ko** au total, sous-ensemble latin (Œ/œ, €, guillemets et tirets compris), chargés une fois puis mis en cache.

Les déclarations `@font-face` sont produites par `inc/fonts.php` (site et éditeur) et `inc/connexion.php` (écran de connexion), uniquement pour les fichiers présents.

## Pourquoi Instrument Sans et pas Figtree

La maquette utilisait Figtree pour le texte. Elle n'a pas pu être récupérée lors de la refonte ; Instrument Sans, grotesque de même famille d'esprit (ouverte, ronde, très lisible en petit corps), la remplace. Pour revenir à Figtree : déposer ses fichiers ici et changer les noms dans `subalcatel_font_files()` et `$definitions` (`inc/fonts.php`), ainsi que dans `inc/connexion.php` et `assets/css/login.css`.

## Format

WOFF (compression zlib) et non WOFF2 : la chaîne de conversion disponible n'avait pas de compresseur Brotli. Le gain du WOFF2 serait d'environ 30 %. Pour convertir :

```bash
pip install fonttools brotli
for f in *.woff; do pyftsubset "$f" --unicodes='*' --flavor=woff2 --output-file="${f%.woff}.woff2"; done
```

puis remplacer l'extension dans `inc/fonts.php` et le `format('woff')` de `inc/connexion.php`.

## Anciennes polices

`montserrat-variable.woff2` et `lora-variable.woff2` (charte précédente) ne sont plus chargées ; elles peuvent être supprimées avec leurs fichiers `OFL-*.txt`.

## Pourquoi auto-héberger

Le thème ne charge **jamais** les polices depuis `fonts.googleapis.com` : un tel appel transmet l'adresse IP de chaque visiteur à Google sans base légale (RGPD — jugement allemand de janvier 2022, analyse reprise par la CNIL).

## Licences

Les deux polices sont sous **SIL Open Font License 1.1**, qui autorise l'auto-hébergement à condition que la licence accompagne les fichiers. **Ne pas supprimer** les `OFL-*.txt` correspondants.
