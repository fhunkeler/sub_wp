<?php

declare(strict_types=1);

namespace Subalcatel\Club\Setup;

use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Frontend\Pages;

/**
 * Arborescence du site, sous forme déclarative.
 *
 * Reprend le plan de `design-arborescence.md`. Chaque entrée dit où va la page,
 * qui peut la voir, et ce qu'elle contient au départ.
 *
 * Certaines entrées portent un tableau `legacy` : les chemins qu'a pu prendre
 * la même page avant l'installation. Un site où le bureau a créé « /mon-profil/ »
 * à la main doit se voir *réorganisé*, pas doublé.
 *
 * **Le contenu est un point de départ, pas une livraison.** Là où il faut une
 * information que seul le club détient — horaires de piscine, adresse du local,
 * composition du bureau — le texte le dit explicitement plutôt que d'inventer
 * une valeur vraisemblable. Un chiffre inventé qui traîne six mois sur un site
 * associatif fait plus de dégâts qu'un blanc signalé.
 */
final class SiteMap
{
    public const MENU_MAIN   = 'principal';
    public const MENU_MEMBER = 'espace-membre';
    public const MENU_LEGAL  = 'pied-de-page';

    /**
     * @return list<array<string, mixed>>
     */
    public static function pages(): array
    {
        return array_merge(self::publicPages(), self::memberPages(), self::legalPages());
    }

