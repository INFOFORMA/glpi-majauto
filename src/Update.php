<?php

namespace GlpiPlugin\Majauto;

use CommonGLPI;
use Config;
use CronTask;
use Glpi\Marketplace\Controller;
use NotificationMailing;
use Plugin;
use Toolbox;

/**
 * Cœur du plugin : relève, applique, vérifie.
 *
 * La règle qui gouverne tout ce fichier : on ne modifie jamais l'état
 * d'activation d'un plugin. On le relève avant, on le rétablit après, et si le
 * rétablissement échoue on le dit fort. Une mise à jour qui désactive
 * silencieusement le collecteur de courriels coûte plus cher que la mise à jour
 * ne rapporte.
 */
class Update extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return "Mise à jour automatique des plugins";
    }

    public static function cronInfo($name)
    {
        return [
            'description' => "Mise à jour des plugins (relevé, puis application si le mode le permet)",
        ];
    }

    /**
     * Point d'entrée de la tâche automatique GLPI.
     *
     * @return int 1 si quelque chose a été fait, 0 sinon, -1 en cas d'erreur.
     */
    public static function cronMajauto(?CronTask $task = null): int
    {
        $conf   = Config::getConfigurationValues(PLUGIN_MAJAUTO_CONTEXT);
        $mode   = (int) ($conf['mode'] ?? 0);
        $exclus = self::listeExclus((string) ($conf['exclus'] ?? ''));

        $journal = static function (string $ligne) use ($task): void {
            if ($task !== null) {
                $task->log($ligne);
            }
            Toolbox::logInFile('majauto', $ligne . "\n");
        };

        if (!Controller::isCLIAllowed() && !Controller::isWebAllowed()) {
            $journal("ARRÊT : la place de marché est désactivée (GLPI_MARKETPLACE_ENABLE).");
            return -1;
        }

        // ── 1. Relevé de l'état AVANT ────────────────────────────────────────
        $avant = self::etatDesPlugins();
        if ($avant === []) {
            $journal("ARRÊT : aucun plugin lisible en base — on ne touche à rien.");
            return -1;
        }

        // ── 2. Mises à jour disponibles ──────────────────────────────────────
        try {
            $dispo = Controller::getAllUpdates();   // [clé => version en ligne]
        } catch (\Throwable $e) {
            $journal("ARRÊT : la place de marché est injoignable (" . $e->getMessage() . ").");
            return -1;
        }

        $applicables = [];
        $reportes    = [];
        foreach ($dispo as $cle => $version) {
            if (!isset($avant[$cle])) {
                // Présent en ligne mais absent de notre relevé : on s'abstient.
                continue;
            }
            if (in_array($cle, $exclus, true)) {
                $reportes[$cle] = $version;
                continue;
            }
            $applicables[$cle] = $version;
        }

        if ($applicables === [] && $reportes === []) {
            self::enregistrerRapport("Aucune mise à jour disponible.");
            $journal("Aucune mise à jour disponible.");
            return 0;
        }

        // ── 3. Mode « signaler seulement » ───────────────────────────────────
        if ($mode !== 1) {
            $rapport = self::redigerRapport($avant, $applicables, $reportes, [], [], true);
            self::enregistrerRapport($rapport);
            $journal(count($applicables) . " mise(s) à jour disponible(s), mode « signaler » : rien n'a été appliqué.");
            self::avertir(
                "[GLPI] " . count($applicables) . " mise(s) à jour de plugin disponible(s)",
                $rapport,
                $conf
            );
            $task?->addVolume(count($applicables));
            return 1;
        }

        // ── 4. Application ───────────────────────────────────────────────────
        if ($applicables === []) {
            $rapport = self::redigerRapport($avant, [], $reportes, [], [], false);
            self::enregistrerRapport($rapport);
            $journal("Seules des mises à jour exclues sont disponibles : rien à appliquer automatiquement.");
            self::avertir("[GLPI] mise(s) à jour de plugin à traiter à la main", $rapport, $conf);
            return 1;
        }

        if (!Controller::hasWriteAccess()) {
            $journal("ARRÊT : le dossier marketplace n'est pas accessible en écriture.");
            self::avertir(
                "[GLPI] mise à jour de plugins impossible",
                "Le dossier marketplace n'est pas accessible en écriture. Aucune mise à jour appliquée.",
                $conf
            );
            return -1;
        }

        // 🔴 SAUVEGARDE PRÉALABLE. Une mise à jour de plugin exécute des
        // migrations de schéma : sans copie de la base, un échec est sans
        // retour. Si la sauvegarde ne peut pas être produite ET vérifiée, on
        // n'applique RIEN — c'est le seul comportement défendable.
        $archive = null;
        if ((int) ($conf['sauvegarde'] ?? 1) === 1) {
            $archive = self::sauvegarderBase($conf, $journal);
            if ($archive === null) {
                $journal("ARRÊT : sauvegarde impossible ou illisible — aucune mise à jour appliquée.");
                self::avertir(
                    "[GLPI] ALERTE — sauvegarde impossible, mise à jour annulée",
                    "La sauvegarde préalable de la base n'a pas pu être produite ou s'est révélée\n"
                    . "illisible. Aucune mise à jour de plugin n'a été appliquée.\n\n"
                    . "Vérifier l'espace disque et le dossier de sauvegarde configuré.",
                    $conf
                );
                return -1;
            }
            $journal("sauvegarde vérifiée : $archive");
        } else {
            $journal("⚠️ sauvegarde préalable DÉSACTIVÉE dans les réglages.");
        }

        $reussites = [];
        $echecs    = [];

        foreach ($applicables as $cle => $version_cible) {
            $motif = self::mettreAJourUnPlugin(
                $cle,
                (string) $version_cible,
                $avant[$cle]['state'],
                $avant[$cle]['version'],
                $journal
            );
            if ($motif === null) {
                $reussites[$cle] = $avant[$cle]['version'] . ' → ' . $version_cible;
            } else {
                $echecs[$cle] = $motif;
                $journal("$cle : ÉCHEC — $motif");
            }
        }

        $rapport = self::redigerRapport($avant, $applicables, $reportes, $reussites, $echecs, false);
        self::enregistrerRapport($rapport);

        if ($echecs !== []) {
            self::avertir("[GLPI] ALERTE — échec de mise à jour de plugin", $rapport, $conf);
        } elseif ($reussites !== []) {
            self::avertir("[GLPI] " . count($reussites) . " plugin(s) mis à jour", $rapport, $conf);
        }

        $task?->addVolume(count($reussites));

        return $echecs === [] ? 1 : -1;
    }

    /**
     * Met à jour un plugin, et vérifie que le résultat est bien celui attendu.
     *
     * @return string|null null si tout s'est bien passé, sinon le motif d'échec.
     */
    private static function mettreAJourUnPlugin(
        string $cle,
        string $version_cible,
        int $etat_avant,
        string $version_avant,
        callable $journal
    ): ?string {
        if (Controller::hasVcsDirectory($cle)) {
            // Un dossier sous git est un plugin en cours de développement :
            // l'écraser ferait perdre du travail.
            return "dossier sous gestion de version — non touché";
        }

        $controleur = new Controller($cle);
        if (!$controleur->canBeDownloaded($version_cible)) {
            return "version $version_cible non téléchargeable (offre requise ou incompatibilité)";
        }

        // false : on installe nous-mêmes juste après, pour garder la main sur
        // l'enchaînement et surtout sur la réactivation.
        if (!$controleur->downloadPlugin(false, $version_cible)) {
            return "téléchargement échoué";
        }

        $plugin = new Plugin();

        // 🔴 ÉTAPE INDISPENSABLE, ET FACILE À OUBLIER.
        // Le téléchargement dépose les fichiers, mais GLPI ne « voit » pas la
        // nouvelle version tant que la base n'a pas été synchronisée avec le
        // disque. Sans cela, install() migre une version que la base croit
        // inchangée : rien ne bouge, et le contrôle conclut à tort que la mise
        // à jour n'a pas pris. Constaté le 21/08/2026 sur metademands 3.6.2 →
        // 3.6.3 : fichiers en 3.6.3 sur le disque, base restée en 3.6.2.
        //
        // Pire : la synchronisation place le greffon en état « non à jour », et
        // dans cet état GLPI CESSE DE LE CHARGER. Un abandon à ce stade laisse
        // donc le greffon hors service, sans message.
        $plugin->checkStates(true);

        if (!$plugin->getFromDBbyDir($cle)) {
            return "plugin introuvable en base après téléchargement";
        }

        try {
            $plugin->install($plugin->fields['id']);
        } catch (\Throwable $e) {
            return "installation échouée : " . $e->getMessage();
        }

        // 🔴 L'ÉTAPE QUI COMPTE. install() laisse le plugin désactivé. On ne le
        // réactive QUE s'il était actif : réactiver un plugin volontairement
        // éteint serait tout aussi fautif que d'en éteindre un actif.
        if ($etat_avant === Plugin::ACTIVATED) {
            $plugin->getFromDBbyDir($cle);
            $plugin->activate($plugin->fields['id']);
        }

        // ── Vérification : ces appels ne rendent pas d'erreur exploitable ─────
        $plugin->getFromDBbyDir($cle);
        $version_apres = (string) ($plugin->fields['version'] ?? '');
        $etat_apres    = (int) ($plugin->fields['state'] ?? -1);

        if ($version_apres === $version_avant) {
            return "version inchangée ($version_avant) — la mise à jour n'a pas pris";
        }
        if ($etat_avant === Plugin::ACTIVATED && $etat_apres !== Plugin::ACTIVATED) {
            return "passé en $version_apres mais NON RÉACTIVÉ (état $etat_apres) — le plugin n'est plus chargé";
        }

        $journal("$cle : $version_avant → $version_apres");
        return null;
    }

    // ── Sauvegarde ───────────────────────────────────────────────────────────

    /**
     * Produit une copie compressée de la base, et ne la rend que si elle est
     * réellement exploitable.
     *
     * @return string|null chemin de l'archive, ou null si rien de fiable.
     */
    public static function sauvegarderBase(array $conf, callable $journal): ?string
    {
        global $DB;

        $dossier = trim((string) ($conf['sauvegarde_dossier'] ?? ''));
        if ($dossier === '') {
            $dossier = GLPI_VAR_DIR . '/_dumps';
        }
        if (!is_dir($dossier) && !@mkdir($dossier, 0770, true)) {
            $journal("sauvegarde : dossier $dossier impossible à créer");
            return null;
        }
        @chmod($dossier, 0770);

        // Le mot de passe passe par un fichier en 600, JAMAIS par la ligne de
        // commande : les arguments d'un processus sont lisibles par tout compte
        // de la machine. C'est exactement l'exposition corrigée sur le VPS.
        $identifiants = tempnam(sys_get_temp_dir(), 'majauto_');
        if ($identifiants === false) {
            $journal("sauvegarde : fichier d'identifiants impossible");
            return null;
        }
        @chmod($identifiants, 0600);

        $hote = (string) $DB->dbhost;
        $port = '';
        if (str_contains($hote, ':')) {
            [$hote, $port] = explode(':', $hote, 2);
        }
        $guillemets = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

        $ini = "[client]\n"
             . 'user=' . $guillemets((string) $DB->dbuser) . "\n"
             . 'password=' . $guillemets(rawurldecode((string) $DB->dbpassword)) . "\n"
             . 'host=' . $guillemets($hote) . "\n";
        if ($port !== '' && ctype_digit($port)) {
            $ini .= "port=$port\n";
        }
        file_put_contents($identifiants, $ini);

        $fichier = $dossier . '/glpi_avant_maj_' . date('Ymd_His') . '.sql.gz';
        $commande = sprintf(
            'mysqldump --defaults-file=%s --single-transaction --quick --no-tablespaces %s 2>/dev/null | gzip > %s',
            escapeshellarg($identifiants),
            escapeshellarg((string) $DB->dbdefault),
            escapeshellarg($fichier)
        );

        $sortie = [];
        $code   = 1;
        @exec($commande, $sortie, $code);
        @unlink($identifiants);

        // Un dump contient les empreintes de mots de passe, les jetons d'API et
        // les secrets de configuration : il ne doit être lisible que par le
        // compte qui fait tourner GLPI, jamais par les autres comptes de la
        // machine. Le masque par défaut donnerait 0644.
        @chmod($fichier, 0600);

        if (!self::archiveExploitable($fichier)) {
            $journal("sauvegarde : archive absente, tronquée ou illisible (code $code)");
            @unlink($fichier);
            return null;
        }

        self::rotationSauvegardes($dossier, (int) ($conf['sauvegarde_retention'] ?? 5), $journal);

        return $fichier;
    }

    /**
     * Une archive « présente » ne prouve rien : un mysqldump interrompu produit
     * un fichier gzip parfaitement valide, mais tronqué. Le seul témoin fiable
     * est la ligne finale que mysqldump écrit lui-même.
     */
    public static function archiveExploitable(string $fichier): bool
    {
        if (!is_file($fichier) || filesize($fichier) < 50000) {
            return false;
        }

        $fh = @gzopen($fichier, 'rb');
        if ($fh === false) {
            return false;
        }

        $octets = 0;
        $queue  = '';
        while (!gzeof($fh)) {
            $bloc = gzread($fh, 262144);
            if ($bloc === false) {
                gzclose($fh);
                return false;
            }
            $octets += strlen($bloc);
            // On ne garde que la fin : inutile de charger 12 Mo en mémoire.
            $queue = substr($queue . $bloc, -400);
        }
        gzclose($fh);

        if ($octets < 1000000) {
            return false;
        }
        return str_contains($queue, 'Dump completed');
    }

    private static function rotationSauvegardes(string $dossier, int $garder, callable $journal): void
    {
        if ($garder < 1) {
            return;
        }

        $archives = glob($dossier . '/glpi_avant_maj_*.sql.gz') ?: [];
        if (count($archives) <= $garder) {
            return;
        }

        // Les noms portent la date : le tri alphabétique est chronologique.
        sort($archives);
        $aSupprimer = array_slice($archives, 0, count($archives) - $garder);
        foreach ($aSupprimer as $vieille) {
            @unlink($vieille);
        }
        $journal("rotation : " . count($aSupprimer) . " archive(s) ancienne(s) supprimée(s), $garder conservée(s)");
    }

    // ── Essai d'alerte ───────────────────────────────────────────────────────

    /**
     * Exerce le chemin d'alerte de bout en bout. Un mécanisme d'alerte jamais
     * déclenché est un mécanisme dont on ne sait rien.
     */
    public static function essaiAlerte(string $destinataire): bool
    {
        global $DB;

        $avant = count($DB->request([
            'FROM'  => 'glpi_queuednotifications',
            'WHERE' => ['recipient' => $destinataire],
        ]));

        self::avertir(
            "[GLPI] essai d'alerte — mise à jour automatique des plugins",
            "Ceci est un essai déclenché depuis la page de réglages du plugin.\n\n"
            . "Si ce message vous parvient, la chaîne d'alerte fonctionne : le plugin\n"
            . "saura vous prévenir en cas d'échec de mise à jour.\n\n"
            . "Déclenché le " . date('d/m/Y à H:i') . ".",
            ['destinataire' => $destinataire]
        );

        $apres = count($DB->request([
            'FROM'  => 'glpi_queuednotifications',
            'WHERE' => ['recipient' => $destinataire],
        ]));

        return $apres > $avant;
    }

    /** État courant de tous les plugins connus de GLPI, lu en base. */
    public static function etatDesPlugins(): array
    {
        global $DB;

        $etat = [];
        foreach ($DB->request(['FROM' => 'glpi_plugins']) as $ligne) {
            $etat[$ligne['directory']] = [
                'version' => (string) $ligne['version'],
                'state'   => (int) $ligne['state'],
                'name'    => (string) $ligne['name'],
            ];
        }
        return $etat;
    }

    // ── Planification ────────────────────────────────────────────────────────

    /** Fréquences proposées, en secondes. */
    public static function frequences(): array
    {
        return [
            HOUR_TIMESTAMP      => "Toutes les heures",
             6 * HOUR_TIMESTAMP => "Toutes les 6 heures",
            DAY_TIMESTAMP       => "Tous les jours",
            WEEK_TIMESTAMP      => "Toutes les semaines",
            MONTH_TIMESTAMP     => "Tous les mois",
        ];
    }

    /**
     * Applique la planification à la tâche automatique.
     *
     * La fenêtre horaire compte autant que la fréquence : régler « toutes les
     * heures » en laissant une fenêtre 2 h – 6 h ne donne que quatre passages
     * par nuit. On écrit donc les deux ensemble, jamais l'une sans l'autre.
     *
     * @return string ce qui a réellement été appliqué, relu en base.
     */
    public static function planifier(int $frequence, int $heure_debut, int $heure_fin): string
    {
        global $DB;

        $connues = array_keys(self::frequences());
        if (!in_array($frequence, $connues, true)) {
            $frequence = WEEK_TIMESTAMP;
        }
        $heure_debut = max(0, min(24, $heure_debut));
        $heure_fin   = max(0, min(24, $heure_fin));
        if ($heure_fin <= $heure_debut) {
            // Une fenêtre vide empêcherait toute exécution, en silence.
            $heure_debut = 0;
            $heure_fin   = 24;
        }

        $DB->update('glpi_crontasks', [
            'frequency' => $frequence,
            'hourmin'   => $heure_debut,
            'hourmax'   => $heure_fin,
        ], ['name' => 'majauto']);

        foreach ($DB->request([
            'SELECT' => ['frequency', 'hourmin', 'hourmax'],
            'FROM'   => 'glpi_crontasks',
            'WHERE'  => ['name' => 'majauto'],
        ]) as $t) {
            return sprintf(
                '%s, entre %sh et %sh',
                self::frequences()[(int) $t['frequency']] ?? $t['frequency'] . ' s',
                $t['hourmin'],
                $t['hourmax']
            );
        }
        return 'planification introuvable';
    }

    /** Planification courante, lue dans la tâche elle-même. */
    public static function planification(): array
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['frequency', 'hourmin', 'hourmax', 'lastrun'],
            'FROM'   => 'glpi_crontasks',
            'WHERE'  => ['name' => 'majauto'],
        ]) as $t) {
            return [
                'frequence' => (int) $t['frequency'],
                'debut'     => (int) $t['hourmin'],
                'fin'       => (int) $t['hourmax'],
                'derniere'  => $t['lastrun'],
            ];
        }
        return ['frequence' => WEEK_TIMESTAMP, 'debut' => 2, 'fin' => 6, 'derniere' => null];
    }

    public static function listeExclus(string $brut): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $brut))));
    }

    private static function redigerRapport(
        array $avant,
        array $applicables,
        array $reportes,
        array $reussites,
        array $echecs,
        bool $simulation
    ): string {
        $l = [];
        $l[] = "Relevé du " . date('d/m/Y à H:i');
        $l[] = "";

        if ($simulation) {
            $l[] = "Mode « signaler seulement » : aucune modification n'a été faite.";
            $l[] = "";
        }

        if ($applicables !== []) {
            $l[] = "Mises à jour applicables automatiquement :";
            foreach ($applicables as $cle => $v) {
                $l[] = sprintf("   %-22s %s → %s", $cle, $avant[$cle]['version'], $v);
            }
            $l[] = "";
        }

        if ($reportes !== []) {
            $l[] = "Exclues de l'automatisation (à traiter à la main) :";
            foreach ($reportes as $cle => $v) {
                $l[] = sprintf("   %-22s %s → %s", $cle, $avant[$cle]['version'], $v);
            }
            $l[] = "";
        }

        if ($reussites !== []) {
            $l[] = "Appliquées :";
            foreach ($reussites as $cle => $t) {
                $l[] = sprintf("   %-22s %s", $cle, $t);
            }
            $l[] = "";
        }

        if ($echecs !== []) {
            $l[] = "ÉCHECS :";
            foreach ($echecs as $cle => $motif) {
                $l[] = sprintf("   %-22s %s", $cle, $motif);
            }
            $l[] = "";
            $l[] = "⚠️ Vérifier que ces plugins sont bien ACTIFS. Un plugin mis à jour";
            $l[] = "mais non réactivé n'est plus chargé par GLPI, sans message d'erreur.";
            $l[] = "";
        }

        return implode("\n", $l);
    }

    private static function enregistrerRapport(string $rapport): void
    {
        Config::setConfigurationValues(PLUGIN_MAJAUTO_CONTEXT, [
            'derniere_execution' => date('Y-m-d H:i:s'),
            'dernier_rapport'    => $rapport,
        ]);
    }

    /**
     * Avertissement par courriel.
     *
     * ⚠️ On passe par NotificationMailing::sendNotification() et NON par une
     * insertion directe dans glpi_queuednotifications. Une ligne fabriquée à la
     * main part avec la mauvaise adresse d'expédition (admin_email au lieu de
     * from_email) et l'envoi échoue en silence : le message reste dans la file,
     * sent_try s'incrémente, personne n'est prévenu. Constaté le 18/08/2026.
     */
    private static function avertir(string $sujet, string $corps, array $conf): void
    {
        global $CFG_GLPI;

        $destinataire = trim((string) ($conf['destinataire'] ?? ''));
        if ($destinataire === '') {
            return;
        }

        // C'est from_email qui fait foi pour l'expédition, pas admin_email.
        $expediteur = (string) ($CFG_GLPI['from_email'] ?? '');
        if ($expediteur === '') {
            $expediteur = (string) ($CFG_GLPI['admin_email'] ?? '');
        }
        if ($expediteur === '') {
            Toolbox::logInFile('majauto', "Alerte impossible : aucune adresse d'expédition configurée\n");
            return;
        }

        $nom_expediteur = (string) ($CFG_GLPI['admin_email_name'] ?? 'GLPI');
        $repondre_a     = (string) ($CFG_GLPI['admin_email'] ?? $expediteur);

        $mailing = new NotificationMailing();
        $ok = $mailing->sendNotification([
            '_itemtype'                 => 'Config',
            '_items_id'                 => 1,
            '_notificationtemplates_id' => 0,
            '_entities_id'              => 0,
            'from'                      => $expediteur,
            'fromname'                  => $nom_expediteur,
            'replyto'                   => $repondre_a,
            'replytoname'               => $nom_expediteur,
            'to'                        => $destinataire,
            'toname'                    => '',
            'subject'                   => $sujet,
            'content_text'              => $corps,
            'content_html'              => nl2br(htmlspecialchars($corps)),
            'event'                     => 'majauto',
            'messageid'                 => 'GLPI-majauto-' . date('YmdHis') . '-'
                                         . bin2hex(random_bytes(4)) . '@' . (gethostname() ?: 'glpi'),
        ]);

        if (!$ok) {
            Toolbox::logInFile('majauto', "Avertissement NON déposé pour $destinataire : $sujet\n");
        }
    }
}
