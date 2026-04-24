- **PHP 8.2** + **Ratchet** — serveur WebSocket
- **Apache** — serveur HTTP (fichiers statiques + proxy WebSocket)
- **Docker Compose** — orchestration des deux conteneurs

---

## Structure du projet

```
makemememe/
├── docker-compose.yml
├── docker/
│   ├── php/
│   │   └── Dockerfile
│   └── apache/
│       ├── Dockerfile
│       └── vhost.conf
├── src/
│   ├── server.php
│   ├── composer.json
│   ├── composer.lock
│   └── vendor/
└── public/
    └── index.html
```

---

## Lancer en local

```bash
docker compose up --build
```

Accès : **http://localhost:8081**

---

## Déployer sur un serveur

**1. Se connecter en SSH**
```bash
ssh user@IP_SERVEUR
```

**2. Vérifier / installer Docker**
```bash
docker --version
# Si pas installé :
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
```
**3. Lancer**
```bash
cd makemememe
docker compose up -d --build
```

Accès : **http://IP_SERVEUR:8081**

---

## Commandes utiles

| Commande | Description |
|---|---|
| `docker compose up --build` | Lancer (avec rebuild) |
| `docker compose up -d --build` | Lancer en arrière-plan |
| `docker compose down` | Arrêter |
| `docker compose logs -f` | Voir les logs en direct |
| `docker compose ps` | Voir l'état des conteneurs |

---

## Comment jouer

1. Ouvre le jeu dans ton navigateur
2. Entre ton pseudo
3. Crée ou rejoins une room
4. Attends qu'un 2e joueur arrive — la partie démarre automatiquement
5. **Phase Écriture (30s)** — écris la meilleure légende pour le mème
6. **Phase Vote (20s)** — vote pour la légende qui te fait le plus rire
7. Le joueur avec le plus de votes gagne le round 🏆
