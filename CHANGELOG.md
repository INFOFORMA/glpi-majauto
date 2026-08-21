# Journal des versions

## 1.0.0 — 21/08/2026

Première publication.

- Relevé de l'état d'activation de chaque greffon **avant** mise à jour, et **rétablissement après** : un greffon actif le redevient, un greffon volontairement éteint le reste.
- Vérification que la version a réellement changé et que le greffon est bien rechargé — `plugin:install` laisse le greffon désactivé sans le signaler.
- Sauvegarde préalable de la base, **vérifiée jusqu'à sa ligne finale** ; la mise à jour est annulée si l'archive n'est pas exploitable.
- Mot de passe de base transmis par fichier temporaire en 600, jamais par la ligne de commande. Archive produite en 0600.
- Mode « signaler seulement » par défaut, liste d'exclusions, rotation des archives.
- Alerte par courriel sur échec, avec bouton d'essai pour vérifier la chaîne d'alerte.
