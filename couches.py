#!/usr/bin/env python3
"""
Vérifie que les dossiers de l'extension se superposent sans boucle.

    python3 couches.py

Chaque dossier de `plugins/subalcatel-club/src/` est une couche. Une couche ne
peut utiliser que celles qui sont **en dessous** d'elle dans `COUCHES`. Le
contrôle échoue sur toute référence qui remonte, et sur tout dossier qui n'est
pas encore rangé.

Pourquoi
--------
Jusqu'à la 1.56, chaque dossier finissait par dépendre de tous les autres : le
courriel connaissait les représentants légaux, l'entretien quotidien appelait
un écran d'administration, les comptes appelaient le formulaire d'inscription.
On ne pouvait rien isoler, ni rien sortir dans une extension à part — ce qui
est la condition d'un cœur distribuable et de modules optionnels.

Quand une couche basse a besoin d'une haute, trois issues, dans cet ordre :

  1. la classe est mal rangée : la déplacer ;
  2. seule une donnée est partagée (un nom de page, de tâche…) : la descendre
     dans `Support` (voir `AdminLinks`, `Cron`) ;
  3. la couche basse doit vraiment déclencher un comportement plus haut : une
     action ou un filtre WordPress, que la couche haute écoute (voir
     `Mailer::GUARDIAN_FILTER`, `TechnicalAccounts::MARKED_ACTION`).

Ce que le contrôle lit : les `use Subalcatel\\Club\\X\\…` et les noms qualifiés
`\\Subalcatel\\Club\\X\\…`, hors commentaires et chaînes. Le fichier racine
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
    "Support",        # outils sans dépendance : journal, PDF, réponses, noms partagés
    "Notifications",  # courriels, modèles, file et quota d'envoi
    "Identity",       # comptes, rôles, niveaux, représentants légaux
    "Documents",      # pièces des membres et leur stockage
    "Communication",  # listes, abonnements, messages au club
    "Membership",     # campagnes, tarifs, dossiers d'adhésion
    "Policy",         # éligibilité : croise comptes, documents et adhésions
    "Content",        # pages du site, visibilité, documents du club
    "Setup",          # construction du site, mises à jour
    "Events",         # sorties, inscriptions, agenda
    "Exports",        # tableaux et fichiers pour le bureau
    "Privacy",        # purge et données personnelles : touche à tout
    "Database",       # schéma et migrations, qui appellent les domaines
    "Security",       # connexion, mots de passe, double authentification
    "Maintenance",    # entretien quotidien, état du site
    "Frontend",       # formulaires et codes courts du site public
    "Admin",          # écrans d'administration
]

COMMENTAIRES = re.compile(r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*", re.S)
CHAINES = re.compile(r"'(?:[^'\\]|\\.)*'")
REFERENCE = re.compile(r"\\?Subalcatel\\Club\\([A-Z][A-Za-z]+)\\[A-Z][A-Za-z]+")


def main() -> int:
    rang = {couche: n for n, couche in enumerate(COUCHES)}
    erreurs: list[str] = []

    presents = sorted(d.name for d in SRC.iterdir() if d.is_dir())
    for dossier in presents:
        if dossier not in rang:
            erreurs.append(
                f"{SRC.relative_to(RACINE)}/{dossier}/ : dossier non rangé — l'ajouter à COUCHES dans couches.py"
            )

    for fichier in sorted(SRC.rglob("*.php")):
        relatif = fichier.relative_to(SRC)
        if len(relatif.parts) == 1:
            continue  # Plugin.php

        couche = relatif.parts[0]
        if couche not in rang:
            continue

        code = fichier.read_text(encoding="utf-8")
        nettoye = CHAINES.sub("''", COMMENTAIRES.sub(lambda m: "\n" * m.group(0).count("\n"), code))

        for trouve in REFERENCE.finditer(nettoye):
            cible = trouve.group(1)
            if cible == couche or cible not in rang or rang[cible] < rang[couche]:
                continue

            ligne = nettoye[: trouve.start()].count("\n") + 1
            chemin = fichier.relative_to(RACINE)
            erreurs.append(
                f"{chemin}:{ligne} : {couche} utilise {trouve.group(0).lstrip(chr(92))}, "
                f"qui est au-dessus de lui"
            )
            if "GITHUB_ACTIONS" in os.environ:
                print(f"::error file={chemin},line={ligne}::{couche} → {cible} : référence à contresens")

    for erreur in erreurs:
        print(erreur)

    print(f"{len(COUCHES)} couches, {len(erreurs)} infraction(s).")
    return 1 if erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
