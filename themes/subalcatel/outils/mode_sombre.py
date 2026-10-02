#!/usr/bin/env python3
"""
Génère la feuille « mode sombre » d'une feuille de style claire.

    python3 outils/mode_sombre.py SOURCE.css SORTIE.css

Pour chaque déclaration de couleur de SOURCE (fond, texte, bordure, ombre de
contour), le script calcule l'équivalent sombre et l'écrit sous le même
sélecteur, préfixé par `:root[data-theme="dark"]`. Le rôle de la propriété
décide de la transformation :

  - fond clair            → surface sombre de même teinte ;
  - fond sombre translucide (voile de survol) → voile clair de même opacité ;
  - texte sombre          → texte clair de même teinte ;
  - bordure claire        → filet sombre ; bordure sombre opaque → filet clair.

Les couleurs pleines déjà adaptées (blanc sur bouton orange, bande marine…)
ne bougent pas. Les images (`url(...)`) sont laissées telles quelles.

Le fichier produit est un artefact : on ne le retouche pas à la main, on
relance le script après toute modification de la feuille source. Les réglages
fins qui ne se calculent pas vivent dans une feuille écrite à la main
(assets/css/sombre.css pour le thème).

Sans dépendance : bibliothèque standard uniquement.
"""

from __future__ import annotations

import colorsys
import re
import sys
from pathlib import Path

PREFIXE = ':root[data-theme="dark"]'

# Couleurs nommées par variables CSS : palette theme.json et raccourcis de
# site.css. Les variables inconnues gardent leur valeur de repli, s'il y en a.
PALETTE = {
    "abysse": "#142F52", "profond": "#1D5480", "lien": "#1D6493",
    "lagon": "#2E8FC0", "poulpe": "#4A5487", "lavande": "#4B57B8",
    "ecume": "#E9F4FA", "nacre": "#F6F8FB", "brume-claire": "#EDF2F7",
    "blanc": "#FFFFFF", "ardoise": "#566B84", "brume": "#C4D3E3",
    "lavande-pale": "#E6E8F8", "corail": "#F0801F", "corail-fonce": "#C0561A",
    "corail-pale": "#FDEBDD", "sable": "#F2C14E", "algue": "#17795E",
    "alerte": "#B82A1E",
}
VARIABLES = {f"--wp--preset--color--{k}": v for k, v in PALETTE.items()}
VARIABLES.update({f"--sub-{k}": v for k, v in PALETTE.items()})
VARIABLES.update({
    "--sub-trait": "#DDE5EF",
    "--sub-brume-fonce": "#7A8CA6",
    "--wp--custom--bordure--champ": "#7A8CA6",
})

MARINE_H = colorsys.rgb_to_hls(0x14 / 255, 0x2F / 255, 0x52 / 255)[0]

NOMMEES = {"white": (255, 255, 255, 1.0), "black": (0, 0, 0, 1.0)}

ROLES = {
    "background": "fond", "background-color": "fond",
    "color": "texte", "fill": "texte", "stroke": "texte",
    "text-decoration-color": "texte", "caret-color": "texte",
    "-webkit-text-fill-color": "texte",
    "outline": "bordure", "outline-color": "bordure",
    "box-shadow": "ombre", "column-rule": "bordure",
}

COULEUR = re.compile(
    r"var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\))?[^()]*))?\)"
    r"|#[0-9a-fA-F]{8}\b|#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b"
    r"|rgba?\([^)]*\)"
    r"|\b(?:white|black)\b"
)


def role(propriete: str) -> str | None:
    if propriete in ROLES:
        return ROLES[propriete]
    if propriete.startswith("border") and not propriete.endswith(("radius", "width", "style", "collapse", "spacing", "image")):
        return "bordure"
    return None


def lire(texte: str):
    """Couleur CSS → (r, g, b, a) ou None."""
    t = texte.strip()
    m = re.fullmatch(r"var\(\s*(--[\w-]+)\s*(?:,\s*(.*))?\)", t)
    if m:
        if m.group(1) in VARIABLES:
            return lire(VARIABLES[m.group(1)])
        return lire(m.group(2)) if m.group(2) else None
    if t.lower() in NOMMEES:
        return NOMMEES[t.lower()]
    if t.startswith("#"):
        h = t[1:]
        if len(h) == 3:
            h = "".join(c * 2 for c in h)
        a = int(h[6:8], 16) / 255 if len(h) == 8 else 1.0
        return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), a
    m = re.fullmatch(r"rgba?\(([^)]*)\)", t)
    if m:
        parts = [p for p in re.split(r"[\s,/]+", m.group(1).strip()) if p]
        if len(parts) < 3:
            return None
        r, g, b = (float(p) for p in parts[:3])
        a = float(parts[3].rstrip("%")) / (100 if parts[3].endswith("%") else 1) if len(parts) > 3 else 1.0
        return int(r), int(g), int(b), a
    return None


def ecrire(r, g, b, a) -> str:
    if a >= 0.999:
        return f"#{round(r):02x}{round(g):02x}{round(b):02x}"
    return f"rgba({round(r)}, {round(g)}, {round(b)}, {round(a, 3):g})"


def hls(r, g, b):
    return colorsys.rgb_to_hls(r / 255, g / 255, b / 255)


def rgb(h, l, s):
    r, g, b = colorsys.hls_to_rgb(h, max(0, min(1, l)), max(0, min(1, s)))
    return r * 255, g * 255, b * 255


