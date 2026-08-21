<?php

/**
 * Page de configuration du plugin « Mise à jour automatique des plugins ».
 *
 * En GLPI 11, les pages front des plugins sont amorcées par le routeur du
 * cœur : pas d'include de includes.php ici.
 */

use GlpiPlugin\Majauto\Update;

// 🔴 La page est require()-ée DANS le contrôleur Symfony (LegacyFileLoadController) :
//   - $CFG_GLPI n'est PAS dans la portée locale, il faut le déclarer ;
//   - $_SERVER['PHP_SELF'] vaut « /index.php », PAS l'adresse de cette page.
// Un formulaire posté vers PHP_SELF part donc sur l'accueil de GLPI : le code
// d'enregistrement n'est jamais atteint, et rien ne signale l'échec.
// Le cœur de GLPI 11 n'utilise plus PHP_SELF dans front/ — zéro occurrence.
global $CFG_GLPI;
$page = $CFG_GLPI['root_doc'] . '/plugins/majauto/front/config.form.php';

// Contrôle de connexion en tête, contrôle du droit à l'écriture seulement :
// c'est le schéma des plugins qui fonctionnent sur cette instance (escalade,
// fields). Un checkRight('config', UPDATE) en tête de page y est refusé.
Session::checkLoginUser();

if (!Plugin::isPluginActive('majauto')) {
    Html::displayErrorAndDie("Le plugin n'est pas activé.");
}

$conf = Config::getConfigurationValues(PLUGIN_MAJAUTO_CONTEXT);

if (isset($_POST['enregistrer'])) {
    Session::checkRight('config', UPDATE);
    Config::setConfigurationValues(PLUGIN_MAJAUTO_CONTEXT, [
        'mode'                 => isset($_POST['mode']) && (int) $_POST['mode'] === 1 ? '1' : '0',
        'exclus'               => implode(',', Update::listeExclus((string) ($_POST['exclus'] ?? ''))),
        'destinataire'         => trim((string) ($_POST['destinataire'] ?? '')),
        'sauvegarde'           => isset($_POST['sauvegarde']) && (int) $_POST['sauvegarde'] === 1 ? '1' : '0',
        'sauvegarde_dossier'   => trim((string) ($_POST['sauvegarde_dossier'] ?? '')),
        'sauvegarde_retention' => (string) max(1, (int) ($_POST['sauvegarde_retention'] ?? 5)),
    ]);
    // La planification ne vit pas dans la configuration du greffon mais dans la
    // tâche automatique elle-même : on l'y écrit, et on relit ce qui a été pris.
    $applique = Update::planifier(
        (int) ($_POST['frequence'] ?? WEEK_TIMESTAMP),
        (int) ($_POST['heure_debut'] ?? 2),
        (int) ($_POST['heure_fin'] ?? 6)
    );
    Session::addMessageAfterRedirect("Planification : $applique.", false, INFO);
    Session::addMessageAfterRedirect("Configuration enregistrée.", false, INFO);
    Html::redirect($page);
}

