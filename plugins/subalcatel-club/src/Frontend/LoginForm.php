<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/*
 * Ancien nom, gardé pour le thème.
 *
 * Le formulaire de connexion vit désormais dans `Identity\Frontend`. Le thème
 * l'appelle encore sous ce nom (`inc/blocks.php`), et il est publié à part :
 * un site peut faire tourner un thème plus ancien que l'extension. Cet alias
 * garde les deux compatibles. À retirer quand le thème appellera le nouveau
 * nom et que tous les sites l'auront reçu.
 */
class_alias(\Subalcatel\Club\Identity\Frontend\LoginForm::class, LoginForm::class);
