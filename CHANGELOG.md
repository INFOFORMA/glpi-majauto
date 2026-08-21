
## 1.0.1 — 21/08/2026

**Correctif : une mise à jour téléchargée n'était jamais appliquée.**

Le téléchargement dépose les fichiers, mais GLPI ne « voit » la nouvelle version
qu'après synchronisation de la base avec le disque. Sans ce `checkStates()`,
l'installation migrait une version que la base croyait inchangée : rien ne
bougeait, et le contrôle concluait — à juste titre — que la mise à jour n'avait
pas pris.

Plus grave : la synchronisation place le greffon en état « non à jour », et dans
cet état **GLPI cesse de le charger**. Un abandon à ce stade laissait donc le
greffon hors service, sans message.

**Nouveau : la fréquence de vérification est réglable** depuis la page de
configuration (horaire, 6 heures, quotidienne, hebdomadaire, mensuelle), avec la
plage horaire autorisée. Une plage vide ou inversée est ramenée à 0–24 plutôt
que d'empêcher toute exécution en silence.
