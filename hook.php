<?php

/**
 * Installation / désinstallation du plugin « Mise à jour automatique ».
 *
 * Aucune table n'est créée : la configuration tient dans glpi_configs, sous le
 * contexte PLUGIN_MAJAUTO_CONTEXT. Une table de plus serait une table de plus à
 * migrer à chaque version de GLPI, pour six valeurs scalaires.
 */

function plugin_majauto_install()
{
    $defauts = [
        // 0 = signaler seulement, 1 = appliquer. On installe en mode SIGNALER :
        // un plugin qui se met à jour tout seul dès son installation, sans que
        // personne ne l'ait décidé, est un piège.
        'mode'            => '0',
        // Plugins jamais touchés automatiquement. Les sauts de version mineure
        // s'accompagnent souvent d'une migration de schéma : à faire à la main,
        // en connaissance de cause.
        'exclus'          => 'metademands,advancedforms',
        'destinataire'    => '',
        // Une mise à jour de plugin exécute des migrations de schéma. Sans
        // copie de la base, un échec est sans retour : activé par défaut, et
        // l'échec de la sauvegarde annule la mise à jour.
        'sauvegarde'           => '1',
        'sauvegarde_dossier'   => '',   // vide = <var>/_dumps
        'sauvegarde_retention' => '5',
        'derniere_execution' => '',
        'dernier_rapport' => '',
    ];

    $actuel = Config::getConfigurationValues(PLUGIN_MAJAUTO_CONTEXT);
    // Une réinstallation (cas d'une mise à jour du plugin) ne doit pas écraser
    // les réglages en place.
    foreach ($defauts as $cle => $valeur) {
        if (array_key_exists($cle, $actuel)) {
            unset($defauts[$cle]);
        }
    }
    if (count($defauts) > 0) {
        Config::setConfigurationValues(PLUGIN_MAJAUTO_CONTEXT, $defauts);
    }

    CronTask::register(
        \GlpiPlugin\Majauto\Update::class,
        'majauto',
        WEEK_TIMESTAMP,
        [
            'comment'   => "Relève les mises à jour de plugins disponibles, et les applique si le mode « appliquer » est actif.",
            'mode'      => CronTask::MODE_EXTERNAL,
            'allowmode' => CronTask::MODE_EXTERNAL,
            'hourmin'   => 2,
            'hourmax'   => 6,
            'state'     => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

function plugin_majauto_uninstall()
{
    CronTask::unregister('majauto');
    Config::deleteConfigurationValues(PLUGIN_MAJAUTO_CONTEXT, [
        'mode', 'exclus', 'destinataire', 'derniere_execution', 'dernier_rapport',
        'sauvegarde', 'sauvegarde_dossier', 'sauvegarde_retention',
    ]);

    return true;
}
