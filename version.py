#!/usr/bin/env python3
"""Calcule le numéro de version d'un paquet à partir de l'historique.

    python3 version.py plugin
    python3 version.py theme

Le numéro cesse d'être une intention saisie à la main pour devenir une
**conséquence** de ce qui a été commité. Deux pannes symétriques l'ont rendu
nécessaire :

  - une fusion a monté l'extension en 0.14.4 alors qu'elle n'ajoutait que des
    fichiers de `.github/`, absents de l'archive : une release proposée aux
    sites, sans rien dedans ;
  - deux fusions suivantes ont livré un export et un écran sans qu'aucune
    release ne les emporte, le numéro ayant déjà été consommé.

Le calcul répond à deux questions, dans cet ordre.

**Faut-il publier ?** Oui si, depuis l'étiquette de la dernière release, un
commit a touché un fichier **réellement empaqueté**. C'est la même frontière
que `build-packages.py` : `tests/` en est exclu, `.github/` n'y a jamais été.
Un commit qui ne change rien à l'archive ne publie rien, quel que soit son
type — c'est ce qui aurait évité la release vide.

**De combien ?** Par le type des commits, à la convention *Conventional
Commits* :

  - une rupture (`feat!:`, ou `BREAKING CHANGE:` dans le corps) → majeure ;
  - `feat:` → mineure ;
  - tout le reste → correctif.

Un commit que la convention ne reconnaît pas vaut un correctif, et le dit.
La rigueur se joue à l'entrée — le contrôle de la pull request refuse un
message hors convention — pas ici : l'historique déjà écrit ne se réécrit pas,
et un calcul qui échouerait dessus ne publierait plus rien du tout.

**Tant que la majeure est 0**, une rupture ne monte qu'en mineure. Passer en
1.0 dit « cette interface est stable » : c'est une décision, pas la
conséquence d'un point d'exclamation.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent

# Les deux paquets publiés : le préfixe de leur étiquette, leur dossier, et le
# fichier qui porte leur en-tête d'installation.
PAQUETS = {
    "plugin": {
        "prefixe": "plugin",
        "chemin": "plugins/subalcatel-club",
        "entete": "plugins/subalcatel-club/subalcatel-club.php",
        "nom": "Extension",
    },
    "theme": {
        "prefixe": "theme",
        "chemin": "themes/subalcatel",
        "entete": "themes/subalcatel/style.css",
        "nom": "Thème",
    },
}

# Ce qui vit dans le dossier d'un paquet sans partir dans son archive. Doit
# rester d'accord avec `build-packages.py` : deux frontières différentes
# ramèneraient exactement la panne que ce script existe pour empêcher.
NON_EMPAQUETE = {
    "plugin": ["tests"],
    "theme": [],
}

# `type(portée)!: sujet` — la portée et le point d'exclamation sont optionnels.
CONVENTION = re.compile(
    r"^(?P<type>[a-z]+)(?P<portee>\([^)]+\))?(?P<rupture>!)?: .+"
)

RUPTURE_CORPS = re.compile(r"^BREAKING[ -]CHANGE:", re.M)

FONCTIONNALITE = "feat"


def git(*arguments: str) -> str:
    resultat = subprocess.run(
        ["git", *arguments],
        cwd=RACINE,
        capture_output=True,
        text=True,
        check=False,
    )

    if resultat.returncode != 0:
        raise SystemExit(f"git {' '.join(arguments)} : {resultat.stderr.strip()}")

    return resultat.stdout.strip()


def derniere_etiquette(prefixe: str) -> str | None:
    """L'étiquette de la dernière release de ce paquet, ou None s'il n'y en a pas.

    `--sort=-v:refname` trie en version, pas en texte : sans lui, `0.9.0`
    passerait après `0.14.0`.
    """
    etiquettes = git("tag", "--list", f"{prefixe}-*", "--sort=-v:refname")

    return etiquettes.splitlines()[0] if etiquettes else None


def version_de(etiquette: str | None, prefixe: str) -> tuple[int, int, int]:
    if etiquette is None:
        return (0, 0, 0)

    nombres = etiquette[len(prefixe) + 1 :].split(".")

    try:
        majeure, mineure, correctif = (int(nombre) for nombre in nombres[:3])
    except ValueError as erreur:
        raise SystemExit(f"Étiquette illisible : {etiquette}") from erreur

    return (majeure, mineure, correctif)


def commits(etiquette: str | None, paquet: str) -> list[tuple[str, str]]:
    """Les commits qui ont touché l'archive de ce paquet depuis la dernière release.

    Les fusions sont écartées : leur message est `Merge pull request #N…`, qui
    ne dit rien du travail et n'entre dans aucune convention. Ce qu'elles
    apportent est déjà dans les commits qu'elles amènent.
    """
    chemin = PAQUETS[paquet]["chemin"]
    portee = [chemin] + [f":(exclude){chemin}/{dossier}" for dossier in NON_EMPAQUETE[paquet]]
    plage = [f"{etiquette}..HEAD"] if etiquette else ["HEAD"]

    # \x1e sépare les commits, \x1f le sujet du corps : n'importe quel autre
    # séparateur finirait par apparaître dans un message.
    brut = git("log", "--no-merges", "--format=%s%x1f%b%x1e", *plage, "--", *portee)

    retenus = []

    for bloc in brut.split("\x1e"):
        bloc = bloc.strip("\n")

        if not bloc:
            continue

        sujet, _, corps = bloc.partition("\x1f")
        retenus.append((sujet.strip(), corps))

    return retenus


def palier(messages: list[tuple[str, str]]) -> tuple[str, list[str]]:
    """Le palier appelé par ces commits, et les messages hors convention."""
    niveau = "correctif"
    hors_convention = []

    for sujet, corps in messages:
        trouve = CONVENTION.match(sujet)

        if trouve is None:
            hors_convention.append(sujet)
            continue

        if trouve.group("rupture") or RUPTURE_CORPS.search(corps):
            niveau = "majeure"
        elif trouve.group("type") == FONCTIONNALITE and niveau != "majeure":
            niveau = "mineure"

    return niveau, hors_convention


def suivante(actuelle: tuple[int, int, int], niveau: str) -> tuple[int, int, int]:
    majeure, mineure, correctif = actuelle

    # Avant 1.0, une rupture ne fait pas passer le cap : rien n'a encore été
    # promis à personne, et annoncer 1.0 est une décision qui se prend.
    if niveau == "majeure" and majeure == 0:
        niveau = "mineure"

    if niveau == "majeure":
        return (majeure + 1, 0, 0)
    if niveau == "mineure":
        return (majeure, mineure + 1, 0)

    return (majeure, mineure, correctif + 1)


# Où le numéro se lit, pour chaque paquet. Une seule liste, parcourue aussi
# bien pour écrire que pour vérifier : c'est ce qui empêche un de ces endroits
# de rester en arrière, comme la constante l'a fait pendant deux versions.
#
# La constante n'est pas décorative : elle date le cache des feuilles de style
# et des scripts. Restée en arrière, elle sert le nouveau code sous l'ancien
# numéro, et les navigateurs gardent l'ancien.
EMPLACEMENTS = {
    "plugin": [
        ("plugins/subalcatel-club/subalcatel-club.php", r"^(\s*\*\s*Version:\s*)(\S+)(\s*)$"),
        ("plugins/subalcatel-club/subalcatel-club.php", r"^(const VERSION\s*=\s*')([^']+)(';)$"),
        ("plugins/subalcatel-club/readme.md", r"^(Version \*\*)([^*]+)(\*\*)"),
    ],
    "theme": [
        ("themes/subalcatel/style.css", r"^(Version:\s*)(\S+)(\s*)$"),
    ],
}


def poser(paquet: str, version: str) -> list[str]:
    """Écrit le numéro partout où il se lit. Retourne les fichiers modifiés."""
    modifies = []

    for relatif, motif in EMPLACEMENTS[paquet]:
        fichier = RACINE / relatif
        contenu = fichier.read_text(encoding="utf-8")

        nouveau, remplaces = re.subn(
            motif,
            lambda trouve: trouve.group(1) + version + trouve.group(3),
            contenu,
            count=1,
            flags=re.M,
        )

        if remplaces == 0:
            raise SystemExit(f"Numéro introuvable dans {relatif} — motif : {motif}")

        if nouveau != contenu:
            fichier.write_text(nouveau, encoding="utf-8")
            modifies.append(relatif)

    return modifies


# Les types admis, et ce qu'ils disent. `feat` monte la mineure, tous les
# autres le correctif — voir {@see palier}. La liste est fermée : « amelioration »
# ou « wip » passeraient sans rien signifier au calcul, et le numéro cesserait
# de vouloir dire quelque chose.
TYPES = {
    "feat":     "une fonctionnalité",
    "fix":      "une correction",
    "perf":     "une performance",
    "refactor": "une réécriture sans changement de comportement",
    "revert":   "l’annulation d’un commit",
    "docs":     "de la documentation",
    "style":    "de la mise en forme, sans effet",
    "test":     "des tests",
    "build":    "la construction ou les dépendances",
    "ci":       "l’intégration continue",
    "chore":    "de l’entretien",
}


def defaut_de(sujet: str) -> str | None:
    """Ce qui manque à ce sujet pour entrer dans la convention, ou None."""
    trouve = CONVENTION.match(sujet)

    if trouve is None:
        return "il ne commence pas par « type: » (ni « type(portée): »)"

    if trouve.group("type") not in TYPES:
        return f"« {trouve.group('type')} » n’est pas un type admis"

    return None


def verifier(sujets: list[str]) -> int:
    """Contrôle une liste de sujets. Retourne le nombre de refus."""
    refuses = 0

    for sujet in sujets:
        defaut = defaut_de(sujet)

        if defaut is None:
            print(f"  ok   {sujet}", file=sys.stderr)
            continue

        refuses += 1
        print(f"  NON  {sujet}\n       → {defaut}", file=sys.stderr)

    if refuses:
        print(
            "\nLes messages décident du numéro de la prochaine version : un sujet\n"
            "hors convention rendrait ce calcul arbitraire. Types admis —\n"
            + "\n".join(f"  {type_:<9} {sens}" for type_, sens in TYPES.items())
            + "\n\nExemples :\n"
            "  feat: l’extrait des adhésions que le bureau demandait\n"
            "  fix(import): le numéro de licence était écrit sous une clé morte\n"
            "  feat!: le profil ne porte plus l’adresse postale\n\n"
            "Pour corriger le dernier message : git commit --amend\n"
            "Pour les précédents : git rebase -i, puis « reword ».",
            file=sys.stderr,
        )

    return refuses


def main() -> int:
    # Contrôle d'une plage de commits — appelé par la vérification de la
    # pull request.
    if len(sys.argv) == 4 and sys.argv[1] == "--verifier":
        base, tete = sys.argv[2], sys.argv[3]
        sujets = [
            ligne
            for ligne in git("log", "--no-merges", "--format=%s", f"{base}..{tete}").splitlines()
            if ligne.strip()
        ]

        if not sujets:
            print("Aucun commit à contrôler.", file=sys.stderr)
            return 0

        return 1 if verifier(sujets) else 0

    # Contrôle d'un message en cours d'écriture — appelé par le hook local.
    if len(sys.argv) == 3 and sys.argv[1] == "--verifier-message":
        contenu = Path(sys.argv[2]).read_text(encoding="utf-8")
        sujet = next(
            (ligne for ligne in contenu.splitlines() if ligne.strip() and not ligne.startswith("#")),
            "",
        )

        return 1 if verifier([sujet]) else 0

    if len(sys.argv) < 2 or sys.argv[1] not in PAQUETS:
        nom = Path(sys.argv[0]).name
        print(
            f"usage : {nom} {{{'|'.join(PAQUETS)}}} [--poser X.Y.Z]\n"
            f"        {nom} --verifier <base> <tête>\n"
            f"        {nom} --verifier-message <fichier>",
            file=sys.stderr,
        )
        return 2

    paquet = sys.argv[1]

    if len(sys.argv) == 4 and sys.argv[2] == "--poser":
        for relatif in poser(paquet, sys.argv[3]):
            print(f"{relatif} → {sys.argv[3]}", file=sys.stderr)
        return 0

    if len(sys.argv) != 2:
        print(f"usage : {Path(sys.argv[0]).name} {paquet} [--poser X.Y.Z]", file=sys.stderr)
        return 2

    prefixe = PAQUETS[paquet]["prefixe"]
    nom = PAQUETS[paquet]["nom"]

    etiquette = derniere_etiquette(prefixe)
    actuelle = version_de(etiquette, prefixe)
    messages = commits(etiquette, paquet)

    depuis = etiquette or "l’origine du dépôt"

    if not messages:
        print(f"{nom} — rien d’empaqueté n’a changé depuis {depuis}.", file=sys.stderr)
        print("publier=non")
        return 0

    niveau, hors_convention = palier(messages)
    nouvelle = suivante(actuelle, niveau)
    texte = ".".join(str(nombre) for nombre in nouvelle)

    print(
        f"{nom} — {len(messages)} commit(s) empaqueté(s) depuis {depuis}, "
        f"palier {niveau} : "
        f"{'.'.join(str(n) for n in actuelle)} → {texte}",
        file=sys.stderr,
    )

    for sujet in hors_convention:
        print(f"  hors convention, compté comme correctif : {sujet}", file=sys.stderr)

    print("publier=oui")
    print(f"version={texte}")
    print(f"etiquette={prefixe}-{texte}")
    print(f"niveau={niveau}")
    print(f"commits={len(messages)}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