if (isset($_POST['essai_alerte'])) {
    Session::checkRight('config', UPDATE);
    $dest = trim((string) (Config::getConfigurationValues(PLUGIN_MAJAUTO_CONTEXT)['destinataire'] ?? ''));
    if ($dest === '') {
        Session::addMessageAfterRedirect(
            "Aucune adresse n'est renseignée : rien à essayer.",
            false,
            ERROR
        );
    } elseif (Update::essaiAlerte($dest)) {
        Session::addMessageAfterRedirect(
            "Message d'essai déposé dans la file de notifications à destination de $dest. "
            . "Il partira au prochain passage de la tâche « queuednotification ».",
            false,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(
            "Le message n'a PAS pu être déposé dans la file. La chaîne d'alerte est hors service.",
            false,
            ERROR
        );
    }
    Html::redirect($page);
}

Html::header(
    "Mise à jour automatique des plugins",
    $page,
    'config',
    'plugins'
);

$mode         = (int) ($conf['mode'] ?? 0);
$exclus       = (string) ($conf['exclus'] ?? '');
$destinataire = (string) ($conf['destinataire'] ?? '');
$derniere     = (string) ($conf['derniere_execution'] ?? '');
$rapport      = (string) ($conf['dernier_rapport'] ?? '');
$sauvegarde   = (int) ($conf['sauvegarde'] ?? 1);
$dossier      = (string) ($conf['sauvegarde_dossier'] ?? '');
$retention    = (int) ($conf['sauvegarde_retention'] ?? 5);

echo "<div class='card'><div class='card-body'>";
echo "<form method='post' action='" . htmlspecialchars($page) . "'>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Mode de fonctionnement</label>";
echo "<div class='form-selectgroup'>";
foreach ([0 => "Signaler seulement", 1 => "Appliquer les mises à jour"] as $v => $libelle) {
    echo "<label class='form-selectgroup-item'>";
    echo "<input type='radio' name='mode' value='$v' class='form-selectgroup-input'"
       . ($mode === $v ? " checked" : "") . ">";
    echo "<span class='form-selectgroup-label'>" . htmlspecialchars($libelle) . "</span>";
    echo "</label>";
}
echo "</div>";
echo "<div class='form-hint'>En mode « signaler », la tâche se contente d'envoyer la liste. "
   . "En mode « appliquer », elle télécharge, installe, puis <strong>rétablit l'état d'activation "
   . "relevé avant l'opération</strong>, et vérifie que la version a bien changé.</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Plugins exclus de l'automatisation</label>";
echo "<input type='text' name='exclus' class='form-control' value='" . htmlspecialchars($exclus) . "'>";
echo "<div class='form-hint'>Séparés par des virgules. À utiliser pour les plugins dont la mise à jour "
   . "implique une migration de schéma, qu'il vaut mieux lancer soi-même en regardant le résultat.</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Adresse à prévenir</label>";
echo "<input type='email' name='destinataire' class='form-control' value='" . htmlspecialchars($destinataire) . "'>";
echo "<div class='form-hint'>Laisser vide pour n'envoyer aucun courriel. Le rapport reste consultable ci-dessous.</div>";
echo "</div>";

echo "<hr>";
echo "<h4>Quand la vérification a lieu</h4>";

$plan = Update::planification();

echo "<div class='mb-3'>";
echo "<label class='form-label'>Fréquence</label>";
echo "<select name='frequence' class='form-select' style='max-width:22rem'>";
foreach (Update::frequences() as $secondes => $libelle) {
    echo "<option value='" . (int) $secondes . "'"
       . ($plan['frequence'] === (int) $secondes ? " selected" : "") . ">"
       . htmlspecialchars($libelle) . "</option>";
}
echo "</select>";
echo "<div class='form-hint'>Une mise à jour repérée n'est appliquée qu'au passage suivant. "
   . "En hebdomadaire, un correctif publié le mardi attend jusqu'au lundi.</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Plage horaire autorisée</label>";
echo "<div class='d-flex align-items-center' style='gap:.5rem;max-width:22rem'>";
echo "<span>de</span>";
echo "<input type='number' name='heure_debut' class='form-control' min='0' max='24' style='max-width:6rem'"
   . " value='" . (int) $plan['debut'] . "'>";
echo "<span>h à</span>";
echo "<input type='number' name='heure_fin' class='form-control' min='0' max='24' style='max-width:6rem'"
   . " value='" . (int) $plan['fin'] . "'>";
echo "<span>h</span>";
echo "</div>";
echo "<div class='form-hint'>⚠️ La plage borne la fréquence : « toutes les heures » entre 2 h et 6 h "
   . "ne donne que quatre passages par nuit. Pour un contrôle réellement horaire, mettre <strong>0 à 24</strong>. "
   . "Une plage vide ou inversée est ramenée à 0–24 plutôt que d'empêcher toute exécution en silence.</div>";
echo "</div>";

echo "<hr>";
echo "<h4>Sauvegarde préalable</h4>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Sauvegarder la base avant d'appliquer</label>";
echo "<div class='form-selectgroup'>";
foreach ([1 => "Oui — et annuler si elle échoue", 0 => "Non"] as $v => $libelle) {
    echo "<label class='form-selectgroup-item'>";
    echo "<input type='radio' name='sauvegarde' value='$v' class='form-selectgroup-input'"
       . ($sauvegarde === $v ? " checked" : "") . ">";
    echo "<span class='form-selectgroup-label'>" . htmlspecialchars($libelle) . "</span>";
    echo "</label>";
}
echo "</div>";
echo "<div class='form-hint'>Une mise à jour de plugin exécute des migrations de schéma : sans copie "
   . "de la base, un échec est sans retour. L'archive est <strong>vérifiée</strong> (mysqldump interrompu "
   . "produit un fichier gzip valide mais tronqué) ; si elle ne l'est pas, aucune mise à jour n'est appliquée.</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Dossier de sauvegarde</label>";
echo "<input type='text' name='sauvegarde_dossier' class='form-control' value='"
   . htmlspecialchars($dossier) . "' placeholder='" . htmlspecialchars(GLPI_VAR_DIR . '/_dumps') . "'>";
echo "<div class='form-hint'>Vide = <code>" . htmlspecialchars(GLPI_VAR_DIR . '/_dumps') . "</code>. "
   . "Ce dossier n'est pas exposé par le serveur web.</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>Archives conservées</label>";
echo "<input type='number' name='sauvegarde_retention' class='form-control' min='1' style='max-width:8rem'"
   . " value='" . (int) $retention . "'>";
echo "</div>";

echo "<button type='submit' name='enregistrer' class='btn btn-primary'>Enregistrer</button>";
echo " <button type='submit' name='essai_alerte' class='btn btn-outline-secondary'>Tester l'alerte</button>";
echo Html::closeForm(false);
echo "</div></div>";

// ── État des plugins, tel que le plugin le voit ──────────────────────────────
echo "<div class='card mt-3'><div class='card-body'>";
echo "<h3 class='card-title'>Dernier relevé</h3>";
if ($derniere === '') {
    echo "<p class='text-muted'>La tâche automatique <code>majauto</code> n'a pas encore tourné. "
       . "Elle est planifiée une fois par semaine, entre 2 h et 6 h.</p>";
} else {
    echo "<p class='text-muted'>Exécutée le " . htmlspecialchars(date('d/m/Y à H:i', strtotime($derniere))) . "</p>";
    echo "<pre class='bg-light p-3'>" . htmlspecialchars($rapport) . "</pre>";
}
echo "</div></div>";

echo "<div class='card mt-3'><div class='card-body'>";
echo "<h3 class='card-title'>Plugins installés</h3>";
echo "<table class='table table-sm'>";
echo "<thead><tr><th>Dossier</th><th>Version</th><th>État</th><th>Automatisé</th></tr></thead><tbody>";
$liste_exclus = Update::listeExclus($exclus);
foreach (Update::etatDesPlugins() as $cle => $p) {
    $actif = (int) $p['state'] === Plugin::ACTIVATED;
    echo "<tr>";
    echo "<td>" . htmlspecialchars($cle) . "</td>";
    echo "<td>" . htmlspecialchars($p['version']) . "</td>";
    echo "<td>" . ($actif
        ? "<span class='badge bg-success'>actif</span>"
        : "<span class='badge bg-secondary'>état " . (int) $p['state'] . "</span>") . "</td>";
    echo "<td>" . (in_array($cle, $liste_exclus, true)
        ? "<span class='badge bg-warning'>exclu</span>"
        : "oui") . "</td>";
    echo "</tr>";
}
echo "</tbody></table>";
echo "</div></div>";

Html::footer();
