# Thème Sub Alcatel

Thème bloc du club de plongée Sub Alcatel. Il applique la charte définie dans `wordpress/design-arborescence.md` et fournit les gabarits du site vitrine, du blog et de l'espace membre.

**Le thème ne contient aucune règle métier.** Adhésions, événements, droits, emprunts et éligibilité vivent dans l'extension `subalcatel-club`. Cette séparation est délibérée : elle permet de refondre le design sans toucher aux données du club, et de mettre à jour les règles sans casser la présentation.

## Thème autonome, pas thème enfant

Les documents de cadrage évoquaient un thème *enfant* de Twenty Twenty-Five. Ce thème est finalement **autonome**, pour trois raisons :

1. Un thème bloc enfant hérite du `theme.json` du parent — dont la palette, l'échelle typographique et les styles de blocs entrent en conflit avec les nôtres, et exigent d'être neutralisés un par un.
2. Il hérite aussi de la centaine de compositions et des variations de style du parent, qui noient les compositions du club dans l'inserteur. Pour des rédacteurs bénévoles, c'est un vrai coût d'usage.
3. Le thème par défaut de WordPress est remplacé chaque année. Dépendre de lui, c'est accepter une refonte imposée à chaque version majeure.

Le coût est d'environ 15 fichiers à maintenir. Le bénéfice est un contrôle total et aucune dépendance mouvante.

## Installation

```bash
wp theme activate subalcatel
```

Ou : **Apparence → Thèmes → Sub Alcatel → Activer**.

## Après activation — 6 étapes

