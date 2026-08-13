#!/usr/bin/env python3
"""
Construit les archives d'installation du plugin et du thème Sub Alcatel.

    python3 build-packages.py [dossier-de-sortie]

Les archives produites s'installent par « Extensions → Ajouter → Téléverser »
et « Apparence → Thèmes → Ajouter → Téléverser ».

Ce qui est volontairement exclu :
  - tests/       suites de fumée : inutiles en production, et porteuses des
                 identifiants de la base de développement ;
  - .git, node_modules, fichiers d'éditeur et de système.

Les outils de reprise (tools/) sont conservés : ils servent sur le serveur cible
lors de la migration et ne contiennent aucun identifiant — la source héritée se
règle par des options WordPress.
"""

from __future__ import annotations

import re
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

RACINE = Path(__file__).resolve().parent

# Deux emplacements, un seul script. En local il vit à la racine du projet et
# les extensions sont sous `wordpress/www/wp-content` ; dans le dépôt publié,
# cette racine EST `wp-content`, et l'action GitHub lance le même fichier.
SOURCE = RACINE / "wordpress/www/wp-content"
if not SOURCE.is_dir():
    SOURCE = RACINE

PLUGIN = ("plugins", "subalcatel-club")
THEME = ("themes", "subalcatel")

ROUGE, VERT, JAUNE, GRIS, NUL = "\033[31m", "\033[32m", "\033[33m", "\033[90m", "\033[0m"

# Répertoires jamais empaquetés.
DOSSIERS_EXCLUS = {".git", ".github", "node_modules", "vendor", ".idea", ".vscode", "__pycache__"}

# Fichiers jamais empaquetés.
FICHIERS_EXCLUS = re.compile(
    r"(^\.DS_Store$|^Thumbs\.db$|^\.env|\.log$|\.swp$|~$|^\.gitignore$)"
)

# Motifs de fuite réelle.
#
# Choisis pour être PRÉCIS plutôt que larges : une détection générique du mot
# « password » signale « 'password' => 'password' », qui n'est qu'une
# correspondance de nom de champ. Un contrôle qui crie au loup finit désactivé,
# et c'est alors la vraie fuite qui passe.
SECRETS = [
    (re.compile(r"somewordpress"), "mot de passe de la base de développement"),
    (re.compile(r"new\s+LegacySource\s*\(\s*['\"]"), "source héritée câblée en dur"),
    (re.compile(r"BEGIN [A-Z ]*PRIVATE KEY"), "clé privée"),
    (re.compile(r"define\s*\(\s*['\"]DB_(PASSWORD|USER)"), "identifiants de base"),
    (re.compile(r"SUBALCATEL_DOC_KEY\s*=\s*['\"][A-Za-z0-9+/]{20,}"), "clé des certificats médicaux"),
]

EXTENSIONS_INSPECTEES = {".php", ".js", ".json", ".css", ".txt", ".md"}


def paquets(dossier: Path, exclusions_paquet: set[str]) -> list[Path]:
    """Fichiers à empaqueter, dans un ordre stable."""
    retenus = []

    for chemin in sorted(dossier.rglob("*")):
        if not chemin.is_file():
            continue

        parties = chemin.relative_to(dossier).parts

        if DOSSIERS_EXCLUS.intersection(parties) or exclusions_paquet.intersection(parties):
            continue
        if FICHIERS_EXCLUS.search(chemin.name):
            continue

        retenus.append(chemin)

    return retenus


def verifier_secrets(fichiers: list[Path], dossier: Path) -> list[str]:
    """Signale tout identifiant qui partirait dans l'archive.

    Le contrôle a lieu AVANT la compression : découvrir la fuite après
    distribution ne sert plus à rien — une archive livrée ne se rappelle pas.
    """
    incidents = []

    for chemin in fichiers:
        if chemin.suffix.lower() not in EXTENSIONS_INSPECTEES:
            continue

        try:
            contenu = chemin.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue

        for motif, description in SECRETS:
            trouve = motif.search(contenu)
            if trouve is None:
                continue

            ligne = contenu[: trouve.start()].count("\n") + 1
            incidents.append(
                f"{chemin.relative_to(dossier)}:{ligne} — {description}"
            )

    return incidents


