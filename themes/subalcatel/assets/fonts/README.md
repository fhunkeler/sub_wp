# Polices du thème

**Installées.** Le thème dispose des deux polices de la charte.

| Fichier | Police | Sous-ensemble | Taille | Licence |
|---|---|---|---|---|
| `outfit-variable.woff2` | Outfit, variable 100–900 | latin | 31,5 Ko | SIL OFL 1.1 — `OFL-Outfit.txt` |
| `inter-variable.woff2` | Inter, variable 100–900 | latin | 47,1 Ko | SIL OFL 1.1 — `OFL-Inter.txt` |

Soit **78,6 Ko** au total, chargés une fois puis mis en cache par le navigateur.

## Provenance

Récupérées le 04/08/2026 depuis `fonts.gstatic.com`, dans leur version déjà sous-ensemblée au latin par Google — ce qui évite toute étape de conversion locale. Vérifié : signature WOFF2 valide, table `fvar` présente dans les deux fichiers, donc bien des polices **variables** (l'axe de graisse est nécessaire, `theme.json` déclare `fontWeight: "400 700"`).

Le sous-ensemble `latin` de Google couvre `U+0000-00FF` **et** `U+0152-0153` : les ligatures Œ et œ sont incluses, le français est donc complet. Le sous-ensemble `latin-ext`, deux fois plus lourd, n'apporte rien ici.

## Licences

Les deux polices sont sous **SIL Open Font License 1.1**, qui autorise explicitement l'auto-hébergement et la redistribution. Elle impose en contrepartie que la notice de copyright et le texte de licence accompagnent les fichiers : c'est le rôle de `OFL-Outfit.txt` et `OFL-Inter.txt`, à conserver dans ce dossier. **Ne pas les supprimer** — un site qui sert des `.woff2` redistribue les polices au sens de la licence.

## Pourquoi auto-héberger

Le thème ne charge **jamais** les polices depuis `fonts.googleapis.com` à l'exécution. Un tel appel transmet l'adresse IP de chaque visiteur à Google sans base légale, ce qui constitue une violation du RGPD — un tribunal allemand a condamné un éditeur de site sur ce fondement en janvier 2022, et la CNIL a repris la même analyse. Le téléchargement ci-dessus est un acte ponctuel de développement ; il n'expose aucun visiteur.

## Mettre à jour

Google incrémente le numéro de version dans l'URL (`/outfit/v15/…`) à chaque révision. Pour récupérer l'URL courante :

```bash
curl -sS -A "Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0" "https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap"
```

La réponse contient un bloc `@font-face` par sous-ensemble, précédé d'un commentaire indiquant lequel. Prendre l'URL du bloc `/* latin */`.

## Si les fichiers venaient à disparaître

Le thème continue de fonctionner : `inc/fonts.php` vérifie leur présence et n'injecte les `@font-face` que si les fichiers existent, ce qui évite une requête 404 par police et par page. Le site utilise alors la pile système (`system-ui`), reste parfaitement lisible, et un avertissement s'affiche dans l'administration.
