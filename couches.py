#!/usr/bin/env python3
r"""
Vérifie que le code de l'extension se superpose sans boucle.

    python3 couches.py

Le code est rangé **par domaine** : `Events/`, `Membership/`, `Documents/`…
Chaque domaine porte son code métier à sa racine, ses écrans dans deux
sous-dossiers, `Admin/` (administration) et `Frontend/` (site public), et ses
tableaux pour le bureau dans `Exports/`.

Chaque espace de noms est une couche, et `COUCHES` les range de la plus basse à
la plus haute. Une couche n'utilise que celles qui sont en dessous d'elle. Le
contrôle échoue sur toute référence qui remonte, et sur tout dossier qui n'est
pas encore rangé.

Deux bandes :

  - **le code métier** des domaines, en bas. Il ne connaît aucun écran ;
  - **les exports et les écrans** (`X\Exports`, `X\Admin`, `X\Frontend`), au-dessus de tout le code
    métier : un écran peut utiliser n'importe quel domaine, mais seulement les
    écrans rangés sous lui. `Site` assemble le tout (menu du club, tableau de
    bord, réglages, espace membre) et se trouve en haut.

Pourquoi
--------
Jusqu'à la 1.56, chaque dossier finissait par dépendre de tous les autres. On ne
pouvait rien isoler, ni rien sortir dans une extension à part — ce qui est la
condition d'un cœur distribuable et de modules optionnels. Un domaine, avec ses
écrans, est désormais une unité que l'on peut lire, et un jour sortir, seule.

Quand une couche basse a besoin d'une haute, trois issues, dans cet ordre :

  1. la classe est mal rangée : la déplacer ;
  2. seule une donnée est partagée (un nom de page, de tâche, un format de
     date…) : la descendre dans `Support` ou `Ui` (voir `AdminLinks`, `Cron`,
     `Ui\Dates`) ;
  3. la couche basse doit vraiment déclencher un comportement plus haut : une
     action ou un filtre WordPress, que la couche haute écoute (voir
     `Mailer::GUARDIAN_FILTER`, `TechnicalAccounts::MARKED_ACTION`).

Ce que le contrôle lit : les `use Subalcatel\Club\…` et les noms qualifiés
`\Subalcatel\Club\…`, hors commentaires et chaînes. Le fichier racine
(`Plugin.php`) assemble tout et peut tout utiliser.
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent
SRC = RACINE / "plugins/subalcatel-club/src"

# De la plus basse à la plus haute.
COUCHES = [
    # --- Code métier ---------------------------------------------------------
    "Support",              # outils sans dépendance : journal, PDF, noms partagés
    "Ui",                   # briques d'écran communes : formulaires, avis, graphiques, dates
    "Exports",              # socle des exports : registre, CSV, Excel ; journal d'audit
    "Notifications",        # courriels, modèles, file et quota d'envoi
    "Identity",             # comptes, rôles, niveaux, représentants légaux
    "Documents",            # pièces des membres et leur stockage
    "Communication",        # listes, abonnements, messages au club
    "Membership",           # campagnes, tarifs, dossiers d'adhésion
    "Policy",               # éligibilité : croise comptes, documents et adhésions
    "Content",              # pages du site, visibilité, documents du club
    "Setup",                # construction du site, mises à jour
    "Events",               # sorties, inscriptions, agenda
    "Privacy",              # purge et données personnelles : touche à tout
    "Database",             # schéma et migrations, qui appellent les domaines
    "Security",             # connexion, mots de passe, double authentification
    "Maintenance",          # entretien quotidien, état du site
    # --- Exports de chaque domaine ----------------------------------------------
    "Identity\\Exports",
    "Documents\\Exports",
    "Communication\\Exports",
    "Membership\\Exports",
    "Events\\Exports",
    # --- Écrans ----------------------------------------------------------------
    "Notifications\\Admin",
    "Identity\\Frontend",   # connexion, inscription, profil
    "Documents\\Frontend",
    "Documents\\Admin",
    "Identity\\Admin",      # l'annuaire, qui accueille l'onglet des pièces
    "Content\\Frontend",
    "Communication\\Frontend",
    "Communication\\Admin", # accueille l'onglet des modèles de courriel
    "Membership\\Frontend",
    "Membership\\Admin",
    "Setup\\Admin",
    "Exports\\Admin",
    "Events\\Frontend",
    "Events\\Admin",        # porte les boutons d'export de la feuille d'émargement
    "Content\\Admin",       # documents du club, qui renvoient aux feuilles d'émargement
    "Security\\Admin",
    "Maintenance\\Admin",
    "Site\\Frontend",       # espace membre, barre d'administration
    "Site\\Admin",          # menu du club, tableau de bord, réglages, statistiques
    # --- Compatibilité ---------------------------------------------------------
    "Frontend",             # alias de LoginForm, que le thème appelle encore
]

COMMENTAIRES = re.compile(r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*", re.S)
CHAINES = re.compile(r"'(?:[^'\\]|\\.)*'")
REFERENCE = re.compile(r"\\?Subalcatel\\Club\\((?:[A-Z][A-Za-z]+\\)+)[A-Z][A-Za-z]+")
NAMESPACE = re.compile(r"^namespace Subalcatel\\Club\\([^;]+);", re.M)


def couche(espace: str, rang: dict[str, int]) -> str | None:
    """La couche la plus précise qui contient cet espace de noms."""
    parties = espace.strip("\\").split("\\")
    for n in range(len(parties), 0, -1):
        candidat = "\\".join(parties[:n])
        if candidat in rang:
            return candidat
    return None


def main() -> int:
    rang = {c: n for n, c in enumerate(COUCHES)}
    erreurs: list[str] = []
    annoter = "GITHUB_ACTIONS" in os.environ

    for dossier in sorted({f.parent for f in SRC.rglob("*.php")} - {SRC}):
        espace = "\\".join(dossier.relative_to(SRC).parts)
        # Un sous-dossier du code métier (`Documents/Storage`) appartient à son
        # domaine ; un dossier d'écrans ou d'exports doit, lui, être rangé
        # explicitement.
        rangé = espace in rang or (
            couche(espace, rang) is not None and dossier.name not in ("Admin", "Frontend", "Exports")
        )
        if not rangé:
            erreurs.append(
                f"{dossier.relative_to(RACINE)}/ : dossier non rangé — l'ajouter à COUCHES dans couches.py"
            )

    for fichier in sorted(SRC.rglob("*.php")):
        code = fichier.read_text(encoding="utf-8")
        declare = NAMESPACE.search(code)
        if declare is None:
            continue  # Plugin.php vit dans Subalcatel\\Club, au-dessus de tout

        source = couche(declare.group(1), rang)
        if source is None:
            continue  # déjà signalé : dossier non rangé

        nettoye = CHAINES.sub("''", COMMENTAIRES.sub(lambda m: "\n" * m.group(0).count("\n"), code))

        for trouve in REFERENCE.finditer(nettoye):
            cible = couche(trouve.group(1), rang)
            if cible is None or cible == source or rang[cible] < rang[source]:
                continue

            ligne = nettoye[: trouve.start()].count("\n") + 1
            chemin = fichier.relative_to(RACINE)
            nom = trouve.group(0).lstrip("\\")
            erreurs.append(f"{chemin}:{ligne} : {source} utilise {nom}, qui est au-dessus de lui")
            if annoter:
                print(f"::error file={chemin},line={ligne}::{source} → {cible} : référence à contresens")

    for erreur in erreurs:
        print(erreur)

    print(f"{len(COUCHES)} couches, {len(erreurs)} infraction(s).")
    return 1 if erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
