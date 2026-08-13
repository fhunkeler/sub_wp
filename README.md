# Sub Alcatel — code du site

Extension et thème du club de plongée Sub Alcatel, pour WordPress 6.5+ / PHP 8.1+.

| Dossier | Rôle |
|---|---|
| `plugins/subalcatel-club/` | Adhésions, événements, documents, droits. Toute la règle métier. |
| `themes/subalcatel/` | Thème bloc. Présentation seule, aucune règle métier. |
| `build-packages.py` | Fabrique les archives d'installation. |

Ce dépôt contient **le code, et rien d'autre** : ni base de données, ni médias,
ni sauvegardes, ni document d'audit. Le `.gitignore` fonctionne par liste
blanche pour que cela le reste.

## Construire les archives

```
python3 build-packages.py dist
```

Le script produit `dist/subalcatel-club-<version>.zip` et
`dist/subalcatel-<version>.zip`, prêts pour *Extensions → Ajouter → Téléverser*.
Il refuse de construire si un identifiant figure dans les fichiers, et exclut
`tests/` de l'archive livrée.

## Publier une version

La version se déclare dans l'en-tête — `subalcatel-club.php` pour l'extension,
`style.css` pour le thème — puis la balise la reprend :

```
git tag plugin-0.13.0 && git push origin plugin-0.13.0
git tag theme-1.4.0   && git push origin theme-1.4.0
```

L'action GitHub construit l'archive et l'attache à la release. Si la balise ne
correspond pas à la version déclarée, la publication échoue plutôt que de livrer
une archive que les sites téléchargeraient en boucle.

## Mises à jour des sites

Les sites qui font tourner ce code voient les nouvelles versions apparaître dans
*Tableau de bord → Mises à jour*, comme n'importe quelle extension. **Rien ne
s'installe tout seul** : le bureau clique. Voir `src/Setup/Updater.php` pour le
détail, et pour les deux filtres qui permettent d'en décider autrement.

Dépôt privé — ajouter dans `wp-config.php` un jeton à portée `contents: read`
sur ce seul dépôt :

```php
define('SUBALCATEL_GITHUB_TOKEN', '…');
```

Dépôt public : ne rien définir, le téléchargement est anonyme.