def transformer(c, quoi: str):
    r, g, b, a = c
    h, l, s = hls(r, g, b)
    translucide = a < 0.999
    vive = s > 0.5 and 0.35 < l < 0.8
    if translucide:
        # Voile sombre (survol, ombre de filet) → voile clair ; un voile clair
        # était déjà pensé pour un fond sombre : on le garde.
        if l < 0.35 and quoi in ("fond", "bordure"):
            return ecrire(255, 255, 255, a)
        return None
    if quoi == "fond":
        if l < 0.55:
            return None
        # Plus le fond était clair, plus la surface sombre est « haute » : le
        # blanc des cartes passe au-dessus de la nacre de la page, comme en
        # clair. Les gris neutres prennent la teinte marine du club.
        if l >= 0.9:
            cible = 0.08 + (l - 0.9) * 0.6
        else:
            cible = 0.10 + (0.9 - l) * 0.5
        if s < 0.3 or l > 0.97:
            h, s = MARINE_H, 0.42
        return ecrire(*rgb(h, cible, s * 0.55 if s > 0.42 else s), a)
    if quoi == "texte":
        if l <= 0.6:
            return ecrire(*rgb(h, 0.93 - l * 0.45, min(s, 0.65)), a)
        return None
    if quoi in ("bordure", "ombre"):
        if vive:
            return None  # repères de focus, marqueurs : restent visibles
        if l >= 0.55:
            if s < 0.3:
                h, s = MARINE_H, 0.35
            return ecrire(*rgb(h, 0.22 + (1 - l) * 0.3, s * 0.5 if s > 0.35 else s), a)
        if l < 0.25:
            return ecrire(*rgb(h, 0.72, min(s, 0.5)), a)
        return None
    return None


def convertir_valeur(valeur: str, quoi: str) -> str | None:
    if "url(" in valeur:
        return None
    change = False

    def remplacer(m):
        nonlocal change
        c = lire(m.group(0))
        if c is None:
            return m.group(0)
        nouveau = transformer(c, quoi)
        if nouveau is None:
            return m.group(0)
        change = True
        return nouveau

    resultat = COULEUR.sub(remplacer, valeur)
    return resultat if change else None


def decouper(selecteurs: str) -> list[str]:
    """Sépare une liste de sélecteurs sur les virgules de premier niveau."""
    morceaux, courant, profondeur = [], "", 0
    for car in selecteurs:
        if car in "([":
            profondeur += 1
        elif car in ")]":
            profondeur -= 1
        if car == "," and profondeur == 0:
            morceaux.append(courant)
            courant = ""
        else:
            courant += car
    morceaux.append(courant)
    return [m.strip() for m in morceaux if m.strip()]


def prefixer(selecteurs: str) -> str:
    sortie = []
    for sel in decouper(selecteurs):
        if sel == "html" or sel.startswith(("html ", "html:", "html.", "html[")):
            sortie.append(PREFIXE + sel[4:])
        elif sel.startswith(":root"):
            sortie.append(PREFIXE + sel[5:])
        else:
            sortie.append(f"{PREFIXE} {sel}")
    return ",\n".join(sortie)


def sans_commentaires(css: str) -> str:
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def blocs(css: str, i: int = 0):
    """Découpe en (entête, corps) au premier niveau, à partir de i."""
    n = len(css)
    while i < n:
        debut = i
        ouvre = css.find("{", i)
        if ouvre == -1:
            return
        ferme_simple = css.find(";", i)
        if css[debut:].lstrip().startswith("@") and ferme_simple != -1 and ferme_simple < ouvre:
            i = ferme_simple + 1  # @import, @charset…
            continue
        profondeur, j = 1, ouvre + 1
        while j < n and profondeur:
            if css[j] == "{":
                profondeur += 1
            elif css[j] == "}":
                profondeur -= 1
            j += 1
        yield css[debut:ouvre].strip(), css[ouvre + 1:j - 1]
        i = j


def traiter(css: str) -> list[str]:
    sortie: list[str] = []
    for entete, corps in blocs(css):
        if entete.startswith("@"):
            nom = entete.split()[0].lower()
            if nom in ("@media", "@supports", "@layer", "@container"):
                if nom == "@media" and "print" in entete and "screen" not in entete:
                    continue
                interieur = traiter(corps)
                if interieur:
                    sortie.append(entete + " {\n" + "\n".join("\t" + l for l in "\n".join(interieur).splitlines()) + "\n}")
            continue  # @font-face, @keyframes, @page…
        declarations = []
        for decl in corps.split(";"):
            if ":" not in decl:
                continue
            prop, val = decl.split(":", 1)
            prop, val = prop.strip().lower(), val.strip()
            if prop.startswith("--"):
                continue
            quoi = role(prop)
            if not quoi:
                continue
            important = ""
            if val.endswith("!important"):
                val, important = val[: -len("!important")].rstrip(), " !important"
            nouveau = convertir_valeur(val, quoi)
            if nouveau:
                declarations.append(f"\t{prop}: {nouveau}{important};")
        if declarations:
            sortie.append(prefixer(entete) + " {\n" + "\n".join(declarations) + "\n}")
    return sortie


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    source, cible = Path(sys.argv[1]), Path(sys.argv[2])
    regles = traiter(sans_commentaires(source.read_text(encoding="utf-8")))
    entete = (
        "/*\n"
        f" * Mode sombre — GÉNÉRÉ depuis {source.name} par outils/mode_sombre.py.\n"
        " * Ne pas modifier à la main : relancer le script après toute modification\n"
        " * de la feuille source.\n"
        " */\n\n"
    )
    cible.write_text(entete + "\n\n".join(regles) + "\n", encoding="utf-8")
    cible.chmod(0o644)
    print(f"{cible} : {len(regles)} règles")
    return 0


if __name__ == "__main__":
    sys.exit(main())
