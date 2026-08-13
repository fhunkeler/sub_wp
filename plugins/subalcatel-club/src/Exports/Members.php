<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

/**
 * Aides partagées par les exports de membres.
 */
final class Members
{
    /**
     * @return list<\WP_User>
     */
    public static function all(): array
    {
        return get_users([
            'role__in' => ['sub_member', 'sub_office', 'sub_guest'],
            'orderby'  => 'display_name',
            'number'   => 2000,
        ]);
    }

    public static function frDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '' || $isoDate === '0000-00-00') {
            return '';
        }

        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('d/m/Y', $ts);
    }
}
