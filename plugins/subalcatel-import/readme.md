# subalcatel-import — reprise de l'ancien site Joomla

Outils de migration de Sub Alcatel, sortis de l'extension du club : ils ne
servent qu'une fois, à un seul club, et n'ont pas leur place dans le code livré
à chaque site.

- `src/` — la reprise elle-même (`Subalcatel\Import\…`) : comptes, adhésions,
  articles, images, nettoyage du contenu hérité.
- `tools/` — les scripts `wp eval-file` : reprise (`import-run.php`,
  `import-medias.php`, `refix-articles.php`, `trier-articles.php`,
  `vignettes-articles.php`) et préparation de l'export de production
  (`export-production.php`, `export-user-sync.php`, `list-kept-media.php`).
- `tests/smoke-import.php` — étanchéité de la source héritée, nettoyage du
  contenu, reprise rejouable.

## Dépendance

L'extension `subalcatel-club` doit être active : la reprise crée des comptes,
des niveaux et des dossiers d'adhésion avec ses classes. L'en-tête le déclare
(`Requires Plugins`), et WordPress refuse d'activer celle-ci sans elle.

Les outils fonctionnent **sans activer** cette extension : `tools/bootstrap.php`
charge ses classes. Elle n'a donc pas à être installée sur le site en service.

## Lancer un outil

```bash
docker exec sub_demo_cli wp --allow-root eval-file \
  wp-content/plugins/subalcatel-import/tools/import-run.php
```

La connexion à la base Joomla se règle par des options WordPress, jamais dans
un fichier : voir l'en-tête de `tools/bootstrap.php`.

## Ce qui n'en fait pas partie

Cette extension n'est ni versionnée par `version.py`, ni publiée en release, ni
mise à jour par `Updater`. `build-packages.py` sait en faire une archive pour
l'installer à la main, si une reprise devait être rejouée sur un autre serveur.
