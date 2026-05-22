# Rapport de Performance - Sprint 2

## 1. Analyse Statique PHPStan (Niveau 6)

### Résultats Avant / Après correction

| Module | Erreurs Avant | Erreurs Après | Corrigées | Réduction |
|--------|:---:|:---:|:---:|:---:|
| Investment | 53 | 33 | 20 | -37.7% |
| User | 12 | 1 | 11 | -91.7% |
| Mentorship | 8 | 3 | 5 | -62.5% |
| Community | 59 | 40 | 19 | -32.2% |
| Projects | 17 | 4 | 13 | -76.5% |
| Apprentissage | 10 | 1 | 9 | -90.0% |
| **TOTAL** | **159** | **82** | **77** | **-48.4%** |

### Types d'erreurs corrigées

| Type d'erreur | Nombre | Action |
|---|:---:|---|
| missingType.iterableValue | 35 | Ajout `@return`/`@param` PHPDoc avec types génériques |
| Type mismatch (UserInterface vs User) | 12 | Cast `/** @var User */` aux frontières sécurité |
| Method on wrong type | 8 | Vérifications instanceof + type hints |
| Undefined method/property | 7 | Ajout méthodes manquantes (ProjetRepository::findByUser) |
| Unused properties | 5 | Refactoring + @phpstan-ignore |
| Syntax error (ParseError) | 1 | Double backslash corrigé |
| Autres (return type, null checks) | 9 | Type annotations + null coalescing |

### Fichiers de preuve
- `var/phpstan-investment-before.txt` / `var/phpstan-investment-after.txt`
- `var/phpstan-user-before.txt` / `var/phpstan-user-after.txt`
- `var/phpstan-mentorship-before.txt` / `var/phpstan-mentorship-after.txt`
- `var/phpstan-community-before.txt` / `var/phpstan-community-after.txt`
- `var/phpstan-projects-before.txt` / `var/phpstan-projects-after.txt`
- `var/phpstan-apprentissage-before.txt` / `var/phpstan-apprentissage-after.txt`

## 2. Tests Unitaires

### Résultats d'exécution

```
PHPUnit 11.5.55
OK (51 tests, 97 assertions)
Time: 00:00.070, Memory: 12.00 MB
```

### Couverture par module

| Module | Fichier Test | Méthodes | Assertions |
|--------|---|:---:|:---:|
| Investment | EconomicRiskEngineTest.php | 11 | 22 |
| Investment | CurrencyServiceTest.php | 8 | 14 |
| User | UserTest.php | 8 | 10 |
| Mentorship | MentoratMatchingServiceTest.php | 6 | 12 |
| Community | CommunityWeatherServiceTest.php | 6 | 10 |
| Projects | ProjetScoringServiceTest.php | 6 | 14 |
| Apprentissage | CoursQuizServiceTest.php | 6 | 15 |
| **TOTAL** | **7 fichiers** | **51** | **97** |

### Performance d'exécution
- **Temps** : 70ms pour 51 tests
- **Mémoire** : 12 MB
- **Moyenne** : 1.37ms par test

## 3. Doctrine ORM

### Validation Mapping
```
Mapping: [OK] The mapping files are correct.
```

### Métriques Relations
| Métrique | Valeur |
|---|---|
| Relations ManyToOne | 15 |
| Relations OneToMany | 10 |
| Relations ManyToMany | 1 |
| Fetch mode LAZY (défaut) | 100% |
| Cascade remove configuré | 5 entités |
| OrphanRemoval activé | 2 entités |

### Optimisations QueryBuilder
- `ProjetRepository::createQueryBuilderWithUser()` → JOIN FETCH évite N+1
- `ProjetRepository::findByUserWithFilters()` → Filtrage SQL optimisé
- `ThreadRepository::findByGroup()` → DQL ordonné
- Index composite recommandé sur `messenger_messages`

## 4. Métriques Globales

| Indicateur | Avant Sprint 2 | Après Sprint 2 | Amélioration |
|---|:---:|:---:|:---:|
| Erreurs PHPStan L6 | 159 | 82 | -48.4% |
| Tests unitaires | 0 | 51 | +51 |
| Assertions | 0 | 97 | +97 |
| Mapping Doctrine | ✅ Valide | ✅ Valide | Maintenu |
| ParseErrors bloquants | 1 | 0 | -100% |
| Temps tests | — | 70ms | Excellent |
| Mémoire tests | — | 12MB | Optimal |

## 5. Recommandations pour Sprint 3

1. Réduire les 82 erreurs PHPStan restantes (principalement `missingType.iterableValue` sur collections Doctrine)
2. Exécuter les 5 migrations en attente pour synchroniser le schéma
3. Supprimer la table orpheline `group_join_request`
4. Ajouter l'index composite sur `messenger_messages`
5. Augmenter la couverture de test vers les controllers (tests fonctionnels)
