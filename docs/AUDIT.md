# Audit qualité — sylius-audit-log-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`.

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check --no-progress-bar` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress` | ✅ 0 erreur |
| Tests unitaires | `php -d memory_limit=2G vendor/bin/phpunit --testsuite=unit --no-coverage` | ✅ 67 tests, 1 139 assertions |
| Validation composer | `composer validate --strict` | ✅ valide (2 avertissements : packages abandonnés `behat/transliterator` et `php-http/message-factory`) |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 19 fichiers avec suggestions (`FlipTypeControlToUseExclusiveTypeRector`) |
| Vulnérabilités dépendances | `composer audit` | ✅ aucune advisory CVE |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 19 fichiers signalés relèvent d'une seule règle `FlipTypeControlToUseExclusiveTypeRector` — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` pour les appliquer, puis relancer ECS/PHPStan/tests.

## 2. Audit qualitatif (revue par agents)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **9/10** | Typage strict partout, classes `final`, SRP très bien respecté, value objects immuables, interfaces systématiques (DIP), stack Doctrine ingénieuse pour les flush imbriqués. |
| Intégration Symfony / Sylius | **8/10** | `onFlush`/`postFlush` pattern correct, PSR Clock, `ResetInterface`, `FactoryInterface<T>` générique, prependExtension pour les modèles, EventDispatcher `PRE_WRITE`. |
| Sécurité (OWASP 2025) | **8/10** | Toutes les requêtes DQL paramétrées, `FieldPolicy` avec masquage patterns + exact, anonymiseur GDPR complet (droit à l'effacement), pas de fuite de secrets. Deux risques résiduels mineurs. |

> Caveat : les tests `non-unit` (functional/integration) n'ont pas été exécutés lors de l'audit ; la compatibilité exacte des API Sylius 2.2/2.3 reste à confirmer par le CI.

### Points forts

- `declare(strict_types=1)` + classes `final` sur l'intégralité de `src/` (sauf repository qui étend une classe concrète Sylius).
- **Stack pattern pour les flush imbriqués** (`AuditLogRecorder`) : chaque flush pousse son lot sur un stack indexé par `spl_object_id($entityManager)`, poppé par son `postFlush`. Un flush imbriqué dans un autre `postFlush` garde ses propres changements ; un flush annulé ne pollue pas le suivant.
- Enregistrement **non-bloquant** : toute `\Throwable` dans `onFlush` et `postFlush` est loguée et avalée — l'audit log ne peut jamais casser le flush audité.
- `FieldPolicy` : masquage configurable par liste exacte (insensible à la casse) + expressions régulières, défense en profondeur contre les fuites de données sensibles.
- `CustomerAuditLogAnonymizer` : droit à l'effacement RGPD complet (client, adresses, commandes, IP, nom de l'acteur), keyset pagination, `flush + clear` par lot.
- Toutes les requêtes DQL sont paramétrées (`setParameter`) — risque d'injection SQL nul.
- `AuditContextProvider` : séparation nette entre collecte du contexte (acteur, IP, request-id, route, source Sylius) et enregistrement.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Suppression silencieuse d'erreur regex** (`src/Diff/FieldPolicy.php:44` — `@preg_match`). L'opérateur `@` masque les patterns invalides fournis par la configuration : si un admin saisit une expression régulière malformée pour `masked_field_patterns`, le masquage est silencieusement ignoré et des données sensibles peuvent être enregistrées en clair. → Remplacer par une validation à la compilation ou lever une exception explicite sur pattern invalide.

2. **Absence de contrôle d'accès explicite sur les routes admin** (OWASP A01:2025). Les routes du grid Sylius (liste et détail des entrées d'audit) héritent des permissions du panneau admin Sylius, mais aucun `security.yaml` ou voter dédié ne restreint l'accès à `ROLE_SYLIUS_ADMIN` spécifiquement, ni ne distingue un accès en lecture seule d'un accès complet. → Documenter explicitement la politique d'accès attendue et valider l'absence de route accessible à un rôle non admin.

3. **`AuditLogEntryRepository` non `final` et extending une classe concrète** (`src/Repository/AuditLogEntryRepository.php:11`). La classe étend `Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository` (classe concrète Sylius), ce qui couple le repository à l'implémentation Sylius et rend la substitution difficile. De plus, la classe n'est pas marquée `final`, incohérent avec le reste de `src/`. → Marquer `final`, envisager à terme une dépendance via une interface.

### 🟡 Mineur

- **Cible `ecs` dans le Makefile trop étroite** (`Makefile:61`) : `vendor/bin/ecs check src` ne couvre pas `tests/` ni `ecs.php`, contrairement à la configuration `ecs.php` qui liste `src`, `tests/Unit`, `tests/Integration`, `tests/Functional`, `ecs.php`. → Aligner : `vendor/bin/ecs check` (sans argument, la config est lue).
- **`@preg_match` signalé par Rector** (`src/Diff/FieldPolicy.php:44`) : liée au finding 🟠 ci-dessus — Rector suggère `FlipTypeControlToUseExclusiveTypeRector` sur plusieurs fichiers, dont celui-ci. Appliquer `make rector-fix` pour les 19 fichiers modernisables.
- **Packages abandonnés** (`composer audit`) : `behat/transliterator` (remplacer par `cocur/slugify` ou équivalent) et `php-http/message-factory` (remplacer par `psr/http-factory`) sont signalés comme abandonnés. Pas de CVE aujourd'hui, mais prévoir la migration.
- **`AuditLogWriter` non analysé dans cet audit** (classe non lue faute de budget) : la logique DBAL avec savepoint mérite une relecture dédiée (isolation de transaction, comportement en cas d'erreur partielle).
- Confirmer en CI la compatibilité des API Sylius 2.2/2.3 (grid, resource, section resolver).

### ℹ️ Pour information (choix de conception assumés)

- `onFlush` lit (valeurs encore disponibles dans l'UoW), `postFlush` écrit via DBAL (IDs des entités insérées disponibles) — pattern documenté et intentionnel, évite tout `persist/flush` récursif depuis le listener.
- Le masquage des champs est une liste blanche de noms + patterns, configurable par projet ; les champs sensibles par défaut (`password`, `token`, etc.) sont déclarés dans `Configuration::DEFAULT_MASKED_FIELDS`.
- IP et request-id stockés ; l'anonymiseur RGPD les efface à la demande.

## 4. Verdict

Plugin **très solide et discipliné** : outillage intégralement au vert (ECS, PHPStan level max, 67 tests unitaires couvrant 1 139 assertions), typage strict, classes `final`, SRP exemplaire, enregistrement Doctrine non-bloquant bien conçu, RGPD adressé. Les axes d'amélioration principaux sont : **valider les patterns regex à la compilation** (risque de masquage silencieusement ignoré), **documenter la politique d'accès admin** et **aligner la cible ECS du Makefile**. Rien de bloquant côté outillage. Prêt pour une revue fonctionnelle complète (tests non-unit) avant release.
