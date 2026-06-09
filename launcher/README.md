# Pincore launcher

Boot layer for **standalone** `pinoox/pincore` usage (without the platform `launcher/` at project root).

## When to use

| Setup | Entry |
|-------|--------|
| Full Pinoox platform | `{project}/index.php` → `{project}/launcher/bootstrap.php` |
| Pincore repo / package only | `bin/pincore` → `launcher/bootstrap.php` |
| Core tests in this repo | `composer test` → `tests/bootstrap.php` |

## Path resolution

`launcher/core-path.php` defines:

- `PINOOX_CORE_PATH` — this package root
- `PINOOX_BASE_PATH` — host project root, or this package when standalone

Override with env `PINOOX_BASE_PATH` when the host project layout differs.

## Standalone layout

Minimal directories (created empty in the repo):

```
pincore/          ← PINOOX_BASE_PATH when standalone
├── apps/
├── pinker/
├── storage/
├── vendor/
└── launcher/
```

Install deps, then:

```bash
composer install
composer test
php bin/pincore test platform
```
