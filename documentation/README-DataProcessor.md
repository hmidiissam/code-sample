# Plugin Grimmo2025 Import

Plugin WordPress pour l'import des données depuis l'API Grimmo2025.

## Description

Ce plugin permet d'importer automatiquement les programmes et lots depuis l'API Grimmo2025 et de les intégrer dans le système existant d'Arcade Promotion.

## Installation

1. Télécharger le dossier `grimmo2025-import` dans `wp-content/plugins/`
2. Activer le plugin depuis l'administration WordPress
3. Le plugin se lance automatiquement via le cron à 11h chaque jour

## Configuration

### Paramètres API

Les paramètres de connexion API se trouvent dans `includes/class-grimmo-api-client.php` :

```php
$this->baseUrl = '';
$this->username = '';
$this->password = '';
```

### Modification du mapping des champs

#### Pour les Lots

Modifier le tableau `$fieldMapping` dans `includes/class-grimmo-lot-mapper.php` :

```php
private $fieldMapping = array(
    // Champs directs depuis l'API
    'id_lot' => 'id',
    'libelle_lot' => 'numero', 
    'type_logement' => 'typeLot.title',
    
    // Champs calculés ou fixes
    'source' => 'Grimmo2025',
    'n_tranche_commerciale' => '1',
    // ...
);
```

#### Pour les Programmes

Modifier le tableau `$fieldMapping` dans `includes/class-grimmo-programme-mapper.php` :

```php
private $fieldMapping = array(
    // Champs directs depuis l'API
    'code_ci' => 'operation.id',
    'nom_commercial' => 'operation.libelle',
    
    // Champs calculés ou fixes
    'source' => 'Grimmo2025',
    'societe' => 'SOCIETE_GRIMMO',
    // ...
);
```

## Fonctionnalités

### Import automatique
- **Programmes** : Récupération depuis `/api/******`
- **Lots** : Récupération depuis `/api/******` avec expansion des documents
- **Documents** : Téléchargement des plans PDF depuis `/api/********`

### Mapping des données
- Transformation automatique des données JSON vers le format WordPress
- Règles métier configurables facilement
- Validation des données avant import

### Logging complet
- Logs quotidiens dans `logs/grimmo-YYYY-MM-DD.log`
- Logs d'erreurs séparés dans `logs/grimmo-errors-YYYY-MM-DD.log`
- Nettoyage automatique des logs (30 jours)

### Archivage automatique
- Archive les lots non présents dans le nouveau flux
- Gestion des statuts WordPress (publish/archive)



## Logs et debug

### Consulter les logs
```php
$logger = new GrimmoLogger();
echo $logger->getTodayLogs();
echo $logger->getTodayErrors();
```

### Niveaux de log
- `INFO` : Informations générales
- `DEBUG` : Détails techniques
- `WARNING` : Avertissements
- `ERROR` : Erreurs

## Structure des fichiers

```
grimmo2025-import/
├── grimmo2025-import.php          # Fichier principal du plugin
├── includes/
│   ├── class-grimmo-api-client.php        # Client API
│   ├── class-grimmo-logger.php            # Système de logs
│   ├── class-grimmo-lot-mapper.php        # Mapping des lots
│   ├── class-grimmo-programme-mapper.php  # Mapping des programmes
│   └── class-grimmo-data-processor.php    # Processeur principal
├── logs/                          # Dossier des logs (créé automatiquement)
└── README.md                      # Documentation
```

## Intégration avec le système existant

Le plugin s'intègre parfaitement avec :
- Le système de cron existant (`run-cron.php`)
- Les classes `Programmes`, `Lots`, `Tranche` existantes
- Le système de logs et JSON quotidiens
- L'envoi de mails de rapport

## Statuts des lots

Conversion automatique des statuts API vers WordPress :
- `En stock` → `publish` (visible sur le site)
- `Vendu`, `Réservé`, `Acté`, `Loué` → `archive` (archivé)

## Support et maintenance

- Nettoyage automatique des logs anciens
- Gestion des erreurs de connexion API
- Retry automatique en cas d'erreur 401
- Validation des données avant import

## Règles métier spécifiques

### Selon le fichier Lot.csv :
- **Source** : Toujours "Grimmo2025"
- **N° tranche** : Toujours "1"
- **Nature tranche** : Toujours "T_Nature"
- **Tranche active** : Toujours "N"
- **Exposition 1 & 2** : Toujours vides
- **Lots secondaires** : Toujours vides

### Selon le fichier Programme.csv :
- **Source** : Toujours "Grimmo2025"
- **N° tranche commerciale** : Toujours "1"
- **Nature tranche commerciale** : Toujours "T_Nature"
- **Tranche active** : Toujours "N"

## Horaire d'exécution

Le cron Grimmo2025 s'exécute à **11h** chaque jour (modifiable dans `run-cron.php`).

## Installation et déploiement

### 1. Installation du plugin
```bash
# Télécharger le plugin dans le dossier plugins
cd wp-content/plugins/
# Copier le dossier grimmo2025-import

# Activer le plugin
# Via l'interface WordPress : Plugins > Grimmo2025 Import > Activer
```

### 2. Configuration (optionnelle)
```bash
# Copier le fichier de configuration d'exemple
cp config.example.php config.php

# Modifier les paramètres selon vos besoins
nano config.php
```


```

### 3. Mise en production du cron
```bash
# Le cron est automatiquement intégré dans run-cron.php
# Vérifier que le serveur cron appelle bien run-cron.php
```



### Vérification des logs
```bash
# Consulter les logs dans le dossier
wp-content/plugins/grimmo2025-import/logs/

# Format des fichiers :
# grimmo-YYYY-MM-DD.log        (logs généraux)
# grimmo-errors-YYYY-MM-DD.log (erreurs uniquement)
```

## Personnalisation avancée

### Modifier les endpoints API
Éditer `includes/class-grimmo-api-client.php` :
```php
// Changer l'URL de base
$this->baseUrl = 'https://nouvelle-url.com/api';

// Ajouter de nouveaux endpoints
public function getCustomData($id) {
    $url = $this->baseUrl . '/api/custom/' . $id;
    return $this->makeRequest($url);
}
```

### Ajouter des champs au mapping
Éditer `includes/class-grimmo-lot-mapper.php` :
```php
private $fieldMapping = array(
    // Existant...
    'nouveau_champ' => 'api.nouveau.champ',
    'champ_calcule' => '', // Sera traité dans mapLot()
);
```

### Personnaliser le traitement des données
Éditer `includes/class-grimmo-data-processor.php` pour ajouter une logique métier spécifique.

## Résolution des problèmes

### Problème de connexion API
1. Vérifier les credentials dans `class-grimmo-api-client.php`
2. Consulter les logs d'erreurs

### Problème de mapping
1. Vérifier les champs dans les fichiers CSV
2. Ajuster le mapping dans les classes correspondantes
3. Tester avec des données sample

### Problème de cron
1. Vérifier que `run-cron.php` est bien appelé à 11h
2. Consulter les logs du serveur cron
3. Tester manuellement l'import

## Monitoring et maintenance

### Surveillance quotidienne
- Consulter les logs pour détecter les erreurs
- Vérifier que l'import s'exécute correctement
- Contrôler le nombre de programmes/lots importés

### Maintenance périodique
- Les logs sont automatiquement nettoyés (30 jours)
- Surveiller l'espace disque utilisé par les logs
- Mettre à jour les credentials API si nécessaire

### Alertes email
Le système envoie automatiquement des rapports quotidiens avec les tranches archivées, comme pour les autres flux. 