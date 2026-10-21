# Sylius Audit Log Plugin

> English: [README.md](README.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-audit-log-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-audit-log-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Qui a modifié quoi, et quand : un journal d'audit des modifications effectuées dans le back-office de Sylius 2.**

Un prix a baissé, une promotion a démarré un jour trop tôt, un produit a disparu d'un canal : l'administration
de Sylius 2 ne permet pas de savoir qui en est l'auteur. Les plugins d'audit écrits pour Sylius 1 n'ont jamais
été portés.

Ce plugin enregistre les créations, modifications et suppressions des principales ressources Sylius, avec un diff
au niveau des champs (ancien → nouveau), l'administrateur qui les a faites et la requête dont elles proviennent,
et les affiche dans l'administration. Il est gratuit et open source.

## Fonctionnalités

| Besoin | Ce que fait le plugin |
|---|---|
| Savoir qui a modifié une ressource, et comment | Chaque création / modification / suppression des ressources auditées est enregistrée avec un **diff au niveau des champs** (ancien → nouveau), la date (UTC), l'**auteur** (identifiant de l'administrateur + copie du nom d'utilisateur, client, ligne de commande ou système), l'**origine** (panneau d'administration, API d'administration, boutique, API boutique, ligne de commande) et la route ou la commande console. |
| Rechercher dans la piste d'audit | Page **Configuration › Journal d'audit** : une grille Sylius filtrée par période, ressource (type et identifiant), action, auteur et type d'auteur, requête. Chaque entrée a une page avec un tableau de diff lisible. |
| Voir l'historique là où vous travaillez | **Fiche d'historique** sur les pages produit (affichage et édition), commande, client et promotion : les dernières modifications de la ressource et de ce qu'elle possède (variantes, prix, coupons, paiements, adresses...), avec un lien vers la grille filtrée. |
| Regrouper les modifications d'un même enregistrement | Chaque entrée porte un **identifiant de requête** (en-tête `X-Request-Id` lorsqu'il est présent) : « Toutes les modifications de cette requête » liste tout ce qu'un clic a modifié. |
| Garder les secrets hors du journal | Mots de passe, sels, jetons, informations de paiement et configuration des passerelles sont **masqués** : le journal indique qu'ils ont changé, jamais leurs valeurs. Les textes longs sont **tronqués**. |
| Respecter le RGPD | **Conservation** : `tmonier:audit-log:purge` supprime les entrées plus anciennes que `retention_days` (365 par défaut). **Effacement** : `tmonier:audit-log:anonymize-customer` retire de la piste les données personnelles d'un client. Les adresses IP ne sont pas stockées par défaut (anonymisées lorsque c'est activé). |
| Auditer vos propres entités | Ajoutez n'importe quelle entité Doctrine à la configuration ; taguez vos propres résolveurs de libellé ou de propriétaire ; écoutez un événement pour enrichir ou ignorer des entrées. |

Également inclus : traductions en anglais, français, allemand, espagnol, italien, néerlandais et polonais ;
Twig Hooks partout ; prise en charge de MySQL, MariaDB et PostgreSQL.

### Journal d'audit ou chronologie ?

