# subalcatel-club — extension métier du club

Adhésions, événements et droits du club de plongée Sub Alcatel.
Version **0.14.3** — jalon *démonstration*.

---

## Démarrer

```bash
cd wordpress && docker compose up -d
```

Le site est sur **http://127.0.0.1** — pas `localhost` : WordPress est enregistré
sur `127.0.0.1`, et le cookie de session ne suit pas d'un hôte à l'autre.

## Comptes de démonstration

| Compte | Mot de passe | Rôle | Ce qu'il montre |
|---|---|---|---|
| `demo_membre` | `demo1234` | Membre, niveau P2 | Adhésion, agenda, inscription |
| `demo_bureau` | `demo1234` | Bureau, niveau P5 | Validation des dossiers, création d'événements |
| `admin` | *(installation)* | Administrateur | Tout |

## Pages de démonstration

| Page | Contenu |
|---|---|
| `/adherer/` | Formulaire d'adhésion, récapitulatif tarifaire en direct |
| `/agenda-club/` | Agenda, éligibilité, inscription et liste d'attente |
| `/mon-profil/` | Profil du membre — les champs du bureau sont en lecture seule |
| `/espace-membre/agenda/organiser-une-sortie/` | Un autonome ou un directeur de plongée ouvre une sortie sans passer par l'administration |
| `/espace-membre/agenda/mes-sorties-organisees/` | Ses inscrits — niveau, téléphone, personne à prévenir, validité des documents — et la feuille d'émargement à imprimer |
| **Club → Adhésions → Dossiers** | Validation : paiement puis secrétariat |
| **Club → Membres → Annuaire** | Liste, fiche, attribution des niveaux, historique des brevets |
| **Club → Adhésions → Campagnes** | Liste, création et **duplication annuelle** |
| **Configurer une campagne** | Formules, options et remises — tout se règle ici |
| **Club → Statistiques** | Renouvellement, niveaux, âges, participation ; recettes, origine des adhésions et délais d'encaissement |

## Dérouler la démonstration

**1. La tarification conditionnelle** — `/adherer/` en tant que `demo_membre`.