def verifier_entete(dossier: Path, slug: str) -> tuple[str, list[str]]:
    """Version déclarée, et alertes sur l'en-tête d'installation."""
    alertes = []

    if slug == PLUGIN[1]:
        principal = dossier / f"{slug}.php"
        motif_version = re.compile(r"^\s*\*\s*Version:\s*(.+)$", re.M)
        motif_nom = re.compile(r"^\s*\*\s*Plugin Name:\s*(.+)$", re.M)
    else:
        principal = dossier / "style.css"
        motif_version = re.compile(r"^Version:\s*(.+)$", re.M)
        motif_nom = re.compile(r"^Theme Name:\s*(.+)$", re.M)

    if not principal.exists():
        return "0.0.0", [f"fichier d'en-tête absent : {principal.name}"]

    entete = principal.read_text(encoding="utf-8", errors="ignore")[:2000]

    version = motif_version.search(entete)
    nom = motif_nom.search(entete)

    if nom is None:
        alertes.append("en-tête sans nom déclaré — WordPress refusera l'archive")
    if version is None:
        alertes.append("en-tête sans version")

    # Un thème bloc doit fournir index.html, sans quoi WordPress le refuse.
    if slug == THEME[1] and not (dossier / "templates/index.html").exists():
        alertes.append("thème bloc sans templates/index.html")

    return (version.group(1).strip() if version else "0.0.0"), alertes


def construire(famille: str, slug: str, sortie: Path, exclusions: set[str]) -> bool:
    dossier = SOURCE / famille / slug

    if not dossier.is_dir():
        print(f"  {ROUGE}ABSENT{NUL} {dossier}")
        return False

    version, alertes = verifier_entete(dossier, slug)
    fichiers = paquets(dossier, exclusions)
    incidents = verifier_secrets(fichiers, dossier)

    for alerte in alertes:
        print(f"  {JAUNE}ALERTE{NUL} {alerte}")

    if incidents:
        for incident in incidents:
            print(f"  {ROUGE}SECRET{NUL} {incident}")
        print(f"\n  {ROUGE}Archive {slug} NON construite : des identifiants y figurent.{NUL}")
        return False

    archive = sortie / f"{slug}-{version}.zip"
    archive.unlink(missing_ok=True)

    # `deflated` et non `stored` : l'archive voyage par formulaire web, où la
    # taille compte plus que la vitesse de décompression.
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for chemin in fichiers:
            # WordPress exige UN dossier racine nommé comme le slug ; il refuse
            # l'archive dont les fichiers sont à plat.
            interne = Path(slug) / chemin.relative_to(dossier)
            zf.write(chemin, interne.as_posix())

    poids = archive.stat().st_size
    print(
        f"  {VERT}{slug:<22}{NUL} v{version:<8} "
        f"{poids / 1024:7.0f} Ko  {len(fichiers):3d} fichiers"
    )
    print(f"  {GRIS}{archive}{NUL}")

    return True


def main() -> int:
    sortie = Path(sys.argv[1]) if len(sys.argv) > 1 else RACINE / "dist"
    sortie.mkdir(parents=True, exist_ok=True)

    print(f"\nConstruction des archives Sub Alcatel")
    print(f"{GRIS}source : {SOURCE}{NUL}")
    print(f"{GRIS}date   : {datetime.now(timezone.utc):%Y-%m-%d %H:%M UTC}{NUL}\n")

    ok = construire(*PLUGIN, sortie, exclusions={"tests"})
    print()
    ok &= construire(*THEME, sortie, exclusions=set())

    print(f"\n{'Archives prêtes dans ' + str(sortie) if ok else ROUGE + 'Construction incomplète.' + NUL}\n")

    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
