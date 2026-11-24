# subalcatel-profil — ce qui est propre à Sub Alcatel

Version **1.0.0**.

L'extension `subalcatel-club` est le produit, commun à tous les clubs de
plongée. Ce qui n'est vrai que pour Sub Alcatel vit ici, et s'y branche par les
filtres qu'elle expose. Un autre club aurait son propre profil — ou aucun.

## Ce que le profil apporte

- **Le règlement par le CE Orange** (`src/CeOrange.php`) : un mode de
  règlement de plus, avec sa consigne, l'adresse de la boutique HelloAsso
  « CE Orange » de chaque campagne, et sa **prise en charge** — le CE règle
  l'adhésion, l'adhérent ne paie que la carte de niveau et l'assurance. Le
  partage lui-même est fait par le cœur (`ThirdPartyShare`).

## Installation et mises à jour

L'extension est publiée en release comme le cœur et le thème (balises
`profil-X.Y.Z`, archive `subalcatel-profil-X.Y.Z.zip`), et se met à jour de la
même façon, depuis *Tableau de bord → Mises à jour*.

Elle s'installe **avant** la version du cœur qui la lit : elle nomme les
filtres du cœur en toutes lettres, si bien que sur un cœur plus ancien elle ne
fait simplement rien. Installer le profil, puis mettre le cœur à jour : aucune
coupure, le mode « CE Orange » ne disparaît jamais du formulaire.

## Tests

```bash
docker exec sub_demo_cli wp --allow-root eval-file \
  wp-content/plugins/subalcatel-profil/tests/smoke-ce-orange.php
```