Choisir *Plongée*, puis **Nokia**. Le récapitulatif affiche aussitôt la remise
forfaitaire. Choisir *P2* comme niveau préparé : deux options apparaissent
(carte de niveau, prêt d'ordinateur) qui n'existaient pas avant. Ajouter le prêt
d'un bloc et d'un détendeur : la remise passe à **-108,00 €**, total **273,00 €**.

C'est exactement le résultat de la formule OSMembership d'origine
(`-58 - [BLOC]*14/36 - [DETENDEUR]*0.40 - [GILET]*0.40`), obtenu sans aucune
formule — un forfait et des pourcentages, saisissables par un bénévole.

Comparer avec un profil *Extérieur* : mêmes options, remise absente.

**2. Le workflow bureau** — soumettre le dossier, puis se connecter en
`demo_bureau` et ouvrir l'écran *Club*. Le dossier apparaît avec son détail
figé. Enregistrer le paiement, puis valider. L'adhésion devient active **et les
droits d'emprunt souscrits s'ouvrent automatiquement**.

**3. L'éligibilité** — `/agenda-club/` en tant que `demo_membre`. Les sorties
dont le niveau ne convient pas affichent le motif exact plutôt qu'un refus muet.

**3 bis. L'annonce d'une sortie** — *Club → Événements*. Créer une sortie en
cochant **Prévenir par courriel les membres concernés** : le message part
aussitôt aux adhérents à jour dont le niveau permet l'inscription, et le compte
rendu dit combien sont partis. Laisser la case décochée, puis ouvrir la sortie :
le bouton **Annoncer la sortie aux membres** attend, avec l'effectif affiché
avant le clic. Un second envoi rappelle la date du premier. Même geste depuis
l'espace membre, à la publication d'une sortie et dans *Mes sorties organisées*.

**4. La configurabilité** — *Club → Adhésions → Campagnes*, bouton **Dupliquer**. La saison
suivante est créée en brouillon, dates décalées d'un an, avec ses formules,
options et remises. Ouvrir l'onglet *Options*, changer le tarif d'un prêt :
seule la nouvelle campagne bouge, l'ancienne garde ses montants — donc la
comptabilité de l'an passé aussi.

L'onglet *Remises* montre la remise Nokia telle qu'elle se configure : un
forfait de -58 €, puis 14 € sur le prêt de bloc et 40 % sur le détendeur et le
gilet. C'est l'exact équivalent de la formule OSMembership
`-58 - [BLOC]*14/36 - [DETENDEUR]*0.40 - [GILET]*0.40`, saisissable à la souris.

## Tests

```bash
docker exec sub_demo_wp wp --allow-root eval-file wp-content/plugins/subalcatel-club/tests/smoke-pricing.php
```

Une suite par domaine, dans `tests/` — `smoke-eligibility`, `smoke-pricing`,
`smoke-application`, `smoke-events`, `smoke-outing`, `smoke-roster`,
`smoke-charts`, `smoke-stats`, `smoke-widget`… Elles nettoient leurs données et se lancent de
la même façon.

Ce sont des tests de fumée, pas des tests unitaires : ils vérifient que les
parcours tiennent debout de bout en bout. PHPUnit viendra quand le périmètre
sera stabilisé.

## Ce que contient ce jalon

| Module | État |
|---|---|
| Rôles, capacités, niveaux de plongée | Livré |
| `EligibilityPolicy` — point de décision unique | Livré |
| Tarification conditionnelle, remises, campagnes | Livré |
| Dossiers : soumission, paiement, validation, droits | Livré |
| Événements : types, droits de création, inscription, liste d'attente | Livré |
| Écran bureau des dossiers | Livré |
| Profil membre : 26 champs, 7 groupes, droits par champ | Livré |
| Fiche membre bureau + historique des niveaux | Livré |
| Campagnes : création, duplication, ouverture | Livré |
| Formules, options et remises configurables | Livré |
| Journal d'audit | Livré |
| Tableau de bord : 6 blocs actionnables et 4 courbes | Livré |
| Bloc « Club » sur le tableau de bord de WordPress : compteurs et raccourcis | Livré |
| Statistiques annuelles : renouvellement, niveaux, âges, participation | Livré |
| Statistiques financières : recettes, origine des adhésions, encaissements | Livré |
| Sorties organisées : inscrits, message, désinscription, émargement — sans wp-admin | Livré |
| Annonce d'une sortie aux membres concernés — case à cocher ou bouton, jamais automatique | Livré |
| Documents personnels (certificat médical, licence) | **À faire** |
| Adhérents mineurs | **À faire** |
| Exports CSV / XLSX / PDF, tableau de bord DP | **À faire** |
| Notifications et rappels | **À faire** |
| Matériel, entretiens, emprunts | Phase ultérieure |

## Points d'attention

**Les tarifs sont ceux du Joomla, pas ceux de la saison à venir.** Les options
et les remises proviennent du dump du 22/07/2026 ; les **prix de base des deux
plans** — 144 € pour la plongée, 59 € pour la nage avec palmes — ont été repris
de la table des plans OSMembership. Ce sont des données : le bureau les ajuste
depuis l'interface à chaque campagne, sans toucher au code. Les suites de tests
n'en dépendent pas : elles créent leur propre campagne, aux montants figés.

**La campagne de démonstration ouvre à la date du jour** pour que la démo
fonctionne toute l'année. En exploitation, le bureau saisit la vraie date
d'ouverture : c'est un champ de la campagne, pas une constante du code.

**Les niveaux PA20 et PA40** sont marqués *non autonomes*, conformément à la
lettre de `projet.md` (autonomie de P3 à E4). Ces qualifications désignant
littéralement une autonomie limitée en profondeur, le point est à faire trancher
par un encadrant. C'est une donnée, pas du code : un drapeau sur le terme.

**`EligibilityPolicy` lit encore des métas utilisateur** pour la validité du
certificat médical et de la licence. Le module Documents les remplacera ; le
reste du code n'aura pas à changer.

**Un membre ne modifie pas son propre niveau de plongée.** Niveau, Nitrox,
Trimix, RIFAP, permis bateau et certificat radio sont réservés au bureau : ils
conditionnent l'accès aux plongées, donc la sécurité. Le membre les voit, en
lecture seule, avec la raison. Toute tentative de forcer ces champs par la
requête est rejetée côté serveur — et testée comme telle.
