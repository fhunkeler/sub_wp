<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

use RuntimeException;

/**
 * Envoi groupé refusé : il dépasserait le plafond quotidien.
 *
 * Hérite de RuntimeException pour que les écrans qui affichent déjà les refus
 * d'annonce le montrent tel quel, sans traitement particulier.
 */
final class QuotaExceeded extends RuntimeException
{
}
