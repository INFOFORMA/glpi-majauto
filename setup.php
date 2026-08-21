<?php

/**
 * Mise à jour automatique des plugins — plugin GLPI
 *
 * GLPI sait signaler qu'une mise à jour existe (tâche automatique
 * « checkallupdates » du cœur), mais il ne l'applique jamais. La console offre
 * bien `marketplace:upgrade`, seulement elle traite tous les plugins d'un bloc,
 * sans simulation et sans filet.
 *
 * Ce plugin comble l'écart : il applique les mises à jour, une par une, en
 * restaurant l'état d'activation relevé avant l'opération.
 *
 * POURQUOI CE FILET EST INDISPENSABLE
 *   Le 18/08/2026, sur ce GLPI, l'enchaînement download + install a laissé CINQ
 *   plugins en état « installé / non activé », dont oauthimap qui alimente le
 *   collecteur de courriels. GLPI ne signale rien : le plugin cesse simplement
 *   d'être chargé. Sans relevé préalable, la panne serait passée inaperçue.
 *
 * @author  Infoforma
 * @license GPLv3+
 */

define('PLUGIN_MAJAUTO_VERSION', '1.0.0');
define('PLUGIN_MAJAUTO_MIN_GLPI', '11.0');
define('PLUGIN_MAJAUTO_MAX_GLPI', '12.0');

/** Contexte de configuration dans glpi_configs (pas de table dédiée). */
define('PLUGIN_MAJAUTO_CONTEXT', 'plugin:majauto');

function plugin_init_majauto()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['majauto'] = true;

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['majauto'] = 'front/config.form.php';
    }
}

function plugin_version_majauto()
{
    return [
        'name'         => "Mise à jour automatique des plugins",
        'version'      => PLUGIN_MAJAUTO_VERSION,
        'license'      => 'GPLv3+',
        'author'       => 'Infoforma',
        'homepage'     => 'https://www.infoforma.fr',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAJAUTO_MIN_GLPI,
                'max' => PLUGIN_MAJAUTO_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_majauto_check_prerequisites()
{
    // Sans accès en écriture au dossier des plugins, rien n'est applicable :
    // autant le dire à l'installation plutôt qu'à 4 h du matin dans un journal.
    if (!Glpi\Marketplace\Controller::hasWriteAccess()) {
        echo "Le dossier marketplace n'est pas accessible en écriture : "
           . "aucune mise à jour ne pourra être appliquée.";
        return false;
    }
    return true;
}

function plugin_majauto_check_config($verbose = false)
{
    return true;
}
