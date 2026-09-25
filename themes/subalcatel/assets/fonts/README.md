# Polices du thème

**Installées.** Le thème dispose des deux polices de la charte.

| Fichier | Police | Sous-ensemble | Taille | Licence |
|---|---|---|---|---|
| `montserrat-variable.woff2` | Montserrat, variable 100–900 | latin | 37,1 Ko | SIL OFL 1.1 — `OFL-Montserrat.txt` |
| `lora-variable.woff2` | Lora, variable 400–700 | latin | 36,9 Ko | SIL OFL 1.1 — `OFL-Lora.txt` |

Soit **74 Ko** au total, chargés une fois puis mis en cache par le navigateur.

## Provenance

Récupérées le 25/09/2026 depuis `fonts.gstatic.com`, dans leur version déjà sous-ensemblée au latin par Google — ce qui évite toute étape de conversion locale. Chaque bloc `@font-face` renvoyé par Google déclare `font-weight: 100 900` (Montserrat) et `font-weight: 400 700` (Lora) — une plage, pas une valeur unique : c'est la signature d'une police **variable**, confirmée ici sans ambiguïté (`theme.json` déclare `fontWeight: "400 700"`, la plage réellement utilisée par le thème).

Remplace la précédente charte (`outfit-variable.woff2` + `inter-variable.woff2`), écartée après comparatif avec le bureau — voir la décision dans l'historique du projet.

Le sous-ensemble `latin` de Google couvre `U+0000-00FF` **et** `U+0152-0153` : les ligatures Œ et œ sont incluses, le français est donc complet. Le sous-ensemble `latin-ext`, deux fois plus lourd, n'apporte rien ici.

## Licences

Les deux polices sont sous **SIL Open Font License 1.1**, qui autorise explicitement l'auto-hébergement et la redistribution. Elle impose en contrepartie que la notice de copyright et le texte de licence accompagnent les fichiers : c'est le rôle de `OFL-Montserrat.txt` et `OFL-Lora.txt`, à conserver dans ce dossier. **Ne pas les supprimer** — un site qui sert des `.woff2` redistribue les polices au sens de la licence.

## Pourquoi auto-héberger

Le thème ne charge **jamais** les polices depuis `fonts.googleapis.com` à l'exécution. Un tel appel transmet l'adresse IP de chaque visiteur à Google sans base légale, ce qui constitue une violation du RGPD — un tribunal allemand a condamné un éditeur de site sur ce fondement en janvier 2022, et la CNIL a repris la même analyse. Le téléchargement ci-dessus est un acte ponctuel de développement ; il n'expose aucun visiteur.

## Mettre à jour

Google incrémente le numéro de version dans l'URL (`/montserrat/v31/…`) à chaque révision. Pour récupérer l'URL courante, l'User-Agent doit ressembler à un vrai Chrome de bureau complet (une chaîne tronquée du type `Chrome/120.0` seul suffit rarement : Google répond alors des `.ttf` statiques par graisse, pas un `.woff2` variable) :

```bash
curl -sS -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=Montserrat:wght@100..900&display=swap"
```

La réponse contient un bloc `@font-face` par sous-ensemble, précédé d'un commentaire indiquant lequel (`/* latin */`, `/* cyrillic */`, etc.) — et confirmée variable si `font-weight` y est une plage (`100 900`) et non une valeur unique. Prendre l'URL du bloc `/* latin */`.

## Si les fichiers venaient à disparaître

Le thème continue de fonctionner : `inc/fonts.php` vérifie leur présence et n'injecte les `@font-face` que si les fichiers existent, ce qui évite une requête 404 par police et par page. Le site utilise alors la pile système (`system-ui`, avec repli `serif` pour le texte puisque Lora en est une), reste parfaitement lisible, et un avertissement s'affiche dans l'administration.
