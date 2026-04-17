# cachly PHP SDK

Official PHP SDK for [cachly.dev](https://cachly.dev) – Managed Valkey/Redis cache.

**GDPR-compliant · German servers · Live in 30 seconds**

## Installation

```bash
composer require cachly/sdk
```

> Requires PHP ≥ 8.1 and `predis/predis ^2.2`.

## Quick Start

```php
use Cachly\CachlyClient;

$cache = new CachlyClient('redis://:your-password@my-instance.cachly.dev:6379');

// Set a value with TTL
$cache->set('user:42', ['name' => 'Alice', 'plan' => 'pro'], ttl: 300);

// Get a value
$user = $cache->get('user:42'); // ['name' => 'Alice', 'plan' => 'pro']

// Check existence
if ($cache->exists('user:42')) { ... }

// TTL refresh
$cache->expire('user:42', 600);

// Atomic counter
$count = $cache->incr('page:views');

// Delete
$cache->del('user:42');
```

## Get-or-Set Pattern

```php
$data = $cache->getOrSet(
    key: 'expensive:report',
    fn:  fn() => $db->runExpensiveReport(),
    ttl: 60,
);
```

## Semantic AI Cache (Speed / Business tiers)

Cache LLM responses by *meaning*, not just exact key. Cut OpenAI costs by 60%.

```php
use Cachly\CachlyClient;

$cache  = new CachlyClient(getenv('CACHLY_URL'));
$openai = new OpenAI\Client(getenv('OPENAI_API_KEY'));

$embedFn = fn(string $text) => $openai
    ->embeddings()
    ->create(['model' => 'text-embedding-3-small', 'input' => $text])
    ->data[0]->embedding;

$result = $cache->semantic()->getOrSet(
    prompt:              $userQuestion,
    fn:                  fn() => $openai->chat($userQuestion),
    embedFn:             $embedFn,
    similarityThreshold: 0.90,
    ttl:                 3600,
);

echo $result->hit
    ? "⚡ Cache hit (similarity={$result->similarity})"
    : "🔄 Fresh from LLM";
echo $result->value;
```

## Laravel Integration

```php
// config/cachly.php
return ['url' => env('CACHLY_URL')];

// AppServiceProvider
$this->app->singleton(CachlyClient::class, fn() => new CachlyClient(config('cachly.url')));

// In a controller / service
public function __construct(private CachlyClient $cache) {}
```

## API Reference

| Method | Description |
|---|---|
| `get(string $key)` | Get a value (null if not found) |
| `set(string $key, mixed $value, ?int $ttl)` | Set a value |
| `del(string ...$keys): int` | Delete keys, returns count |
| `exists(string $key): bool` | Check existence |
| `expire(string $key, int $seconds)` | Update TTL |
| `incr(string $key): int` | Atomic increment |
| `getOrSet(string $key, callable $fn, ?int $ttl)` | Get-or-set pattern |
| `semantic(): SemanticCache` | Access semantic cache helper |
| `raw(): PredisClient` | Direct Predis access |

## Batch API — Multiple Ops in One Round-Trip

Bundle GET/SET/DEL/EXISTS/TTL operations into **one** HTTP request or Predis pipeline.

```php
use Cachly\CachlyClient;
use Cachly\BatchOp;

$cache = new CachlyClient(
    url: $_ENV['CACHLY_URL'],
    batchUrl: $_ENV['CACHLY_BATCH_URL'] ?? null, // optional
);

$results = $cache->batch([
    BatchOp::get('user:1'),
    BatchOp::get('config:app'),
    BatchOp::set('visits', '42', ttl: 86400),
    BatchOp::exists('session:xyz'),
    BatchOp::ttl('token:abc'),
]);

$user  = $results[0]->value;       // string|null
$ok    = $results[2]->ok;          // bool
$found = $results[3]->exists;      // bool
$secs  = $results[4]->ttlSeconds;  // int (-1 = no TTL, -2 = key missing)
```

## Environment Variables

```bash
CACHLY_URL=redis://:your-password@my-app.cachly.dev:30101
CACHLY_BATCH_URL=https://api.cachly.dev/v1/cache/YOUR_TOKEN   # optional
# Speed / Business tier – Semantic AI Cache:
CACHLY_VECTOR_URL=https://api.cachly.dev/v1/sem/your-vector-token
```

Find both values in your [cachly.dev dashboard](https://cachly.dev/instances).

## AI Dev Brain — Persistent Memory for Your Coding Assistant

cachly ships a **30-tool MCP server** that gives Claude Code, Cursor, GitHub Copilot, and Windsurf a persistent memory across sessions.

```bash
npx @cachly-dev/init
```

`session_start(instance_id, focus)` returns a full briefing in one call: last session summary, relevant lessons, open failures, brain health.

→ Full docs: [cachly.dev/docs/ai-memory](https://cachly.dev/docs/ai-memory)

---

## Links

- 📖 [cachly.dev docs](https://cachly.dev/docs)
- 🧠 [AI Memory / MCP Server](https://cachly.dev/docs/ai-memory)
- 🐛 [Issues](https://github.com/cachly-dev/sdk-php/issues)
- 📦 [Packagist](https://packagist.org/packages/cachly/sdk)

---

MIT © [cachly.dev](https://cachly.dev)
