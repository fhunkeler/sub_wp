# Créer et diffuser un document du club

Guide pour le bureau : déposer un document (statuts, compte rendu d'AG,
consignes…) et choisir qui peut le télécharger. Tout adhérent à jour peut
aussi déposer des documents depuis le site, par exemple un moniteur ses
supports de cours : voir [Dépôt par les adhérents](#6-dépôt-par-les-adhérents).
Le code de référence est `plugins/subalcatel-club/src/Content/ClubDocuments.php`.

> Les captures viennent de la démo locale, avec des documents d'exemple.

## En bref : trois niveaux d'accès

Chaque document porte sa propre restriction. La page **Documents du club** est
ouverte à tous, mais chacun n'y voit que ce qu'il a le droit de télécharger.

| Accès choisi | Qui peut le télécharger | Exemples |
|---|---|---|
| **Public** | Tout le monde, même sans compte | Statuts, règlement intérieur, plaquette |
| **Adhérents** | Les membres connectés **dont l'adhésion est en cours** | Compte rendu d'AG, calendrier des sorties |
| **Restreint** | Les membres qui ont une capacité précise (voir plus bas) | Consignes réservées aux encadrants |

Le bureau voit et télécharge **tous** les documents, quel que soit leur accès.

## 1. Créer un document

Menu **Club → Documents du club**, puis **Ajouter un document**.

![Liste des documents du club dans l'administration](img/documents-du-club/admin-liste.jpg)

La liste montre pour chaque document son accès, le fichier attaché et le nombre
de téléchargements.

Sur l'écran d'ajout :

1. **Titre** : c'est le nom affiché aux membres (« Calendrier des sorties 2027 »).
2. **Texte** (facultatif) : une courte description, affichée sous le titre.
3. **Catégories** (à droite) : cochez la rubrique sous laquelle le document
   sera rangé. **+ Ajouter une catégorie** en crée une nouvelle (voir
   [Ranger en sous-catégories](#ranger-en-sous-catégories)).
4. **Fichier et accès** (en bas) : **Choisir un fichier**, puis **Qui peut le
   télécharger**.
5. **Publier**.

![Écran d'ajout d'un document, rempli](img/documents-du-club/admin-nouveau.jpg)

Formats acceptés : PDF, Word, Excel, PowerPoint, LibreOffice (odt, ods), JPEG,
PNG et ZIP, jusqu'à 25 Mo.

> ⚠️ **N'utilisez pas le bouton « Ajouter un média »** pour joindre le fichier.
> Il range le fichier dans la médiathèque, où il est public et n'est protégé
> par aucun accès. Seul le champ **Fichier** du cadre « Fichier et accès »
> protège le document.

> ⚠️ La ligne **Visibilité : Publique** du cadre « Publier » ne concerne pas les
> droits de téléchargement. Laissez-la telle quelle : c'est le champ **Qui peut
> le télécharger** qui décide.

### Ranger en sous-catégories

Les catégories s'emboîtent comme des dossiers :
« Supports de formation › Niveau 1 › Théorie ». Pour créer une sous-catégorie,
menu **Club → Documents du club → Catégories** : saisissez le nom
(« Niveau 1 »), choisissez **Supports de formation** comme **Catégorie
parente**, puis **Ajouter une catégorie**. On peut descendre d'autant de
niveaux qu'on veut.

Chaque catégorie peut être **réservée au bureau** (case du même nom sur
l'écran de la catégorie, colonne « Dépôt » dans la liste) : les adhérents ne
peuvent alors pas y ranger de document. Voir
[Dépôt par les adhérents](#6-dépôt-par-les-adhérents).

Sur le document, cochez seulement la catégorie la plus précise (« Théorie »).
Cocher aussi la parente ne change rien : le document reste rangé sous la plus
précise. En revanche, deux catégories sans lien (« Niveau 1 » et « Niveau 2 »)
le font apparaître aux deux endroits.

## 2. Choisir qui peut le télécharger

### Document public

Choisir **Public — visible par tout le monde**. N'importe quel visiteur, même
sans compte, voit le document et le télécharge.

![Accès Public](img/documents-du-club/acces-public.png)

### Document réservé aux adhérents

Choisir **Adhérents — adhésion en cours requise**. C'est le choix proposé par
défaut.

![Accès Adhérents](img/documents-du-club/acces-adherents.png)

Attention : être connecté ne suffit pas. Il faut une **adhésion en cours**
(date de fin de validité non dépassée). Ne voient donc **pas** ces documents :

- les visiteurs non connectés ;
- les comptes « Invité du club » ;
- les membres dont l'adhésion a expiré ou n'est pas encore validée.

Il n'existe pas de niveau « tout compte connecté » : un document à montrer à
quelqu'un qui n'est pas adhérent doit être public.

### Document réservé à un type de membre

Choisir **Restreint — selon une capacité précise**. Une seconde liste apparaît,
**Capacité requise** : seules les personnes qui ont cette capacité verront le
document.

![Accès Restreint, réservé aux encadrants](img/documents-du-club/acces-restreint.png)

La liste reprend toutes les capacités du site ; seules quelques-unes ont un
sens pour un document. Les plus utiles :

| Pour réserver le document à… | Capacité à choisir | Qui l'a |
|---|---|---|
| Encadrants et directeurs de plongée | **Créer une plongée de formation** | Tout membre dont le niveau est E1 ou plus (P2/E1, P3/E1, P4/E2, P5/E2, E3, E4) ou P5 |
| Plongeurs autonomes | **Créer une plongée d'exploration** | Tout membre de niveau P3 ou plus (y compris les encadrants à partir de P3/E1) |
| Bureau seulement | **Gérer les documents du club** | Les membres du bureau |

Les capacités liées au niveau de plongée suivent le niveau saisi sur le compte :
un membre qui passe son E1 voit aussitôt les documents réservés aux encadrants,
sans qu'il faille toucher à son compte.

À savoir : un accès **Restreint** ne vérifie pas l'adhésion. Un encadrant dont
la cotisation a expiré garde l'accès aux documents réservés aux encadrants.

## 3. Ce que voit chacun

La page **Documents du club** est dans le menu principal et dans **Mon espace**.
Les documents y sont rangés dans l'arborescence des catégories, repliée par
défaut : un clic sur une catégorie l'ouvre, **Tout déplier** ouvre tout. Le
nombre affiché à droite compte les documents de la catégorie et de ses
sous-catégories. Un cadenas 🔒 signale un document non public.

![Arborescence des documents, dépliée](img/documents-du-club/arborescence.jpg)

Une catégorie dont aucun document n'est visible pour la personne n'apparaît
pas : un visiteur ne voit pas de rubrique vide. Une recherche déplie
automatiquement les catégories qui contiennent un résultat.

**Visiteur non connecté** : uniquement les documents publics, et une invitation
à se connecter.

![Vue d'un visiteur](img/documents-du-club/visiteur-liste.jpg)

**Adhérent à jour** : en plus, le compte rendu d'AG (accès Adhérents).

![Vue d'un adhérent](img/documents-du-club/adherent-liste.jpg)

**Encadrant** : en plus, les consignes réservées aux encadrants (accès
Restreint).

![Vue d'un encadrant](img/documents-du-club/encadrant-liste.jpg)

## 4. Diffuser un document

- **Par la page Documents du club** : rien à faire, le document y apparaît dès
  sa publication, pour les personnes autorisées.
- **Par un lien vers une catégorie** : ajoutez `#doc-cat-` suivi de
  l'identifiant de la catégorie (colonne « Identifiant » de l'écran
  Catégories) à l'adresse de la page, par exemple
  `…/espace-membre/documents/#doc-cat-niveau-1`. La page s'ouvre directement
  sur cette catégorie, dépliée.
- **Par un lien direct** (dans un article, une actualité, un courriel) : sur
  l'écran du document, dans le cadre « Fichier et accès », faites un clic droit
  sur le nom du fichier → **Copier l'adresse du lien**. Le lien ressemble à
  `…/wp-admin/admin-post.php?action=sub_club_document&document=123`.

Le lien direct respecte l'accès du document : on peut l'envoyer à tout le club
sans risque. Une personne qui n'a pas le droit reçoit un message de refus au
lieu du fichier ; un visiteur non connecté se voit proposer de se connecter.

![Message reçu par un visiteur sur un document réservé](img/documents-du-club/visiteur-refus.jpg)

Les messages possibles :

- « Ce document est réservé aux adhérents. Connectez-vous pour y accéder. »
  (visiteur non connecté) ;
- « Ce document est réservé aux adhérents à jour de cotisation. » ;
- « Ce document est réservé à certains membres du club. » (accès Restreint).

Publier un document n'envoie aucun courriel. Pour prévenir les membres, publiez
une actualité avec le lien, ou écrivez-leur depuis **Club → Communication**.

## 5. Mettre à jour, retirer

- **Changer l'accès** : ouvrez le document, modifiez **Qui peut le
  télécharger**, puis **Mettre à jour**. L'effet est immédiat, y compris pour
  les liens déjà envoyés.
- **Remplacer le fichier** : choisissez un nouveau fichier puis **Mettre à
  jour**. Le lien ne change pas ; l'ancien fichier rejoint les **Versions
  précédentes**, d'où un bouton **Restaurer** le remet en place.

  ![Remplacer le fichier d'un document existant](img/documents-du-club/remplacer-fichier.png)

- **Masquer temporairement** : repassez le document en brouillon. Un brouillon
  n'apparaît nulle part et son lien est refusé (sauf pour le bureau).
- **Supprimer** : **Déplacer dans la corbeille**. Le fichier et toutes ses
  versions sont effacés quand la corbeille est vidée.

## 6. Dépôt par les adhérents

Tout adhérent **à jour de cotisation** dont le compte est validé peut déposer
un document lui-même, sans accès à l'administration : un moniteur y met ses
supports de cours, un membre le compte rendu d'une sortie. Les comptes
« Invité du club » et les adhésions expirées ne peuvent pas déposer.

### Côté adhérent

Sur la page **Documents du club**, bouton **Déposer un document** en haut de
la page : titre, description facultative, catégorie, accès et fichier, puis
**Publier le document**. Le document est **publié aussitôt**.

![Formulaire de dépôt d'un adhérent](img/documents-du-club/depot-formulaire.jpg)

L'adhérent choisit l'accès parmi trois possibilités, plus simples que celles
du bureau :

| Choix proposé | Équivaut, côté bureau, à |
|---|---|
| Public | Public |
| Adhérents | Adhérents |
| Encadrants | Restreint — Créer une plongée de formation |

L'auteur peut toujours télécharger son propre document, même s'il l'a réservé
aux encadrants sans en être un.

Sous le formulaire, **Mes documents déposés** liste ses documents : il peut en
**remplacer le fichier** (l'ancien rejoint l'historique des versions) ou les
**retirer** (ils partent à la corbeille).

![Mes documents déposés](img/documents-du-club/depot-mes-documents.jpg)

Les formats et la taille maximale sont les mêmes que pour le bureau, et chaque
fichier passe par le même contrôle (un fichier qui contient du code est
refusé).

### Côté bureau : choisir les catégories ouvertes

Dans **Club → Documents du club → Catégories**, la colonne **Dépôt** indique
pour chaque catégorie qui peut y ranger des documents : **Bureau** ou
**Adhérents**. Pour changer, ouvrez la catégorie et cochez ou décochez
**Réservée au bureau**.

![Case « Réservée au bureau » d'une catégorie](img/documents-du-club/categorie-reservee-bureau.jpg)

- Une nouvelle catégorie est **ouverte** par défaut.
- « Assemblées générales », « Comptes rendus de réunion » et « Statuts et
  règlement intérieur » sont réservées au bureau dès l'installation de cette
  version. Le bureau peut changer ce réglage.
- Le réglage vaut pour la catégorie seule : on peut réserver « Supports de
  formation » au bureau et ouvrir sa sous-catégorie « Niveau 1 ».

### Côté bureau : garder la main

Les documents déposés par les adhérents apparaissent dans **Club → Documents
du club** comme les autres ; la colonne **Déposé par** indique leur auteur. Le
bureau peut les modifier, changer leur accès ou les supprimer comme n'importe
quel document. Aucun courriel n'est envoyé au bureau lors d'un dépôt.

