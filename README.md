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

## Travailler sur le code

`main` n'accepte pas de commit direct : le travail passe par une branche, puis
par une pull request. C'est la fusion qui publie, et une version publiée ne se
reprend pas.

```
git switch -c le-sujet-de-la-modification
… éditer, commiter …
git push -u origin HEAD
```

Après un premier clone, activer les garde-fous locaux :

```
git config core.hooksPath .githooks
```

Ils refusent un commit ou un push sur `main` avant qu'il ne parte. Ce ne sont
que des garde-fous — `--no-verify` les contourne. L'interdiction qui tient est
la règle posée sur GitHub, dans *Settings → Rules*.

## Contrôles d'une demande

Chaque pull request déclenche `.github/workflows/controles.yml` :

| Contrôle | Ce qu'il garde |
|---|---|
| Syntaxe et archives | `php -l` sur tous les fichiers, puis `build-packages.py` à blanc — c'est lui qui refuse d'empaqueter un identifiant, et il ne tournait jusqu'ici qu'à la publication |
| Suites de fumée | Les 28 suites de `tests/`, sur un WordPress neuf installé comme le club installe les siens |
| Numéro de version | Un rappel, **jamais bloquant**, quand le code livré bouge sans que son numéro suive |

Le WordPress d'intégration est installé de zéro à chaque exécution : fuseau
`Europe/Paris`, permaliens en `/%postname%/`, semaine du lundi, langue française.
Ce n'est pas de la décoration — le fuseau décide des dates d'événements, les
permaliens des adresses que le thème compare, et sans le pack `fr_FR` les
courriels du club annoncent leurs échéances en anglais.

Une base neuve à chaque fois vaut mieux qu'une base de travail : celle d'un poste
de développement dérive avec les branches qu'on y a installées, et fait échouer
des suites que le code ne casse pas.

**Ces contrôles ne protègent `main` que s'ils sont déclarés requis** dans
*Settings → Rules*. Un contrôle qu'on peut ignorer finit toujours ignoré.

## Publier une version

Il n'y a pas de balise à poser à la main. **La version fait foi** : elle se
déclare dans l'en-tête — `subalcatel-club.php` pour l'extension, `style.css`
pour le thème — dans la branche, avec la modification qu'elle accompagne.

À la fusion dans `main`, l'action lit les deux en-têtes et publie ce qui n'a pas
encore de release : archive construite, balise posée, notes générées. Une fusion
qui ne touche pas au numéro ne publie rien — sans quoi la moindre correction de
faute de frappe enverrait une mise à jour à installer aux sites.

Publier l'extension seule, ou le thème seul, revient donc à ne monter qu'un des
deux numéros.

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