    /**
     * Menus où figure une entrée du plan.
     *
     * Une page peut avoir **deux portes d'entrée**, et `menu` accepte alors une
     * liste. Le cas est celui des documents du club : les statuts et le
     * règlement intérieur se lisent sans compte, ils ont donc leur place dans le
     * menu principal ; l'adhérent connecté retrouve la même page depuis son
     * espace, où s'ajoutent les documents qui lui sont réservés. Une seule page,
     * qui s'adapte à qui la regarde — dupliquer la liste aurait dupliqué
     * l'endroit où le bureau doit penser à publier.
     *
     * @param array<string, mixed> $page
     * @return list<string>
     */
    public static function menusOf(array $page): array
    {
        $menus = array_map('strval', (array) ($page['menu'] ?? []));

        return array_values(array_filter($menus, static fn (string $menu): bool => $menu !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function publicPages(): array
    {
        return [
            [
                'key'        => Pages::HOME,
                'title'      => 'Accueil',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'front_page' => true,
                // Le thème rend l'accueil avec son propre gabarit
                // `front-page.html` — hero, activités, chiffres, actualités.
                // Le contenu de cette page n'est donc pas affiché : elle existe
                // pour que WordPress ait une page d'accueil à désigner. Le mot
                // ci-dessous évite qu'un bénévole rédige dans le vide.
                'content'    => self::note(
                    'Cette page n’affiche pas son contenu : la page d’accueil est composée '
                    . 'par le thème (Apparence → Éditeur → Modèles → Page d’accueil). '
                    . 'Modifiez-la là-bas, pas ici.'
                ),
            ],

            // --- Le club -----------------------------------------------------
            [
                'key'        => 'le-club',
                'title'      => 'Le club',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('Qui sommes-nous')
                    . self::paragraph(
                        'Association loi 1901 affiliée à la Fédération française d’études et '
                        . 'de sports sous-marins.'
                    )
                    . self::note(
                        'À compléter : histoire du club, année de création, valeurs, '
                        . 'esprit associatif, nombre d’adhérents, photos.'
                    ),
            ],
            [
                'key'        => 'le-club/equipe',
                'template'   => 'page-equipe',
                'title'      => 'Le bureau et l’encadrement',
                'parent'     => 'le-club',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('Le bureau')
                    . self::pattern('subalcatel/trombinoscope')
                    . self::note(
                        'Remplacez les portraits et les fonctions. Préférez une adresse de '
                        . 'fonction (president@…) à une adresse personnelle : elle survit aux '
                        . 'changements d’équipe et ne publie les coordonnées de personne.'
                    )
                    . self::heading('L’encadrement', 3)
                    . self::note('À compléter : encadrants, niveau, spécialités.'),
            ],
            [
                'key'        => 'le-club/installations',
                'title'      => 'Nos installations',
                'parent'     => 'le-club',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('La piscine')
                    . self::note('À compléter : adresse, créneaux horaires, plan d’accès.')
                    . self::heading('Le compresseur et le local matériel', 3)
                    . self::note('À compléter : localisation, conditions et horaires d’accès.')
                    . self::heading('Le bateau', 3)
                    . self::note('À compléter : nom, capacité, port d’attache.'),
            ],
            [
                'key'        => 'le-club/partenaires',
                'title'      => 'Partenaires et liens',
                'parent'     => 'le-club',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::paragraph('Les structures avec lesquelles le club travaille.')
                    . self::bullets([
                        'FFESSM — Fédération française d’études et de sports sous-marins',
                        'CODEP — Comité départemental',
                        'Longitude 181 — charte du plongeur responsable',
                        'APECS — Association pour l’étude et la conservation des sélaciens',
                    ])
                    . self::note('À compléter : partenaires locaux, logos, liens.'),
            ],

            // --- Activités ---------------------------------------------------
            [
                'key'        => 'activites',
                'title'      => 'Nos activités',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::paragraph(
                    'Plongée en piscine toute l’année, sorties en mer à la belle saison, '
                    . 'formations fédérales du niveau 1 au niveau 4.'
                ),
            ],
            [
                'key'        => 'activites/piscine',
                'title'      => 'Plongée en piscine',
                'parent'     => 'activites',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::note(
                    'À compléter : créneaux, public accueilli, déroulement d’une séance, '
                    . 'matériel nécessaire et matériel prêté.'
                ),
            ],
            [
                'key'        => 'activites/sorties-mer',
                'title'      => 'Sorties en mer',
                'parent'     => 'activites',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::note(
                    'À compléter : sites fréquentés, organisation d’une sortie type, '
                    . 'consignes de sécurité, galerie photo.'
                ),
            ],
            [
                'key'        => 'activites/bateau',
                'title'      => 'Le bateau',
                'parent'     => 'activites',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::note(
                    'À compléter : nom et présentation du bateau, capacité, règles '
                    . 'd’embarquement, permis et RRF exigés.'
                ),
            ],
            [
                'key'        => 'activites/galerie',
                'template'   => 'page-galerie',
                'title'      => 'Galerie',
                'parent'     => 'activites',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::paragraph('Les photos des sorties et des formations.')
                    . self::note(
                        'À compléter : albums par sortie ou par saison, avec le bloc Galerie '
                        . 'de WordPress. Pensez à l’accord des personnes photographiées.'
                    ),
            ],
            [
                'key'        => 'activites/formations',
                'template'   => 'page-formations',
                'title'      => 'Formations et niveaux',
                'parent'     => 'activites',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::paragraph(
                    'Le club prépare aux brevets fédéraux. Chaque cursus a ses prérequis, '
                    . 'sa durée et sa période.'
                )
                    . self::pattern('subalcatel/carte-formation')
                    . self::note(
                        'Dupliquez la carte ci-dessus pour chaque cursus proposé — N1/PE, '
                        . 'N2/PA, N3, N4, RIFAP, Nitrox — en ajustant prérequis, durée, '
                        . 'période et coût.'
                    ),
            ],

            // --- Actualités et agenda ----------------------------------------
            [
                'key'        => Pages::NEWS,
                'title'      => 'Actualités',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'blog_page'  => true,
                'content'    => '',
            ],
            [
                'key'        => Pages::PUBLIC_AGENDA,
                'template'   => 'page-agenda',
                'title'      => 'Agenda',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('Les rendez-vous du club')
                    . self::paragraph(
                        'Assemblées générales, portes ouvertes et baptêmes sont ouverts à tous. '
                        . 'Les sorties et les formations sont réservées aux adhérents : '
                        . 'connectez-vous pour les voir et vous y inscrire.'
                    )
                    . self::shortcode('[subalcatel_calendrier]'),
            ],

            // --- Nous rejoindre ----------------------------------------------
            [
                'key'        => Pages::JOIN,
                'title'      => 'Nous rejoindre',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('Pourquoi nous rejoindre')
                    . self::paragraph(
                        'Le club accueille les débutants comme les plongeurs confirmés. '
                        . 'L’adhésion donne accès aux créneaux piscine, aux sorties en mer, '
                        . 'aux formations et au prêt de matériel.'
                    )
                    . self::note('À compléter : argumentaire, à qui s’adresse le club, FAQ.')
                    . self::buttons([
                        ['Voir les tarifs', Pages::PRICING],
                        ['Comment adhérer', Pages::HOW_TO_JOIN],
                    ]),
            ],
            [
                'key'        => Pages::PRICING,
                'template'   => 'page-tarifs',
                'title'      => 'Tarifs et options',
                'parent'     => Pages::JOIN,
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                // La grille est lue depuis la campagne configurée : elle suit
                // les tarifs de l'année sans que personne ait à recopier des
                // montants — c'est exactement ce qui avait dérivé sur le Joomla.
                'content'    => self::paragraph(
                    'La cotisation comprend la licence fédérale et l’accès aux activités. '
                    . 'Les options — assurance, prêt de matériel, formation — s’ajoutent '
                    . 'selon vos besoins.'
                )
                    . self::shortcode('[subalcatel_tarifs]'),
            ],
            [
                'key'        => Pages::HOW_TO_JOIN,
                'template'   => 'page-espace-membre',
                'legacy'     => ['adherer'],
                'title'      => 'Comment adhérer',
                'parent'     => Pages::JOIN,
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::heading('Les étapes')
                    . self::numbered([
                        'Créez votre compte sur le site.',
                        'Remplissez le formulaire d’adhésion : formule, options, informations personnelles.',
                        'Réglez par chèque ou par HelloAsso.',
                        'Déposez votre certificat médical de non contre-indication à la plongée.',
                        'Le bureau valide votre dossier — vous recevez une confirmation par courriel.',
                    ])
                    . self::heading('Pièces à fournir', 3)
                    . self::bullets([
                        'Un certificat médical de moins d’un an.',
                        'Une autorisation parentale pour les mineurs.',
                    ])
                    . self::shortcode('[subalcatel_adhesion]'),
            ],
            [
                'key'        => 'nous-rejoindre/bapteme',
                'title'      => 'Baptêmes et séances d’essai',
                'parent'     => Pages::JOIN,
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::paragraph(
                    'Vous voulez essayer avant de vous engager ? Le club organise des '
                    . 'baptêmes encadrés.'
                )
                    . self::note('À compléter : conditions, âge minimum, période, tarif, comment réserver.'),
            ],

            // --- Contact et comptes ------------------------------------------
            [
                'key'        => Pages::CONTACT,
                'template'   => 'page-contact',
                'title'      => 'Contact',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_MAIN,
                'content'    => self::note(
                    'Le gabarit de cette page affiche déjà l’encart de contact du thème : '
                    . 'renseignez-y l’adresse du local, les horaires de permanence et les '
                    . 'contacts par fonction. Le formulaire d’envoi demande une extension '
                    . 'dédiée (Fluent Forms Lite + anti-spam) — voir la proposition, §3.'
                ),
            ],
            [
                'key'        => Pages::LOGIN,
                'title'      => 'Connexion',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'template'   => 'page-connexion',
                'content'    => self::shortcode('[subalcatel_connexion]'),
            ],
            [
                'key'        => Pages::SIGNUP,
                'template'   => 'page-espace-membre',
                'title'      => 'Créer mon compte',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'content'    => self::paragraph(
                    'Un compte vous permet de constituer votre dossier d’adhésion, '
                    . 'de vous inscrire aux sorties et de déposer vos documents. '
                    . 'Le bureau le valide avant que vous puissiez adhérer.'
                )
                    . self::shortcode('[subalcatel_creer_compte]'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function memberPages(): array
    {
        return [
            [
                'key'        => Pages::MEMBER_AREA,
                'template'   => 'page-espace-membre',
                'title'      => 'Espace membre',
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_espace_membre]'),
            ],
            [
                'key'        => Pages::PROFILE,
                'template'   => 'page-espace-membre',
                'legacy'     => ['mon-profil'],
                'title'      => 'Mon profil',
                'parent'     => Pages::MEMBER_AREA,
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_profil]'),
            ],
            [
                'key'        => Pages::MY_DOCUMENTS,
                'template'   => 'page-espace-membre',
                'legacy'     => ['mes-documents'],
                'title'      => 'Mes documents',
                'parent'     => Pages::PROFILE,
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_documents]'),
            ],
            [
                'key'        => Pages::MEMBERSHIP,
                'template'   => 'page-espace-membre',
                'legacy'     => ['mon-adhesion'],
                'title'      => 'Mon adhésion',
                'parent'     => Pages::MEMBER_AREA,
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_mon_adhesion]'),
            ],
            [
                'key'        => Pages::SUBSCRIBE,
                'template'   => 'page-espace-membre',
                'title'      => 'Souscrire ou renouveler',
                'parent'     => Pages::MEMBERSHIP,
                'visibility' => Visibility::CONNECTED,
                'content'    => self::shortcode('[subalcatel_adhesion]'),
            ],
            [
                'key'        => Pages::AGENDA,
                'template'   => 'page-espace-membre',
                'legacy'     => ['agenda-club'],
                'title'      => 'Agenda du club',
                'parent'     => Pages::MEMBER_AREA,
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_agenda]'),
            ],
            [
                'key'        => Pages::CALENDAR,
                'template'   => 'page-espace-membre',
                'legacy'     => ['calendrier'],
                'title'      => 'Calendrier',
                'parent'     => Pages::AGENDA,
                'visibility' => Visibility::CONNECTED,
                'content'    => self::shortcode('[subalcatel_calendrier]'),
            ],
            [
                'key'        => Pages::NEW_OUTING,
                'template'   => 'page-espace-membre',
                'title'      => 'Organiser une sortie',
                'parent'     => Pages::AGENDA,
                'visibility' => Visibility::CONNECTED,
                // Volontairement hors menu : la page ne concerne que les
                // autonomes et les encadrants. Elle s'atteint depuis le tableau
                // de bord, qui n'affiche le raccourci qu'à qui peut s'en servir.
                'content'    => self::paragraph(
                    'Vous proposez une sortie au club. Elle apparaîtra dans l’agenda '
                    . 'dès sa publication, ouverte aux inscriptions des membres dont '
                    . 'le niveau correspond.'
                )
                    . self::shortcode('[subalcatel_creer_sortie]'),
            ],
            [
                'key'        => Pages::MY_OUTINGS,
                'template'   => 'page-espace-membre',
                'title'      => 'Mes sorties organisées',
                'parent'     => Pages::AGENDA,
                'visibility' => Visibility::CONNECTED,
                // Hors menu, comme « Organiser une sortie » : la page ne sert
                // qu'à qui a une liste à consulter. Le tableau de bord affiche
                // le raccourci à ceux-là, et à eux seuls.
                'content'    => self::paragraph(
                    'Les sorties que vous avez ouvertes, avec leurs inscrits : niveau, '
                    . 'téléphone, personne à prévenir, validité des documents. La liste '
                    . 's’imprime en feuille d’émargement.'
                )
                    . self::shortcode('[subalcatel_mes_sorties_organisees]'),
            ],
            [
                'key'        => Pages::REGISTRATIONS,
                'template'   => 'page-espace-membre',
                'legacy'     => ['mes-inscriptions'],
                'title'      => 'Mes inscriptions',
                'parent'     => Pages::MEMBER_AREA,
                'visibility' => Visibility::CONNECTED,
                'menu'       => self::MENU_MEMBER,
                'content'    => self::shortcode('[subalcatel_mes_inscriptions]'),
            ],
            [
                'key'        => Pages::CLUB_DOCUMENTS,
                'template'   => 'page-espace-membre',
                'legacy'     => ['documents'],
                'title'      => 'Documents du club',
                'parent'     => Pages::MEMBER_AREA,
                'visibility' => Visibility::PUBLIC_ACCESS,
                // Deux menus, une seule page — voir [menusOf]. Sans l'entrée au
                // menu principal, la page restait injoignable pour un visiteur :
                // son lien vivait dans le sous-menu « Mon espace », que
                // [MenuVisibility] retire entièrement à qui n'est pas connecté.
                'menu'       => [self::MENU_MAIN, self::MENU_MEMBER],
                // Page publique à dessein : les statuts et le règlement intérieur
                // ont vocation à être lus par qui veut. C'est chaque document qui
                // porte sa propre restriction, pas la page qui les liste.
                'content'    => self::shortcode('[subalcatel_documents_club]'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function legalPages(): array
    {
        return [
            [
                'key'        => 'mentions-legales',
                'title'      => 'Mentions légales',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_LEGAL,
                'content'    => self::note(
                    'À compléter : éditeur du site (nom et adresse de l’association, numéro '
                    . 'RNA), directeur de publication, hébergeur (nom, adresse, téléphone).'
                ),
            ],
            [
                'key'        => 'confidentialite',
                'legacy'     => ['politique-de-confidentialite'],
                'title'      => 'Politique de confidentialité',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_LEGAL,
                'privacy'    => true,
                'content'    => self::note(
                    'Un texte de départ vous est proposé par le plugin : ouvrez cette page '
                    . 'dans l’éditeur, le panneau « Politique de confidentialité » de '
                    . 'WordPress affiche le contenu suggéré, à relire et à adapter.'
                ),
            ],
            [
                'key'        => 'cookies',
                'title'      => 'Gestion des cookies',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_LEGAL,
                'content'    => self::paragraph(
                    'Le site n’utilise que les cookies nécessaires à son fonctionnement : '
                    . 'session de connexion et préférences d’affichage. Aucun traceur '
                    . 'publicitaire, aucune mesure d’audience tierce.'
                )
                    . self::note('À revoir si une mesure d’audience est ajoutée ultérieurement.'),
            ],
            [
                'key'        => 'plan-du-site',
                'title'      => 'Plan du site',
                'visibility' => Visibility::PUBLIC_ACCESS,
                'menu'       => self::MENU_LEGAL,
                'content'    => self::shortcode('[subalcatel_plan_du_site]'),
            ],
        ];
    }

    // ---------------------------------------------------------- Blocs Gutenberg

    private static function heading(string $text, int $level = 2): string
    {
        return sprintf(
            '<!-- wp:heading {"level":%d} --><h%d class="wp-block-heading">%s</h%d><!-- /wp:heading -->',
            $level,
            $level,
            esc_html($text),
            $level
        );
    }

    private static function paragraph(string $text): string
    {
        return sprintf('<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->', esc_html($text));
    }

    /**
     * Repère visible pour le bureau, à supprimer une fois la page rédigée.
     */
    private static function note(string $text): string
    {
        return sprintf(
            '<!-- wp:paragraph {"className":"sub-todo"} --><p class="sub-todo"><em>%s</em></p>'
            . '<!-- /wp:paragraph -->',
            esc_html($text)
        );
    }

    /**
     * @param list<string> $items
     */
    private static function bullets(array $items): string
    {
        $html = '<!-- wp:list --><ul class="wp-block-list">';

        foreach ($items as $item) {
            $html .= sprintf('<!-- wp:list-item --><li>%s</li><!-- /wp:list-item -->', esc_html($item));
        }

        return $html . '</ul><!-- /wp:list -->';
    }

    /**
     * @param list<string> $items
     */
    private static function numbered(array $items): string
    {
        $html = '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">';

        foreach ($items as $item) {
            $html .= sprintf('<!-- wp:list-item --><li>%s</li><!-- /wp:list-item -->', esc_html($item));
        }

        return $html . '</ol><!-- /wp:list -->';
    }

    /**
     * Boutons vers d'autres pages du site.
     *
     * Les URL sont résolues à l'installation : au moment où `SiteBuilder`
     * enregistre le contenu, les pages cibles existent déjà.
     *
     * @param list<array{0: string, 1: string}> $buttons libellé, clé de page
     */
    private static function buttons(array $buttons): string
    {
        $html = '<!-- wp:buttons --><div class="wp-block-buttons">';

        foreach ($buttons as [$label, $key]) {
            $html .= sprintf(
                '<!-- wp:button --><div class="wp-block-button">'
                . '<a class="wp-block-button__link wp-element-button" href="%s">%s</a>'
                . '</div><!-- /wp:button -->',
                '%%URL:' . $key . '%%',
                esc_html($label)
            );
        }

        return $html . '</div><!-- /wp:buttons -->';
    }

    private static function shortcode(string $shortcode): string
    {
        return sprintf('<!-- wp:shortcode -->%s<!-- /wp:shortcode -->', $shortcode);
    }

    /**
     * Insère un pattern du thème.
     *
     * Les patterns livrés — grille de tarifs, trombinoscope, carte de formation —
     * ne servent à rien tant que personne ne les pose sur une page. En les
     * insérant ici, le bureau trouve une mise en page déjà faite et n'a plus
     * qu'à remplacer les valeurs d'exemple. Le pattern est développé à
     * l'enregistrement : il devient du contenu ordinaire, modifiable dans
     * l'éditeur sans savoir ce qu'est un pattern.
     */
    private static function pattern(string $slug): string
    {
        return sprintf('<!-- wp:pattern {"slug":"%s"} /-->', $slug);
    }
}
