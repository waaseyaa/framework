# Waaseyaa Application

A Waaseyaa CMS application.

## Directory Structure

```
src/
├── Access/        Authorization policies
├── Controller/    HTTP controllers (thin orchestration)
├── Domain/        Domain logic grouped by bounded context
├── Entity/        ORM entities (pure data models)
├── Ingestion/     Inbound data pipelines (files, email, APIs)
├── Provider/      Service providers (bootstrapping, DI, routing)
├── Search/        Search providers, autocomplete, indexing
├── Seed/          Seeders for dev/local bootstrap
└── Support/       Cross-cutting utilities (ValueObjects, helpers)
```

### Domain Rules

Bounded contexts go under `Domain/<ContextName>/` with optional subdirectories:
`Service/`, `ValueObject/`, `Workflow/`, `Assembler/`, `Ranker/`, `Mapper/`.

### Support Rules

Cross-cutting utilities (validators, slug generators, normalizers, distance
calculators) belong in `Support/`.

### Namespace Rules

Namespaces must match PSR-4 directory structure. Update namespaces in files
and all references when moving code.

## Commands

```bash
composer install                    # Install dependencies
php -S localhost:8080 -t public     # Dev server
./vendor/bin/phpunit                # Run tests
bin/waaseyaa                        # CLI
```

## Configuration

- `config/waaseyaa.php` — Framework configuration
- `config/entity-types.php` — Custom entity types
- `config/services.php` — Service overrides

## License

GPL-2.0-or-later
