# Connectors generation report — strategic stance

_Date: aligns with Knot “coverage maximisation” roadmap._

## Executive summary

- **Default strategy (A)** — expose manipulable Dolibarr objects through **`dolibarr.object`** with metadata from **`ObjectFactory`** + **`SchemaBuilder`** + introspection caches.
- **Avoid** generating hundreds of `class/Connectors/**/*.php` shims (**weight, autoload, drift**).

## Registered slugs (curated MAP)

Twenty-seven curated slugs (PHPUnit-enforced): `thirdparty`, `contact`, `propal`, `commande`, `facture`, `product`, `service`, `project`, `task`, `ticket`, `user`, `member`, `entrepot`, `stockmove`, `agenda`, `actioncomm`, `categorie`, `bankaccount`, `expense`, **`expedition`**, `holiday`, `mailing`, `facturefourn`, `commandefourn`, `propalfourn`, `contrat`, `paiement`.

Plus **descriptor-only slugs** from `scan` payloads inside `data/compatibility/dolibarr-catalog.json`.

## Exceptions (strategy C)

Generate dedicated connector PHP only when:

- A class cannot satisfy `ObjectAction` operations without bespoke methods, **and**
- The gap is proven by failing integration tests or explicit security review.

No new mass-generated connector directory is introduced by the Phase 3 quick win — **`expedition`** reuses the generic stack.

## UI / editor metadata

The Vue editor consumes `api/dolibarr_schemas.php` (`list`, `hash`, per-slug `fetch/create/...`). Additional slugs appear automatically after cache refresh when introspection finds them.