Ce plugin est une piste d'audit **technique** des données modifiées dans le back-office, pour chaque
ressource. Le plugin frère [tmonier/sylius-timeline-plugin](https://github.com/thibmonier/sylius-timeline-plugin)
est une vue **métier** de la vie d'une commande (passée, payée, expédiée, e-mails envoyés) avec des notes
internes du personnel. Ils se complètent et peuvent être installés ensemble.

## Captures d'écran

![Grille du journal d'audit dans l'administration Sylius avec des filtres par date, ressource, action, auteur et requête, listant des modifications faites par deux administrateurs](docs/images/admin-audit-log-grid.jpg)

![Page d'une entrée du journal d'audit montrant qui a modifié une promotion, d'où, et un diff avant/après des champs modifiés](docs/images/admin-audit-log-entry.jpg)

![Fiche d'historique sur une page produit listant les dernières modifications du produit, de sa variante et des prix par canal](docs/images/admin-product-history.jpg)

## Prérequis

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |

## Installation

1. Requérez le paquet :

    ```bash
    composer require tmonier/sylius-audit-log-plugin
    ```

2. Enregistrez le bundle dans `config/bundles.php` (Symfony Flex le fait automatiquement dès qu'une recette existe) :

    ```php
    Tmonier\SyliusAuditLogPlugin\TmonierSyliusAuditLogPlugin::class => ['all' => true],
    ```

3. Importez la configuration dans `config/packages/tmonier_sylius_audit_log.yaml` :

    ```yaml
    imports:
        - { resource: "@TmonierSyliusAuditLogPlugin/config/config.yaml" }
    ```

4. Importez les routes d'administration dans `config/routes/tmonier_sylius_audit_log.yaml` :

    ```yaml
    tmonier_sylius_audit_log:
        resource: "@TmonierSyliusAuditLogPlugin/config/routes.yaml"
    ```

5. Exécutez les migrations :

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Planifiez la purge (cron, Symfony Scheduler...), par exemple une fois par jour :

    ```bash
    bin/console tmonier:audit-log:purge
    ```

## Configuration

Toutes les clés sont facultatives ; les valeurs par défaut sont indiquées.

```yaml
# config/packages/tmonier_sylius_audit_log.yaml
tmonier_sylius_audit_log:
    enabled: true                 # false: record nothing (the admin pages stay available)
    sources: [admin, admin_api, cli]   # also: shop, shop_api, other
    ignored_commands: [sylius:fixtures:load, doctrine:fixtures:load]

    # Merged with the default resources (see below). A resource is a Doctrine class or interface.
    resources:
        tax_category:                                   # add one
            class: Sylius\Component\Taxation\Model\TaxCategoryInterface
        brand:                                          # add one owned by another resource
            class: App\Entity\Brand
            owner: product                              # its entries show on the product history
            owner_property_path: product
        product:
            ignored_fields: [position]                  # extra ignored fields for one resource
        customer: false                                 # stop auditing a default resource

    ignored_fields: [createdAt, updatedAt, lastLogin, emailCanonical, usernameCanonical, version]
    masked_fields: [password, plainPassword, salt, tokenValue, details, config]
    masked_field_patterns: ['/password/i', '/secret/i', '/token/i', '/salt/i', '/credential/i', '/api_?key/i']
    max_value_length: 500         # longer texts are truncated with "…"

    store_ip: false
    anonymize_ip: true            # 203.0.113.42 -> 203.0.113.0 when store_ip is on

    retention_days: 365           # default of tmonier:audit-log:purge; null keeps entries forever
    personal_resources: [customer, address]           # fully anonymised on erasure
    personal_fields: [email, firstName, lastName, phoneNumber, street, city, postcode, customer, notes, ...]

    history_limit: 10             # entries shown in the history cards
```

### Audité par défaut

| Ressource | Propriétaire (fiche d'historique) |
|---|---|
| Produits (avec leurs traductions) | — |
| Variantes (dont le stock `onHand` / `onHold`), prix par canal | produit |
| Taxons | — |
| Promotions ; leurs coupons, règles et actions | promotion |
| Promotions de catalogue ; leurs périmètres et actions | promotion de catalogue |
| Modes de livraison, moyens de paiement, taux de taxe, canaux | — |
| Administrateurs, clients | — |
| Adresses | client (carnet d'adresses) ou commande (adresses de la commande) |
| Commandes ; leurs paiements et expéditions | commande |

Les modifications de traduction sont enregistrées sur la ressource traduite (`name (fr_FR)`), les modifications
plusieurs-à-plusieurs sous forme de listes d'ajouts / de retraits, les références à d'autres ressources sous forme de libellé et d'identifiant.

Seules les origines `admin`, `admin_api` et `cli` sont enregistrées par défaut : la boutique modifie
sans cesse les paniers, le stock et les clients, et ces modifications ne relèvent pas de l'administration. Ajoutez `shop`
à `sources` pour les enregistrer aussi. Le chargement des fixtures n'est pas enregistré.

## Commandes

```bash
# Retention: delete entries older than retention_days, or a given age
bin/console tmonier:audit-log:purge
bin/console tmonier:audit-log:purge --older-than=90d --dry-run
bin/console tmonier:audit-log:purge --older-than="6 months"

# Right to erasure: by e-mail, or by id for a customer already deleted
bin/console tmonier:audit-log:anonymize-customer jane@example.com --dry-run
bin/console tmonier:audit-log:anonymize-customer 42 --email=jane@example.com
```

L'anonymisation couvre les entrées du client, ses adresses, ses commandes (avec leurs
paiements, expéditions et adresses) et les modifications qu'il a faites depuis la boutique. Les entrées concernant une
ressource personnelle perdent toutes leurs valeurs et leur libellé ; les autres entrées (commandes...) perdent les
valeurs des champs personnels. L'adresse IP est supprimée. Les administrateurs qui ont fait les
modifications et les dates restent : la piste demeure exploitable.

## Personnalisation

**Libellés.** Implémentez `Tmonier\SyliusAuditLogPlugin\Resolver\LabelResolverInterface` ; avec
l'autoconfiguration, il est tagué `tmonier_audit_log.label_resolver` (définissez une priorité supérieure à 0 pour
surcharger les libellés du cœur).

**Propriétaires.** Implémentez `Tmonier\SyliusAuditLogPlugin\Resolver\OwnerResolverInterface` (tag
`tmonier_audit_log.owner_resolver`) lorsqu'un chemin de propriété ne suffit pas à trouver le parent d'une
entité.

**Entrées.** Écoutez `Tmonier\SyliusAuditLogPlugin\Event\AuditLogEntryEvent::PRE_WRITE`
(`tmonier_audit_log.entry.pre_write`) pour enrichir une entrée, réécrire ses modifications ou l'ignorer :

```php
#[AsEventListener(event: AuditLogEntryEvent::PRE_WRITE)]
public function __invoke(AuditLogEntryEvent $event): void
{
    if ($event->getSubject() instanceof ProductVariantInterface && ['onHold'] === $event->getEntry()->getChangedFields()) {
        $event->skip();
    }
}
```

**Classe d'entrée.** L'entrée est une ressource Sylius (`tmonier_audit_log.audit_log_entry`) : remplacez
le modèle sous `tmonier_sylius_audit_log.models.audit_log_entry.classes.model` pour ajouter des champs ;
ils sont écrits eux aussi.

**Templates.** Chaque bloc d'administration est un Twig hook : `tmonier_audit_log.admin.audit_log_entry.*`
pour la page d'une entrée, `tmonier_audit_log_history` dans les hooks produit, commande, client et promotion
pour les fiches d'historique.

## Comment les modifications sont enregistrées

Un écouteur Doctrine lit l'unit of work dans `onFlush` (les anciennes valeurs sont encore connues) et écrit
les entrées dans `postFlush` avec de simples insertions DBAL (les nouvelles entités ont alors leurs identifiants).
Les insertions s'exécutent dans un savepoint de la transaction de l'appelant lorsqu'il y en a une. L'écriture ne
déclenche jamais un nouveau flush de l'entity manager : il n'y a donc pas de récursion, et les flushs imbriqués conservent leurs propres
modifications. Toute erreur pendant l'enregistrement est journalisée (canal `tmonier_audit_log`) puis ignorée : le
journal d'audit ne casse jamais la modification qu'il enregistre.

## Limitations

- Les modifications faites sans l'ORM Doctrine (requêtes DBAL ou SQL, `UPDATE` / `DELETE` DQL en masse)
  ne sont pas enregistrées.
- Les entrées sont écrites juste après le commit en base de la modification : si PHP s'arrête entre les
  deux, la modification est conservée et son entrée est perdue.
- Les collections un-à-plusieurs n'apparaissent sur leur propriétaire que lorsque Doctrine les planifie (par
  exemple un coupon ajouté à une promotion) ; l'entité enfant a toujours sa propre entrée.
- Les prix sont stockés sous forme d'entiers dans la plus petite unité monétaire (centimes), comme le fait Sylius.
- Le filtre de date compare des dates locales avec des dates UTC.
- Le RBAC de Sylius Plus n'est pas intégré : tous les administrateurs peuvent lire le journal d'audit.

## Développement

```bash
docker compose up -d mysql mailhog   # or your own MySQL
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n
vendor/bin/console tmonier:audit-log:demo     # demo entries (test application only)

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # needs a test database with fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

<!-- vitrine:demo-b2b:start -->
## Démo B2B

Ce plugin est utilisé dans la [démo B2B Sylius](https://github.com/thibmonier/sylius-b2b-demo) : une boutique Sylius 2.3 avec des données d'exemple fictives, où les plugins B2B fonctionnent ensemble. Le code source, l'installation et les fixtures de la démo sont dans ce dépôt.

- Démo en ligne : [sylius-b2b.thibaut-monier.fr](https://sylius-b2b.thibaut-monier.fr)
- Auteur : [Thibaut Monier](https://www.linkedin.com/in/thibautmonier/)

La démo est un exemple, pas une configuration de production.
<!-- vitrine:demo-b2b:end -->

## Licence

MIT. Voir [LICENSE](LICENSE).
