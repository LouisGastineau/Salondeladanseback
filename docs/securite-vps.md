# Accès VPS et séparation MariaDB

L'administration SSH utilise `salon-admin` et une clé distincte de celle de GitHub Actions. `sudo` est disponible sur ce compte ; SSH root et l'authentification SSH par mot de passe sont désactivés après vérification de l'accès par clé. La clé privée doit être conservée hors du dépôt. Le compte `deploy` conserve ses seules commandes sudo de sauvegarde et de rechargement PHP-FPM.

MariaDB écoute sur `127.0.0.1:3306` uniquement.

| Compte MariaDB | Utilisation | Droits |
|---|---|---|
| salon_app | Laravel / PHP-FPM | SELECT, INSERT, UPDATE, DELETE sur salon_danse uniquement |
| salon_migrator | Déploiement | DML et CREATE, ALTER, INDEX, DROP, REFERENCES sur salon_danse |
| salon_readonly | Consultation | SELECT sur salon_danse |
| salon_dba | Administration | Administration de salon_danse, sans privilèges globaux ni GRANT OPTION |
| salon_backup | Sauvegarde | SELECT, SHOW VIEW, TRIGGER, EVENT sur salon_danse et SELECT sur mysql.proc pour les routines |

Les identifiants applicatifs restent dans `/var/www/back/shared/.env`. Les identifiants des migrations sont dans `/home/deploy/.config/salon/migrations.env` (600, répertoire 700), hors de portée de PHP-FPM. Le déploiement les charge dans un sous-processus, avec un chemin de cache de configuration distinct non généré ; le cache applicatif conserve le compte limité.

Après connexion comme salon-admin :

```sh
# Administration courante de la base, sans compte MariaDB root
mariadb
# Consultation strictement en lecture seule
mariadb --defaults-file=/home/salon-admin/.config/salon/readonly.cnf
```

Les sauvegardes utilisent `/etc/salon/backup.cnf`, accessible seulement à root. Le compte root MariaDB reste disponible localement pour les interventions exceptionnelles de gestion des comptes ; il n'est utilisé ni par Laravel, ni par les migrations, ni par les sauvegardes. Le compte de migrations possède DROP pour les migrations et n'est pas un compte applicatif.

Les requêtes Eloquent et Query Builder utilisent des paramètres liés. Les expressions SQL brutes du code sont statiques. Les noms de colonnes de filtres et de tris ne sont pas construits à partir de saisies libres.

Provisionnement : créer et tester d'abord salon-admin et sa clé, exécuter une seule fois `separate-db-accounts.py` comme root, installer `deploy.sh` et `backup.sh` dans leurs chemins système. Vérifier sauvegarde et migrations avant d'installer `00-salon-access.conf` dans `/etc/ssh/sshd_config.d/`, puis valider `sshd -t` et recharger SSH. Ne pas relancer le script de provisionnement pour faire tourner les mots de passe : il refuse d'écraser les fichiers existants.
