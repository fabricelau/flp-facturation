# Facturation — Fabrice Lau Production

Outil de devis et factures, en ligne sur https://www.fabricelauproduction.com/facturation

- `facturation/` : l'application. C'est **ce dossier** que la mise à jour automatique copie sur le serveur
  (au plus tard 15 minutes après chaque nouvelle version, quand l'outil est ouvert ; ou tout de suite depuis
  Réglages → Mises à jour → « Vérifier et installer maintenant »).
- Les données (devis, factures, clients, réglages, mots de passe) ne sont **jamais** dans ce dépôt :
  elles restent sur le serveur, dans `facturation/data/`, que la mise à jour ne touche pas.
- Avant chaque mise à jour, le code en place est sauvegardé dans `data/sauvegardes/code-*.zip`
  et remis automatiquement si la copie échoue.