1. ~~Déposer les polices~~ — **fait.** Outfit et Inter (variables, sous-ensemble latin, 78,6 Ko au total) sont dans `assets/fonts/`, avec leurs licences OFL. Rien à faire.
2. ~~Déposer le logo~~ — **fait.** Le poulpe casqué du club est intégré au thème : en-tête, pied de page, onglet du navigateur, écran de connexion et filigrane des vignettes. Rien à téléverser. Le jour où le bureau dépose son propre fichier (Apparence → Éditeur → Styles, ou Réglages → Général pour l'icône), celui du thème s'efface de lui-même.
3. **Créer les pages** listées dans `design-arborescence.md` §4, avec les slugs indiqués. Les gabarits `page-tarifs`, `page-equipe`, `page-formations`, `page-agenda`, `page-contact`, `page-connexion` s'appliquent automatiquement aux pages dont le slug correspond.
4. **Définir la page d'accueil** : Réglages → Lecture → page statique. Le gabarit `front-page` s'applique alors.
5. **Vérifier les liens de navigation** : les menus de `parts/header.html` et `parts/footer.html` pointent vers les URL cibles. Tant que les pages n'existent pas, ces liens renvoient une 404 — c'est attendu.
6. **Régler les permaliens** sur « Titre de la publication » (Réglages → Permaliens), sans quoi les URL du document d'arborescence ne fonctionnent pas.

## Structure

```text
subalcatel/
  theme.json              Tokens, styles de blocs et d'éléments, gabarits personnalisés
  style.css               En-tête de déclaration du thème uniquement
  functions.php           Point d'entrée, charge inc/
  inc/
    setup.php             Supports, catégories de compositions, désactivation des commentaires
    fonts.php             @font-face conditionnels, avertissement si polices absentes
    assets.php            Chargement des styles, site et éditeur
    blocks.php            Styles de blocs, bloc « compte », masquage serveur de la barre membre
    logo.php              Logo par défaut du bloc « Logo du site »
    icone.php             Icône du site par défaut (onglet, iOS, PWA)
    connexion.php         Habillage de wp-login.php aux couleurs du club
  assets/
    css/site.css          Composants que theme.json ne sait pas exprimer
    css/editor.css        Ajustements propres au canevas d'édition
    img/                  Déclinaisons du logo et icônes du site (voir img/README.md)
    fonts/                Polices .woff2 (non versionnées — voir le README)
  parts/                  En-tête public, barre membre, pied de page
  templates/              16 gabarits
  patterns/               9 compositions
```

## Ce que fait le thème

| Sujet | Comportement |
|---|---|
| Palette | 14 couleurs relevées sur le logo du club, choix libre désactivé (`"custom": false`) — un rédacteur ne peut pas inventer une couleur |
| Logo et icône | Fournis par le thème, sans téléversement ; un fichier déposé par le bureau reprend la main (`inc/logo.php`, `inc/icone.php`) |
| Typographie | Outfit + Inter auto-hébergées, tailles limitées à l'échelle définie |
| Espacements | Échelle de 4 px, saisie libre désactivée |
| Commentaires | Désactivés partout, y compris sur les contenus importés |
| Barre membre | Rendue côté serveur uniquement si l'utilisateur est connecté (`pre_render_block`), pas masquée en CSS |
| Bouton de compte | Bloc dynamique `subalcatel/compte` : visiteur → « Nous rejoindre / Connexion » ; membre → avatar et déconnexion |
| Vignettes des cartes | Taille dédiée `subalcatel-carte` (720 × 480) — un article sans image reçoit un aplat de la charte plutôt qu'un trou dans la grille |
| Accessibilité | Lien d'évitement, focus visible, cibles 44 px, `prefers-reduced-motion`, tableaux à défilement propre |
| Impression | En-têtes et pieds masqués, URL des liens explicitées — le bureau imprime les listes d'inscrits |

## Ce que le thème ne fait pas

- **Agenda, tarifs calculés, dossiers d'adhésion, emprunts.** Ce sont des blocs de l'extension `subalcatel-club`. Les gabarits leur réservent la place ; en attendant, `front-page.html` affiche un encart explicite à cet emplacement.
- **Contrôle d'accès.** `subalcatel_body_class()` ajoute `sub-connecte` / `sub-visiteur` à des fins d'affichage seulement. Aucune information sensible ne doit être masquée par CSS.
- **Formulaire de contact.** À confier à un plugin de formulaires générique, conformément au principe directeur de `PROPOSITION_WORDPRESS.md` §2.

## Points de vigilance

- **Cache de pages.** La barre membre et le bouton de compte varient selon l'état de connexion. Tout cache de page doit être configuré pour ne pas servir une version connectée à un visiteur — la plupart des plugins de cache ignorent par défaut les utilisateurs connectés, ce qui suffit, mais il faut le vérifier avant la mise en production.
- **`screenshot.png` absent.** WordPress affiche une vignette grise dans la liste des thèmes. À produire (1200 × 900) une fois qu'une photo du club est disponible — le logo, lui, ne manque plus.
- **Textes d'exemple.** Les compositions contiennent des chiffres et des tarifs plausibles mais inventés (128 adhérents, 168 € la cotisation adulte). Ils doivent être remplacés par les valeurs réelles avant toute mise en ligne.

## Vérifications effectuées

- `php -l` sur les 15 fichiers PHP : aucune erreur de syntaxe.
- `theme.json` : JSON valide, version 3, 14 couleurs, 8 tailles, 8 gabarits personnalisés.
- Structure des blocs des 28 gabarits, parties et compositions : aucun bloc non fermé ni fermeture orpheline.
- Références croisées : chaque `wp:template-part` et `wp:pattern` désigne un fichier existant.
- Cohérence des tokens : chaque slug de couleur, dégradé, taille, famille et espacement employé dans le balisage ou la CSS existe dans `theme.json`.
- Contrastes WCAG 2.1 AA : 22 paires réellement employées, plus les badges d'état de l'administration — toutes au-dessus du seuil (4,5:1 en texte, 3:1 pour le grand texte et les éléments non textuels). Le contrôle est rejoué par `tests/smoke-theme.php` de l'extension, qui lit la palette dans `theme.json` : une retouche de couleur qui casse un seuil fait échouer la suite.
- Logo par défaut : rendu du bloc « Logo du site » sans pièce jointe, avec et sans lien, avec et sans largeur — texte alternatif correct dans chaque cas.

**Non vérifié** : le rendu réel dans WordPress. Il demande de démarrer la pile Docker et d'installer le site — à faire avant tout arbitrage du bureau sur la base d'une capture d'écran.
