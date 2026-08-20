# Images du thème

Toutes les images de ce dossier sont dérivées d'**un seul fichier source** :
`logo-subalcatel-source.jpg`, à la racine du dépôt — le logo fourni par le club
en août 2026, un JPEG de 1024 × 1024.

Rien n'est retouché à la main. La chaîne ci-dessous est reproductible : si le
club livre un jour une version corrigée du logo, il suffit de remplacer le
fichier source et de rejouer les commandes.

## Ce que contient le dossier

| Fichier | Taille | Emploi |
|---|---|---|
| `logo.png` | 256 × 256 | Pastille (anneau + poulpe, sans ruban). En-tête, pied de page, écran de connexion — voir `inc/logo.php` |
| `favicon-32.png` … `favicon-512.png` | 32 à 512 | Même pastille. Onglet, écran d'accueil iOS, tuile Windows, PWA — voir `inc/icone.php` |
| `logo-complet.png` | 320 × 317 | Marque complète, ruban « Subalcatel » compris. Impression, réseaux sociaux, médiathèque |
| `logo-empreinte.png` | 256 × 254 | Marque complète en monochrome. Sert de **masque CSS** au filigrane des vignettes d'articles sans photo (`site.css`, `.sub-vignette--repli::after`) |

## Trois décisions, et pourquoi

**Le fond du logo est détouré, mais pas l'intérieur de l'anneau.** Le remplissage
part des quatre coins et ne se propage qu'aux pixels connexes ; l'anneau étant
fermé, le bleu très clair qu'il enferme est conservé. C'est voulu : posée sur
l'en-tête marine, la pastille garde son fond clair et le poulpe reste lisible.
Un détourage complet aurait laissé un poulpe marine sur un bandeau marine.

**Le ruban est écarté de la pastille.** Sous 64 px il n'est plus qu'une barre
floue, et il mange la moitié de la hauteur disponible. Dans l'en-tête, le bloc
« Titre du site » écrit déjà « Sub Alcatel » à côté du logo : le ruban ferait
doublon. `logo-complet.png` le conserve pour les usages où la marque est seule.

**Les PNG sont quantifiés à 160 couleurs.** Le dessin est un aplat vectoriel
ombré : à cette palette la différence est invisible à l'œil, et le dossier passe
de 1,1 Mo à 236 ko. La quantification est faite hors ligne, par ImageMagick, pas
par le navigateur.

Pas de SVG : le logo est un dessin raster, et une vectorisation automatique
rendrait un tracé lourd et imprécis. C'est le seul point qui resterait à
reprendre par un graphiste (voir `wordpress/design-arborescence.md` §1.3).

## Régénérer

Depuis la racine du dépôt, avec ImageMagick 7 :

```bash
SRC=logo-subalcatel-source.jpg
IMG=wordpress/www/wp-content/themes/subalcatel/assets/img
TMP=$(mktemp -d)

# 1. Aplatir le fond depuis les quatre coins, puis rogner les marges.
magick "$SRC" -fuzz 10% -fill '#E9F4FA' \
  -draw 'color 0,0 floodfill'       -draw 'color 1023,0 floodfill' \
  -draw 'color 0,1023 floodfill'    -draw 'color 1023,1023 floodfill' \
  "$TMP/plat.png"
magick "$TMP/plat.png" -fuzz 4% -trim +repage "$TMP/marque.png"

# 2. Rendre transparent le fond connexe aux coins (l'anneau reste plein).
magick "$TMP/plat.png" -alpha set -fuzz 12% -fill none \
  -draw 'alpha 0,0 floodfill'       -draw 'alpha 1023,0 floodfill' \
  -draw 'alpha 0,1023 floodfill'    -draw 'alpha 1023,1023 floodfill' \
  -trim +repage "$TMP/detoure.png"

# 3. Pastille carrée : on coupe sous l'anneau, au-dessus du ruban.
magick "$TMP/detoure.png" -crop 667x548+0+0 +repage \
  -background none -gravity center -extent 700x700 "$TMP/badge.png"

for f in 32 180 192 270 512; do
  magick "$TMP/badge.png" -filter Lanczos -resize ${f}x${f} \
    -strip -colors 160 -define png:compression-level=9 PNG8:"$IMG/favicon-$f.png"
done
magick "$TMP/badge.png" -filter Lanczos -resize 256x256 \
  -strip -colors 160 -define png:compression-level=9 PNG8:"$IMG/logo.png"

# 4. Marque complète, ruban compris.
magick "$TMP/detoure.png" -filter Lanczos -resize 320x \
  -strip -colors 160 -define png:compression-level=9 PNG8:"$IMG/logo-complet.png"

# 5. Empreinte : la couche alpha porte l'encrage du dessin — le fond clair
#    devient transparent, le trait devient opaque. Seul l'alpha sert au masque.
magick "$TMP/marque.png" -colorspace Gray -negate -level 8%,88% "$TMP/encre.png"
magick -size 667x661 xc:white "$TMP/encre.png" -alpha off \
  -compose CopyOpacity -composite \
  -filter Lanczos -resize 256x -strip -colors 64 \
  -define png:compression-level=9 PNG8:"$IMG/logo-empreinte.png"

rm -rf "$TMP"
```

Les nombres codés en dur (1023, `667x548`, `700x700`, `667x661`) valent pour
cette source de 1024 × 1024 dont le dessin occupe la boîte 190,164 → 856,830.
Une source différente demande de les recalculer :

```bash
magick logo-subalcatel-source.jpg -fuzz 6% -trim -format '%wx%h%O\n' info:
```

## Palette relevée

Les couleurs de `theme.json` sont échantillonnées sur ce fichier :
contour `#163458`, ruban `#2570A3`, poulpe `#454D80` à `#7D96BF`, bulles
`#64BBDC`, masque `#FF8E40`, fond `#E9F4FA`. Elles y sont ajustées en clarté
pour tenir les seuils WCAG — le détail est dans
`wordpress/design-arborescence.md` §2.1.
