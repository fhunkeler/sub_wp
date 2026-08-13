<?php

declare(strict_types=1);

/**
 * Socle commun aux outils de reprise Joomla.
 *
 * Aucun identifiant n'est écrit ici. La connexion à la base héritée se règle
 * une fois par des options WordPress, ce qui évite deux écueils : un mot de
 * passe qui traîne dans un fichier livré, et un outil qui ne fonctionne que
 * sur le poste où il a été écrit.
 *
 *   wp option update subalcatel_legacy_db_name   c1subalcat2020
 *   wp option update subalcatel_legacy_db_user   reprise
 *   wp option update subalcatel_legacy_db_pass   '…'
 *   wp option update subalcatel_legacy_db_host   localhost
 *   wp option update subalcatel_legacy_db_prefix jml_
 *
 * Le compte utilisé devrait n'avoir que le droit SELECT : la base héritée est
 * une pièce d'archive, jamais une base de travail.
 */

use Subalcatel\Club\Import\LegacySource;

/**
 * Source héritée configurée, ou arrêt avec le mode d'emploi.
 */
function sub_import_source(): LegacySource
{
    $source = LegacySource::fromSettings();

    if ($source === null) {
        fwrite(STDERR, <<<'TXT'

        La base héritée n'est pas configurée.

          wp option update subalcatel_legacy_db_name   <base>
          wp option update subalcatel_legacy_db_user   <utilisateur>
          wp option update subalcatel_legacy_db_pass   <mot de passe>
          wp option update subalcatel_legacy_db_host   <hôte>
          wp option update subalcatel_legacy_db_prefix jml_

        Le compte n'a besoin que du droit SELECT.

        TXT);
        exit(1);
    }

    if (!$source->isReady()) {
        fwrite(STDERR, sprintf(
            "\nBase héritée injoignable : %s\n\n",
            $source->lastError() ?: 'aucune table trouvée avec ce préfixe.'
        ));
        exit(1);
    }

    return $source;
}

/**
 * Dossier de transit des images, réglable par option.
 *
 * Il ne contient QUE les fichiers cités par un article, triés à part : le
 * dossier `images/` du Joomla contient des webshells et n'est jamais copié
 * en bloc vers le nouveau site.
 */
function sub_import_staging(): string
{
    $dir = (string) get_option('subalcatel_legacy_media_dir', '');

    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, <<<'TXT'

        Zone de transit des images absente.

          wp option update subalcatel_legacy_media_dir /chemin/vers/transit

        Elle doit contenir les images triées et un index.tsv
        (« fichier<TAB>référence-d-origine » par ligne).

        TXT);
        exit(1);
    }

    return rtrim($dir, '/');
}

/** Les outils écrivent seulement si on le demande explicitement. */
function sub_import_is_dry_run(array $args): bool
{
    return !in_array('write', $args, true);
}
