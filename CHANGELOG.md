# Changelog – cachly SDK (php)

**Language:** PHP  
**Package:** `cachly/sdk` on **Packagist**

> Full cross-SDK release notes: [../CHANGELOG.md](../CHANGELOG.md)

---

## [0.2.0] – 2026-04-07

### Added

- **`mset(array $items)`** – bulk set with per-key TTL via Redis pipeline
- **`mget(array $keys)`** – bulk get in one round-trip; returns `array` (null per missing key)
- **`lock(string $key, ?LockOptions $options = null)`** – distributed lock (SET NX PX + Lua release)
  - Returns `?LockHandle`; `null` when retries exhausted
  - `LockHandle::release()` for early, token-fenced unlock
  - Auto-expires after TTL to prevent deadlocks
- **`streamSet(string $key, iterable $chunks, ?StreamSetOptions $options = null)`** – cache token stream via RPUSH
- **`streamGet(string $key)`** – returns `?Generator`; `null` on miss

### Fixed

- `Known limitations` section updated – bulk ops now implemented

---

## [0.1.0-beta.1] – 2026-04-07

Initial beta release.

### Added

- `set(string $key, mixed $value, ?int $ttl = null)` – store a value with optional TTL
- `get(string $key)` – retrieve a value by key
- `delete(string $key)` – remove a key
- `clear(?string $namespace = null)` – flush namespace or entire cache
- **Semantic cache:** `$client->semantic()->set(...)`, `get(...)`, `clear()`
- Namespace support via `withNamespace(string $ns)`
- API-key-based authentication
- TLS by default, EU data residency (German servers)

### Known limitations

- ~~Bulk operations (`mset` / `mget`) not yet implemented~~ ✅ resolved in v0.2.0
- Pub/Sub not yet supported

---

## [Unreleased]

See [../CHANGELOG.md](../CHANGELOG.md) for upcoming features.

