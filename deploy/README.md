# Mise en ligne de Buddy

1. **VM Azure** : Ubuntu Server 24.04 LTS, taille B1s/B2ats, authentification par clé SSH, ports 22, 80, 443 ouverts.
2. **DNS OVH** : enregistrement `A` `buddy` → IP publique de la VM (TTL court).
3. **Préparation du serveur** (une fois) :
   `ssh azureuser@IP 'sudo bash -s -- buddy.mondomaine.fr moi@exemple.fr' < deploy/provision.sh`
4. **Déploiement manuel** : `./deploy/deploy.sh azureuser@IP` (Git Bash).
5. **CI/CD GitHub Actions** (`.github/workflows/ci.yml`) : tests à chaque push/PR ; déploiement auto sur `main` quand configuré :
   - Variables (Settings → Secrets and variables → Actions → Variables) : `DEPLOY_HOST` (IP), `DEPLOY_USER` (azureuser), `DEPLOY_DOMAIN` (buddy.mondomaine.fr)
   - Secret : `SSH_PRIVATE_KEY` (clé privée dédiée au déploiement, dont la clé publique est dans `~/.ssh/authorized_keys` de la VM)

Les secrets de prod (mot de passe MySQL, APP_SECRET) sont générés sur le serveur dans `/var/www/buddy/shared/.env.local` et ne quittent jamais la VM.
