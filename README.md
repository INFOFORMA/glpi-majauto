# Mise à jour automatique des plugins — greffon GLPI

Applique les mises à jour de greffons **en rétablissant leur état d'activation**, avec sauvegarde préalable vérifiée.

GLPI signale qu'une mise à jour existe, mais ne l'applique jamais. La console offre bien `marketplace:upgrade`, seulement elle traite tous les greffons d'un bloc, sans simulation et sans filet.

## Le problème que ça résout

L'enchaînement `marketplace:download` puis `plugin:install` **laisse le greffon désactivé**. GLPI ne le signale pas : le greffon cesse simplement d'être chargé.

Sur l'instance qui a motivé ce développement, cinq greffons se sont ainsi retrouvés en état « installé / non activé » après une mise à jour — dont celui qui alimentait le collecteur de courriels. L'arrivée des tickets se serait interrompue sans que rien ne l'indique.

Ce greffon relève l'état d'activation **avant**, applique la mise à jour, **rétablit cet état après**, puis vérifie que la version a réellement changé et que le greffon est bien redevenu actif.

## Ce qu'il fait

- Relève l'état de chaque greffon en base, interroge la place de marché, compare.
- **Mode « signaler seulement »** par défaut : envoie la liste, ne modifie rien.
- **Mode « appliquer »** : télécharge, installe, réactive uniquement ce qui était actif, contrôle le résultat.
- **Sauvegarde préalable de la base**, et **annule la mise à jour si l'archive n'est pas exploitable**.
- Liste d'exclusions, pour les greffons dont la mise à jour implique une migration de schéma qu'on préfère lancer soi-même.
- Alerte par courriel en cas d'échec, avec un bouton d'essai pour vérifier la chaîne d'alerte.

## Ce sur quoi il ne transige pas

**Il ne modifie jamais l'état d'activation d'un greffon.** Un greffon volontairement éteint le reste ; un greffon actif le redevient. Réactiver ce qui avait été éteint serait aussi fautif que d'éteindre ce qui était actif.

**Une sauvegarde non vérifiée vaut zéro.** Un `mysqldump` interrompu produit un fichier gzip parfaitement valide, mais tronqué. L'archive est donc relue jusqu'à sa ligne finale ; si ce témoin manque, aucune mise à jour n'est appliquée.

**Le mot de passe de la base ne passe jamais par la ligne de commande** — les arguments d'un processus sont lisibles par tout compte de la machine. Il transite par un fichier temporaire en 600, supprimé aussitôt.

L'archive produite est en **0600** : une copie de base contient les empreintes de mots de passe et les jetons d'API.

## Installation

1. Déposer le dossier `majauto` dans `plugins/` de votre GLPI.
2. **Configuration > Greffons**, installer puis activer.
3. Régler via la roue crantée.

Une tâche automatique hebdomadaire (`majauto`) est enregistrée, planifiée entre 2 h et 6 h.

## Réglages

| Réglage | Défaut | |
|---|---|---|
| Mode | *signaler seulement* | rien n'est modifié sans décision explicite |
| Greffons exclus | — | ceux dont la mise à jour demande une migration de schéma |
| Adresse à prévenir | — | vide = aucun courriel ; le rapport reste consultable |
| Sauvegarde avant application | *oui* | et **annulation** si elle échoue |
| Dossier de sauvegarde | `<var>/_dumps` | hors racine web |
| Archives conservées | 5 | rotation automatique |

## Compatibilité

GLPI **11.0** à 12.0 · PHP 8.1+ · `mysqldump` accessible pour la sauvegarde.

## Licence

GPLv3 ou ultérieure — voir [LICENSE](LICENSE).

## Support

`support@infoforma.fr` — [Infoforma](https://www.infoforma.fr)
