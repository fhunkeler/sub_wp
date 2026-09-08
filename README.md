# Sub Alcatel — code du site

Extension et thème du club de plongée Sub Alcatel, pour WordPress 6.5+ / PHP 8.1+.

| Dossier | Rôle |
|---|---|
| `plugins/subalcatel-club/` | Adhésions, événements, documents, droits. Toute la règle métier. |
| `themes/subalcatel/` | Thème bloc. Présentation seule, aucune règle métier. |
| `build-packages.py` | Fabrique les archives d'installation. |
| `version.py` | Calcule le numéro de la prochaine version, et contrôle les messages de commit. |

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

Ils refusent un commit ou un push sur `main`, et un message de commit hors
convention, avant qu'ils ne partent. Ce ne sont que des garde-fous —
`--no-verify` les contourne. L'interdiction qui tient est la règle posée sur
GitHub, dans *Settings → Rules*.

## Contrôles d'une demande

Chaque pull request déclenche `.github/workflows/controles.yml` :

| Contrôle | Ce qu'il garde |
|---|---|
| Syntaxe et archives | `php -l` sur tous les fichiers, puis `build-packages.py` à blanc — c'est lui qui refuse d'empaqueter un identifiant, et il ne tournait jusqu'ici qu'à la publication |
| Suites de fumée | Les 28 suites de `tests/`, sur un WordPress neuf installé comme le club installe les siens |
| Convention des messages | Chaque sujet de commit entre dans *Conventional Commits* — **bloquant**, puisque le numéro de la prochaine version s'en déduit. La demande affiche aussi ce qu'elle publiera |

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

Une réserve, en posant cette règle : la publication écrit sur `main` — c'est
elle qui y pose le numéro calculé. Une règle « exiger une pull request » sans
exception pour `github-actions[bot]` ferait rejeter ce push, et plus rien ne se
publierait. Exiger les contrôles, oui ; exiger la demande, seulement en
autorisant le robot à passer outre.

## Messages de commit

Le sujet d'un commit entre dans la convention *Conventional Commits*, parce que
c'est lui qui décide du numéro de la prochaine version :

```
feat: l’extrait des adhésions que le bureau demandait
fix(import): le numéro de licence était écrit sous une clé morte
feat!: le profil ne porte plus l’adresse postale
```

| Type | Ce qu'il annonce | Palier |
|---|---|---|
| `feat` | une fonctionnalité | mineure |
| `fix` `perf` `refactor` `revert` `docs` `style` `test` `build` `ci` `chore` | le reste | correctif |
| n'importe lequel suivi de `!`, ou `BREAKING CHANGE:` dans le corps | une rupture | majeure |

Le corps reste libre, et c'est là que se dit le pourquoi — le sujet ne fait
qu'annoncer la nature du changement.

Tant que la majeure est `0`, une rupture ne monte qu'en mineure : annoncer 1.0
dit « cette interface est stable », ce qui se décide et ne se déduit pas.

Le contrôle est **bloquant** sur une pull request. Le hook `commit-msg` le
rejoue en local, où corriger coûte encore un `git commit --amend`.

## Publier une version

Il n'y a **rien à poser à la main** : ni balise, ni numéro. À la fusion dans
`main`, `version.py` lit, pour chaque paquet, l'étiquette de sa dernière
release et les commits qui ont touché son archive depuis. S'il n'y en a aucun,
rien n'est publié. Sinon le palier se déduit des types, le numéro est écrit
dans les en-têtes, commité, étiqueté, et l'archive part en release.

Publier l'extension seule, ou le thème seul, ne se décide donc pas : cela
découle de ce que les commits ont touché.

**Ce qui « a changé » se juge sur l'archive, pas sur le dépôt.** Un commit qui
ne touche que `tests/` ou `.github/` ne publie rien : ces fichiers ne partent
pas chez les adhérents. C'est la leçon de la release 0.14.4, dont l'archive
était identique à la précédente.

Le commit qui pose le numéro touche lui-même un fichier empaqueté, et
appellerait donc une publication à son tour. L'étiquette est posée *sur ce
commit-là* : l'exécution suivante ne trouve rien après elle et s'arrête.

Voir d'avance ce qu'une branche publiera :

```
python3 version.py plugin
python3 version.py theme
```

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
