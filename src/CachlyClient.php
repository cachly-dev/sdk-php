<?php

declare(strict_types=1);

namespace Cachly;

use Predis\Client as PredisClient;

/**
 * Three-level confidence band for a semantic cache hit.
 *
 * - HIGH:      similarity ≥ highConfidenceThreshold (default 0.97) – serve directly.
 * - MEDIUM:    similarity ≥ threshold – consider A/B logging.
 * - UNCERTAIN: cache miss.
 */
final class SemanticConfidence
{
    public const HIGH      = 'high';
    public const MEDIUM    = 'medium';
    public const UNCERTAIN = 'uncertain';
}

/**
 * SemanticResult – returned by SemanticCache::getOrSet().
 */
final class SemanticResult
{
    public function __construct(
        public readonly mixed   $value,
        public readonly bool    $hit,
        public readonly ?float  $similarity = null,
        /** @var string|null One of SemanticConfidence::HIGH / MEDIUM / UNCERTAIN */
        public readonly ?string $confidence = null,
    ) {}
}

/**
 * Metadata about a single semantic cache entry.
 */
final class SemEntryInfo
{
    public function __construct(
        /** Full emb Redis key – pass to invalidate() to remove this entry. */
        public readonly string $key,
        public readonly string $prompt,
    ) {}
}

/**
 * SemanticCache – cache LLM responses by *meaning*, not just exact key.
 *
 * When $vectorUrl is set → uses cachly pgvector API (HNSW O(log n)) with
 * graceful SCAN fallback if the API is unreachable.
 *
 * **Storage layout (split keys):**
 * - `{ns}:emb:{uuid}` – lightweight: `{"embedding":[...],"prompt":"..."}` (SCAN mode only)
 * - `{ns}:val:{uuid}` – actual cached value (always in Valkey)
 */
final class SemanticCache
{
    private const DEFAULT_NAMESPACE               = 'cachly:sem';
    private const DEFAULT_HIGH_CONF_THRESHOLD     = 0.97;

    private const DEFAULT_FILLER_WORDS = [
        // EN
        'please', 'hey', 'hi', 'hello',
        'could you', 'can you', 'would you', 'will you',
        'just', 'quickly', 'briefly', 'simply',
        'tell me', 'show me', 'give me', 'help me', 'assist me',
        'explain to me', 'describe to me',
        'i need', 'i want', 'i would like', "i'd like", "i'm looking for",
        // DE
        'bitte', 'mal eben', 'schnell', 'kurz', 'einfach',
        'kannst du', 'könntest du', 'könnten sie', 'würden sie', 'würdest du',
        'hallo', 'hi', 'hey',
        'sag mir', 'zeig mir', 'gib mir', 'hilf mir', 'erkläre mir', 'erklär mir',
        'ich brauche', 'ich möchte', 'ich hätte gerne', 'ich suche',
        // FR
        "s'il vous plaît", 'svp', 'stp', 'bonjour', 'salut', 'allô',
        'pouvez-vous', 'pourriez-vous', 'peux-tu', 'pourrais-tu',
        'dis-moi', 'dites-moi', 'montre-moi', 'montrez-moi',
        "j'ai besoin de", 'je voudrais', 'je cherche', 'je souhaite',
        'expliquez-moi', 'explique-moi', 'aidez-moi', 'aide-moi',
        // ES
        'por favor', 'hola', 'oye',
        'puedes', 'podrías', 'podría usted', 'me puedes', 'me podrías',
        'dime', 'dígame', 'muéstrame', 'muéstreme', 'dame', 'deme',
        'necesito', 'quisiera', 'me gustaría', 'quiero saber',
        'ayúdame', 'ayúdeme', 'explícame', 'explíqueme',
        // IT
        'per favore', 'perfavore', 'ciao', 'salve', 'ehi',
        'potresti', 'mi potresti', 'potrebbe', 'mi potrebbe',
        'dimmi', 'mi dica', 'mostrami', 'dammi', 'mi dia',
        'ho bisogno di', 'vorrei', 'mi piacerebbe',
        'aiutami', 'mi aiuti', 'spiegami', 'mi spieghi',
        // PT
        'por favor', 'olá', 'oi', 'ei',
        'pode', 'poderia', 'você poderia', 'você pode', 'podes',
        'me diga', 'diga-me', 'me mostre', 'mostre-me', 'me dê', 'dê-me',
        'preciso de', 'gostaria de', 'quero saber', 'estou procurando',
        'me ajude', 'ajude-me', 'explique-me', 'me explique',
    ];

    /** Edge Worker URL for routing search reads through Cloudflare (optional). */
    private ?string $edgeUrl = null;

    public function __construct(
        private readonly PredisClient $redis,
        private readonly ?string $vectorUrl = null,
        ?string $edgeUrl = null,
    ) {
        $this->edgeUrl = $edgeUrl ? rtrim($edgeUrl, '/') : null;
    }

    // ── HTTP helper ────────────────────────────────────────────────────────────

    /**
     * Perform an HTTP request against the cachly vector API.
     * Uses file_get_contents + stream_context_create (no curl / Guzzle needed).
     *
     * @param  string      $method  GET | POST | DELETE
     * @param  string      $url     Full URL
     * @param  array|null  $body    Request body (JSON-encoded when not null)
     * @return array       Decoded JSON response
     * @throws \RuntimeException on HTTP error or non-2xx response
     */
    private function httpRequest(string $method, string $url, ?array $body = null): array
    {
        $options = [
            'http' => [
                'method'        => $method,
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $ctx = stream_context_create($options);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("cachly: HTTP request failed: {$method} {$url}");
        }
        // Check HTTP response code from $http_response_header (populated by file_get_contents).
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $m) && (int) $m[1] >= 400) {
            throw new \RuntimeException("cachly: API returned {$m[1]}: {$method} {$url}");
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    // ── Internal helpers ───────────────────────────────────────────────────────

    /**
     * Derive the val key from an emb key.
     * Format: {ns}:emb:{uuid} → {ns}:val:{uuid}
     */
    private static function valKey(string $embKey): string
    {
        $lastColon = strrpos($embKey, ':');
        $uuidPart  = substr($embKey, $lastColon);  // ":uuid-str"
        $nsType    = substr($embKey, 0, $lastColon); // "{ns}:emb"
        $secondLastColon = strrpos($nsType, ':');
        $ns = substr($nsType, 0, $secondLastColon); // "{ns}"
        return $ns . ':val' . $uuidPart;
    }

    /** Extract the UUID (last segment) from any key. */
    private static function extractId(string $key): string
    {
        $pos = strrpos($key, ':');
        return $pos !== false ? substr($key, $pos + 1) : $key;
    }

    /** Derive the namespace from an emb/val key: {ns}:emb:{uuid} → {ns} */
    private static function extractNamespace(string $key): string
    {
        $last   = strrpos($key, ':');
        if ($last === false) return self::DEFAULT_NAMESPACE;
        $nsType = substr($key, 0, $last);
        $second = strrpos($nsType, ':');
        return $second !== false ? substr($nsType, 0, $second) : self::DEFAULT_NAMESPACE;
    }

    /** Cursor-based SCAN; returns all matching keys without blocking the server. */
    private function scanAll(string $pattern): array
    {
        $keys   = [];
        $cursor = 0;
        do {
            $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
            $cursor = (int) $result[0];
            $keys   = array_merge($keys, $result[1]);
        } while ($cursor !== 0);
        return $keys;
    }

    /** Generate a UUID v4 string using cryptographically secure random bytes. */
    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** @param float[] $a @param float[] $b */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        $denom = sqrt($normA) * sqrt($normB);
        return $denom === 0.0 ? 0.0 : $dot / $denom;
    }

    // ── Prompt normalisation ───────────────────────────────────────────────────

    /**
     * Strip filler words, lowercase, collapse whitespace. +8–12% hit-rate uplift.
     *
     * @param  string[]|null $fillerWords Custom list; pass null for defaults.
     */
    public static function normalizePrompt(string $text, ?array $fillerWords = null): string
    {
        $s     = strtolower(trim($text));
        $words = $fillerWords ?? self::DEFAULT_FILLER_WORDS;
        foreach ($words as $fw) {
            $s = preg_replace('/\b' . preg_quote($fw, '/') . '\b/iu', '', $s) ?? $s;
        }
        $s = (string) preg_replace('/\s+/', ' ', $s);
        $s = (string) preg_replace('/[?!]+$/', '?', $s);
        return trim($s);
    }

    /** Returns the confidence band for a similarity score. */
    private static function confidenceBand(
        float $similarity,
        float $threshold,
        float $highThreshold,
    ): string {
        if ($similarity >= $highThreshold) return SemanticConfidence::HIGH;
        if ($similarity >= $threshold)     return SemanticConfidence::MEDIUM;
        return SemanticConfidence::UNCERTAIN;
    }

    // ── §4 Namespace Auto-Detection ────────────────────────────────────────────

    private const CODE_KW = [
        'function ', 'def ', 'class ', 'import ', 'const ', 'let ', 'var ',
        'return ', ' => ', 'void ', 'public class', 'func ', '#include', 'package ',
        'struct ', 'interface ', 'async def', 'lambda ', '#!/',
    ];
    private const TRANSLATION_KW = [
        'translate', 'übersetze', 'auf deutsch', 'auf englisch',
        'in english', 'in german', 'ins deutsche', 'ins englische', 'übersetz',
        'traduce', 'traduis', 'vertaal',
    ];
    private const SUMMARY_KW = [
        'summarize', 'summarise', 'summary', 'zusammenfass', 'tl;dr', 'tldr',
        'key points', 'stichpunkte', 'fasse zusammen', 'give me a brief',
        'kurze zusammenfassung', 'in a nutshell',
    ];
    private const QA_PREFIXES = [
        'what ', 'who ', 'where ', 'when ', 'why ', 'how ', 'which ',
        'is ', 'are ', 'was ', 'were ', 'does ', 'do ', 'did ',
        'can ', 'could ', 'would ', 'should ', 'will ',
        'wer ', 'wie ', 'wo ', 'wann ', 'warum ', 'welche', 'wieso ',
    ];

    /**
     * §4 – Classify a prompt into one of 5 semantic namespaces using text heuristics.
     *
     * Overhead: < 0.1 ms. No embedding required.
     *
     * @return string one of cachly:sem:code|:translation|:summary|:qa|:creative
     */
    public static function detectNamespace(string $prompt): string
    {
        $s = strtolower(trim($prompt));
        foreach (self::CODE_KW        as $kw) { if (str_contains($s, $kw)) return 'cachly:sem:code'; }
        foreach (self::TRANSLATION_KW as $kw) { if (str_contains($s, $kw)) return 'cachly:sem:translation'; }
        foreach (self::SUMMARY_KW     as $kw) { if (str_contains($s, $kw)) return 'cachly:sem:summary'; }
        foreach (self::QA_PREFIXES    as $p)  { if (str_starts_with($s, $p)) return 'cachly:sem:qa'; }
        if (str_ends_with(rtrim($s), '?'))    return 'cachly:sem:qa';
        return 'cachly:sem:creative';
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Return a cached response for semantically similar prompts, or call $fn
     * and cache the result.
     *
     * When vectorUrl is set → uses pgvector API with graceful SCAN fallback.
     *
     * @template T
     * @param  string           $prompt
     * @param  callable(): T    $fn
     * @param  callable(string): float[] $embedFn
     * @param  float            $similarityThreshold
     * @param  int|null         $ttl                TTL in seconds
     * @param  string           $namespace
     * @param  bool             $normalizePrompt    Strip filler words before embedding
     * @param  float            $highConfidenceThreshold
     * @param  bool             $useAdaptiveThreshold §1 – use server-calibrated threshold
     * @param  bool             $autoNamespace        §4 – auto-detect namespace from prompt
     * @param  string|null      $quantize            §7 – null|'int8'
     * @param  bool             $useHybrid           §3 – BM25+Vector RRF fusion search
     * @return SemanticResult
     */
    public function getOrSet(
        string $prompt,
        callable $fn,
        callable $embedFn,
        float $similarityThreshold = 0.85,
        ?int $ttl = null,
        string $namespace = self::DEFAULT_NAMESPACE,
        bool $normalizePrompt = true,
        float $highConfidenceThreshold = self::DEFAULT_HIGH_CONF_THRESHOLD,
        bool $useAdaptiveThreshold = false,
        bool $autoNamespace = false,
        ?string $quantize = null,
        bool $useHybrid = false,
    ): SemanticResult {
        $textForEmbed = $normalizePrompt ? self::normalizePrompt($prompt) : $prompt;

        // §4 – auto-detect namespace when requested and default namespace is unchanged.
        if ($autoNamespace && $namespace === self::DEFAULT_NAMESPACE) {
            $namespace = self::detectNamespace($prompt);
        }

        // §1 – replace static threshold with server-calibrated value when requested.
        if ($useAdaptiveThreshold && $this->vectorUrl !== null) {
            $similarityThreshold = $this->adaptiveThreshold($namespace);
        }

        if ($this->vectorUrl !== null) {
            try {
                return $this->getOrSetViaApi(
                    $textForEmbed, $fn, $embedFn, $similarityThreshold,
                    $ttl, $namespace, $highConfidenceThreshold, $quantize, $useHybrid,
                );
            } catch (\Throwable) {
                // Graceful degradation: API unreachable → fall back to linear SCAN.
            }
        }
        return $this->getOrSetViaScan(
            $textForEmbed, $fn, $embedFn, $similarityThreshold,
            $ttl, $namespace, $highConfidenceThreshold, $prompt,
        );
    }

    /** Uses the cachly pgvector API (HNSW O(log n)). */
    private function getOrSetViaApi(
        string $prompt,
        callable $fn,
        callable $embedFn,
        float $threshold,
        ?int $ttl,
        string $namespace,
        float $highThreshold = self::DEFAULT_HIGH_CONF_THRESHOLD,
        ?string $quantize = null,
        bool $useHybrid = false,
    ): SemanticResult {
        $queryEmbed = $embedFn($prompt);

        // §7 – build search body with optional int8 quantization.
        if ($quantize === 'int8') {
            $searchBody = [
                'embedding_q8' => self::quantizeEmbedding($queryEmbed),
                'namespace'    => $namespace,
                'threshold'    => $threshold,
            ];
        } else {
            $searchBody = [
                'embedding' => $queryEmbed,
                'namespace' => $namespace,
                'threshold' => $threshold,
            ];
        }

        // §3 – hybrid BM25+Vector RRF: include prompt and set hybrid=true.
        if ($useHybrid && $prompt !== '') {
            $searchBody['hybrid'] = true;
            $searchBody['prompt'] = $prompt;
        }

        $searchUrl  = ($this->edgeUrl ?? $this->vectorUrl) . '/search';
        $searchResp = $this->httpRequest('POST', $searchUrl, $searchBody);

        if (!empty($searchResp['found']) && !empty($searchResp['id'])) {
            $id  = $searchResp['id'];
            $sim = (float) ($searchResp['similarity'] ?? 0.0);
            $vKey = "{$namespace}:val:{$id}";
            $raw = $this->redis->get($vKey);
            if ($raw !== null) {
                $decoded = json_decode($raw, true);
                return new SemanticResult(
                    value:      $decoded,
                    hit:        true,
                    similarity: $sim,
                    confidence: self::confidenceBand($sim, $threshold, $highThreshold),
                );
            }
            // orphaned pgvector entry – fall through to miss
        }

        // Cache miss.
        $value = $fn();
        $id    = self::uuid4();
        $vKey  = "{$namespace}:val:{$id}";

        $valPayload = json_encode($value, JSON_THROW_ON_ERROR);
        if ($ttl !== null) {
            $this->redis->setex($vKey, $ttl, $valPayload);
        } else {
            $this->redis->set($vKey, $valPayload);
        }

        // §7 – index with quantized or full embedding.
        $indexBody = ['id' => $id, 'prompt' => $prompt, 'namespace' => $namespace];
        if ($quantize === 'int8') {
            $indexBody['embedding_q8'] = self::quantizeEmbedding($queryEmbed);
        } else {
            $indexBody['embedding'] = $queryEmbed;
        }
        if ($ttl !== null) {
            $indexBody['expires_at'] = (new \DateTimeImmutable("+{$ttl} seconds"))->format(\DateTimeInterface::RFC3339);
        }
        try {
            $this->httpRequest('POST', $this->vectorUrl . '/entries', $indexBody);
        } catch (\Throwable) { /* best-effort */ }

        return new SemanticResult(value: $value, hit: false);
    }

    /** Uses linear SCAN over Valkey emb keys (fallback). */
    private function getOrSetViaScan(
        string $prompt,
        callable $fn,
        callable $embedFn,
        float $similarityThreshold,
        ?int $ttl,
        string $namespace,
        float $highThreshold = self::DEFAULT_HIGH_CONF_THRESHOLD,
        string $originalPrompt = '',
    ): SemanticResult {
        $queryEmbed = $embedFn($prompt);

        $bestSim    = -PHP_FLOAT_MAX;
        $bestValKey = null;

        foreach ($this->scanAll("{$namespace}:emb:*") as $embKey) {
            $raw = $this->redis->get($embKey);
            if ($raw === null) continue;
            $entry = json_decode($raw, true);
            if (!isset($entry['embedding'])) continue;
            $sim = self::cosineSimilarity($queryEmbed, $entry['embedding']);
            if ($sim > $bestSim) {
                $bestSim    = $sim;
                $bestValKey = self::valKey($embKey);
            }
        }

        if ($bestValKey !== null && $bestSim >= $similarityThreshold) {
            $valRaw = $this->redis->get($bestValKey);
            if ($valRaw !== null) {
                $decoded = json_decode($valRaw, true);
                return new SemanticResult(
                    value:      $decoded,
                    hit:        true,
                    similarity: $bestSim,
                    confidence: self::confidenceBand($bestSim, $similarityThreshold, $highThreshold),
                );
            }
        }

        // Cache miss – write val first, then emb.
        $value  = $fn();
        $id     = self::uuid4();
        $embKey = "{$namespace}:emb:{$id}";
        $vKey   = "{$namespace}:val:{$id}";

        $embPayload = json_encode([
            'embedding'       => $queryEmbed,
            'prompt'          => $prompt,
            'original_prompt' => $originalPrompt !== '' ? $originalPrompt : $prompt,
        ], JSON_THROW_ON_ERROR);
        $valPayload = json_encode($value, JSON_THROW_ON_ERROR);

        if ($ttl !== null) {
            $this->redis->setex($vKey, $ttl, $valPayload);
            $this->redis->setex($embKey, $ttl, $embPayload);
        } else {
            $this->redis->set($vKey, $valPayload);
            $this->redis->set($embKey, $embPayload);
        }

        return new SemanticResult(value: $value, hit: false);
    }

    /**
     * Remove a single semantic cache entry by its key ({ns}:emb:{uuid}).
     *
     * In API mode: deletes val from Valkey + calls DELETE API.
     * In SCAN mode: deletes both emb and val from Valkey.
     *
     * @return int 1 if deleted, 0 otherwise
     */
    public function invalidate(string $key): int
    {
        $id  = self::extractId($key);
        $ns  = self::extractNamespace($key);
        $vKey = "{$ns}:val:{$id}";

        if ($this->vectorUrl !== null) {
            $this->redis->del([$vKey]);
            try {
                $this->httpRequest('DELETE', $this->vectorUrl . '/entries/' . $id);
                return 1;
            } catch (\Throwable) {
                return 0;
            }
        }

        $deleted = (int) $this->redis->del([$key, $vKey]);
        return $deleted > 0 ? 1 : 0;
    }

    /**
     * List every cached prompt together with its key.
     *
     * In API mode: queries pgvector API, returns keys of the form {ns}:emb:{uuid}.
     * In SCAN mode: scans Valkey emb keys.
     *
     * @return SemEntryInfo[]
     */
    public function entries(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        if ($this->vectorUrl !== null) {
            return $this->entriesViaApi($namespace);
        }
        return $this->entriesViaScan($namespace);
    }

    private function entriesViaApi(string $namespace): array
    {
        $resp = $this->httpRequest('GET', $this->vectorUrl . '/entries?namespace=' . urlencode($namespace));
        $result = [];
        foreach ($resp['data'] ?? [] as $item) {
            if (empty($item['id'])) continue;
            $result[] = new SemEntryInfo(
                key:    "{$namespace}:emb:{$item['id']}",
                prompt: $item['prompt'] ?? '',
            );
        }
        return $result;
    }

    private function entriesViaScan(string $namespace): array
    {
        $result = [];
        foreach ($this->scanAll("{$namespace}:emb:*") as $embKey) {
            $raw = $this->redis->get($embKey);
            if ($raw === null) continue;
            $entry = json_decode($raw, true);
            $result[] = new SemEntryInfo(key: $embKey, prompt: $entry['original_prompt'] ?? $entry['prompt'] ?? '');
        }
        return $result;
    }

    /**
     * Delete all semantic cache entries in a namespace.
     * Returns count of logical entries deleted.
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): int
    {
        if ($this->vectorUrl !== null) {
            return $this->flushViaApi($namespace);
        }
        return $this->flushViaScan($namespace);
    }

    private function flushViaApi(string $namespace): int
    {
        try {
            $resp    = $this->httpRequest('DELETE', $this->vectorUrl . '/flush?namespace=' . urlencode($namespace));
            $deleted = (int) ($resp['deleted'] ?? 0);
        } catch (\Throwable) {
            $deleted = 0;
        }
        // Purge val keys from Valkey.
        $valKeys = $this->scanAll("{$namespace}:val:*");
        if (!empty($valKeys)) {
            $this->redis->del($valKeys);
        }
        return $deleted;
    }

    private function flushViaScan(string $namespace): int
    {
        $embKeys = $this->scanAll("{$namespace}:emb:*");
        $valKeys = $this->scanAll("{$namespace}:val:*");
        $all     = array_merge($embKeys, $valKeys);
        if (!empty($all)) {
            $this->redis->del($all);
        }
        return count($embKeys);
    }

    /** Return the number of entries in a namespace. */
    public function size(string $namespace = self::DEFAULT_NAMESPACE): int
    {
        if ($this->vectorUrl !== null) {
            try {
                $resp = $this->httpRequest('GET', $this->vectorUrl . '/size?namespace=' . urlencode($namespace));
                return (int) ($resp['size'] ?? 0);
            } catch (\Throwable) {
                // Fallback to SCAN.
            }
        }
        return count($this->scanAll("{$namespace}:emb:*"));
    }

    // ── §1 Adaptive Threshold ──────────────────────────────────────────────────

    /**
     * Record whether a cache hit was accepted as correct (§1).
     *
     * @param string $hitId      Entry UUID returned on a hit
     * @param bool   $accepted   true = cached answer was correct
     * @param float  $similarity Cosine similarity of the hit
     * @param string $namespace  Key namespace
     */
    public function feedback(
        string $hitId,
        bool $accepted,
        float $similarity = 0.0,
        string $namespace = self::DEFAULT_NAMESPACE,
    ): void {
        if ($this->vectorUrl === null || $hitId === '') return;
        try {
            $this->httpRequest('POST', $this->vectorUrl . '/feedback', [
                'hit_id'     => $hitId,
                'accepted'   => $accepted,
                'similarity' => $similarity,
                'namespace'  => $namespace,
            ]);
        } catch (\Throwable) { /* best-effort */ }
    }

    /**
     * Return the server-side F1-calibrated threshold (§1).
     * Falls back to 0.85 when no calibration data exists.
     */
    public function adaptiveThreshold(string $namespace = self::DEFAULT_NAMESPACE): float
    {
        if ($this->vectorUrl === null) return 0.85;
        try {
            $resp = $this->httpRequest('GET',
                $this->vectorUrl . '/threshold?namespace=' . urlencode($namespace));
            return (float) ($resp['threshold'] ?? 0.85);
        } catch (\Throwable) {
            return 0.85;
        }
    }

    // ── §7 int8 Quantization ───────────────────────────────────────────────────

    /**
     * Scalar-quantize a float embedding to int8 [-128, 127] (§7).
     *
     * Reduces API JSON payload ~8x. Pass 'int8' as $quantize in getOrSet().
     *
     * @param  float[] $vec
     * @return array{values: int[], min: float, max: float}
     */
    public static function quantizeEmbedding(array $vec): array
    {
        if (empty($vec)) return ['values' => [], 'min' => 0.0, 'max' => 0.0];
        $min = min($vec);
        $max = max($vec);
        $rng = $max - $min;
        if ($rng == 0.0) {
            return ['values' => array_fill(0, count($vec), 0), 'min' => $min, 'max' => $max];
        }
        $scale = 255.0 / $rng;
        $values = [];
        foreach ($vec as $v) {
            $q = (int) round($scale * ($v - $min)) - 128;
            $values[] = max(-128, min(127, $q));
        }
        return ['values' => $values, 'min' => $min, 'max' => $max];
    }

    // ── §8 Cache-Warming ──────────────────────────────────────────────────────

    /**
     * §8 – Pre-warm the semantic cache with an array of prompt/fn pairs.
     *
     * Each entry must be an associative array with keys:
     *   - 'prompt' (string, required)
     *   - 'fn' (callable(): mixed, required)
     *   - 'namespace' (string, optional)
     *
     * A high default threshold (0.98) is used so already-cached entries are returned
     * without calling 'fn' unnecessarily.
     *
     * @param  array<int, array{prompt: string, fn: callable, namespace?: string}> $entries
     * @param  callable(string): float[] $embedFn
     * @param  float  $similarityThreshold  Default: 0.98 (high, to skip existing)
     * @param  int|null $ttl
     * @param  bool   $autoNamespace        §4 – auto-detect namespace per entry
     * @return array{warmed: int, skipped: int}
     */
    public function warmup(
        array $entries,
        callable $embedFn,
        float $similarityThreshold = 0.98,
        ?int $ttl = null,
        bool $autoNamespace = false,
    ): array {
        $warmed = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            $prompt = $entry['prompt'] ?? '';
            $fn     = $entry['fn'] ?? null;
            if ($prompt === '' || !is_callable($fn)) { $skipped++; continue; }

            $ns = $entry['namespace']
                ?? ($autoNamespace ? self::detectNamespace($prompt) : self::DEFAULT_NAMESPACE);
            try {
                $result = $this->getOrSet(
                    prompt: $prompt,
                    fn: $fn,
                    embedFn: $embedFn,
                    similarityThreshold: $similarityThreshold,
                    ttl: $ttl,
                    namespace: $ns,
                );
                $result->hit ? $skipped++ : $warmed++;
            } catch (\Throwable) {
                $skipped++;
            }
        }
        return ['warmed' => $warmed, 'skipped' => $skipped];
    }

    // ── §8 Import from JSONL log ──────────────────────────────────────────────

    /**
     * §8 – Import prompts from a JSONL file and warm the cache in batches.
     *
     * Each line must be a JSON object. The prompt is extracted from $promptField
     * (default: "prompt"). $responseFn is called for every cache miss.
     *
     * @param string   $filePath       Path to a JSONL file.
     * @param callable $responseFn     Called on cache miss: fn(string $prompt): mixed
     * @param callable $embedFn        Embedding function.
     * @param string   $promptField    JSON key holding the prompt (default: "prompt").
     * @param int      $batchSize      Prompts per batch (default: 50).
     * @param float    $threshold      Similarity threshold (default: 0.98).
     * @param int|null $ttl            TTL for new entries; null = no expiry.
     * @param bool     $autoNamespace  Auto-detect namespace per entry (§4).
     * @return array{warmed: int, skipped: int}
     * @throws \RuntimeException when the file cannot be read
     */
    public function importFromLog(
        string $filePath,
        callable $responseFn,
        callable $embedFn,
        string $promptField = 'prompt',
        int $batchSize = 50,
        float $threshold = 0.98,
        ?int $ttl = null,
        bool $autoNamespace = false,
    ): array {
        $field   = $promptField !== '' ? $promptField : 'prompt';
        $bs      = $batchSize > 0 ? $batchSize : 50;
        $warmThr = $threshold > 0.0 ? $threshold : 0.98;

        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cachly: cannot open file: $filePath");
        }

        $totalWarmed = 0;
        $totalSkipped = 0;
        $batch = [];

        $flush = function () use (&$batch, $embedFn, $warmThr, $ttl, $autoNamespace,
                                   &$totalWarmed, &$totalSkipped): void {
            if (empty($batch)) { return; }
            $result = $this->warmup($batch, $embedFn, $warmThr, $ttl, $autoNamespace);
            $totalWarmed  += $result['warmed'];
            $totalSkipped += $result['skipped'];
            $batch = [];
        };

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') { continue; }
            $obj = json_decode($line, true);
            if (!is_array($obj)) { $totalSkipped++; continue; }
            $prompt = $obj[$field] ?? '';
            if (!is_string($prompt) || $prompt === '') { $totalSkipped++; continue; }
            $p = $prompt;
            $batch[] = [
                'prompt' => $p,
                'fn'     => static fn () => $responseFn($p),
            ];
            if (count($batch) >= $bs) {
                $flush();
            }
        }
        fclose($handle);
        $flush();

        return ['warmed' => $totalWarmed, 'skipped' => $totalSkipped];
    }

    // ── New API methods (SDK Feature Gap) ─────────────────────────────────────

    /**
     * Set the F1-calibrated similarity threshold for a namespace.
     * POST /v1/sem/:token/threshold
     */
    public function setThreshold(string $namespace, float $threshold): void
    {
        $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/threshold', [
            'namespace' => $namespace, 'threshold' => $threshold,
        ]);
    }

    /**
     * Return cache statistics.
     * GET /v1/sem/:token/stats?namespace=…
     *
     * @return array{hits: int, misses: int, hit_rate: float, total: int, namespaces: array}
     */
    public function stats(string $namespace = 'cachly:sem'): array
    {
        $url = rtrim($this->vectorUrl ?? '', '/') . '/stats?namespace=' . urlencode($namespace);
        return $this->apiRequest('GET', $url) ?? [];
    }

    /**
     * SSE-streaming semantic search – yields text chunks.
     * POST /v1/sem/:token/search/stream
     *
     * @param callable(string): float[] $embedFn
     * @return \Generator<string>
     */
    public function streamSearch(
        string $prompt,
        callable $embedFn,
        string $namespace = 'cachly:sem',
        float $threshold = 0.85,
    ): \Generator {
        if ($this->vectorUrl === null) { return; }
        $textForEmbed = self::normalizePromptText($prompt);
        $embedding    = $embedFn($textForEmbed);
        $url = rtrim($this->vectorUrl, '/') . '/search/stream';

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: text/event-stream",
                'content' => json_encode(['embedding' => $embedding, 'namespace' => $namespace,
                                          'threshold' => $threshold, 'prompt' => $prompt]),
                'ignore_errors' => true,
            ],
        ]);
        $stream = @fopen($url, 'r', false, $ctx);
        if ($stream === false) { return; }
        try {
            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) { break; }
                $line = rtrim($line);
                if (!str_starts_with($line, 'data:')) { continue; }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '{}') { continue; }
                $decoded = json_decode($data, true);
                $text = $decoded['text'] ?? '';
                if ($text !== '') { yield $text; }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Bulk-index up to 500 semantic cache entries.
     * POST /v1/sem/:token/entries/batch
     *
     * @param  array<int, array{id: string, prompt: string, embedding: float[], namespace: string, expires_at?: string}> $entries
     * @return array{indexed: int, skipped: int}
     */
    public function batchIndex(array $entries): array
    {
        if (count($entries) > 500) {
            throw new \InvalidArgumentException('batchIndex: max 500 entries per request');
        }
        return $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/entries/batch',
            ['entries' => $entries]) ?? ['indexed' => 0, 'skipped' => 0];
    }

    /**
     * Create a new vector index.
     * POST /v1/sem/:token/indexes
     */
    public function createIndex(
        string $namespace,
        int $dimensions = 1536,
        string $model = 'text-embedding-3-small',
        string $metric = 'cosine',
        bool $hybridEnabled = false,
    ): void {
        $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/indexes', [
            'namespace' => $namespace, 'dimensions' => $dimensions,
            'model' => $model, 'metric' => $metric, 'hybrid_enabled' => $hybridEnabled,
        ]);
    }

    /**
     * Delete an index.
     * DELETE /v1/sem/:token/indexes/:namespace
     */
    public function deleteIndex(string $namespace): void
    {
        $this->apiRequest('DELETE', rtrim($this->vectorUrl ?? '', '/') . '/indexes/' . urlencode($namespace));
    }

    /**
     * Attach JSONB metadata to a semantic cache entry.
     * POST /v1/sem/:token/metadata
     *
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(string $entryId, array $metadata): void
    {
        $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/metadata',
            ['entry_id' => $entryId, 'metadata' => $metadata]);
    }

    /**
     * Semantic search with metadata filter.
     * POST /v1/sem/:token/search/filtered
     *
     * @param callable(string): float[] $embedFn
     * @param array<string, mixed>      $filter
     * @return array<string, mixed>|null
     */
    public function filteredSearch(
        string $prompt,
        callable $embedFn,
        string $namespace = 'cachly:sem',
        float $threshold = 0.85,
        array $filter = [],
        int $limit = 5,
    ): ?array {
        $embedding = $embedFn(self::normalizePromptText($prompt));
        return $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/search/filtered', [
            'prompt' => $prompt, 'embedding' => $embedding,
            'namespace' => $namespace, 'threshold' => $threshold,
            'filter' => $filter, 'limit' => $limit,
        ]);
    }

    /**
     * Configure content-safety guardrails.
     * POST /v1/sem/:token/guardrails
     */
    public function setGuardrail(
        string $namespace = 'cachly:sem',
        string $piiAction = 'block',
        string $toxicAction = 'flag',
        float $toxicThreshold = 0.8,
    ): void {
        $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/guardrails', [
            'namespace' => $namespace, 'pii_action' => $piiAction,
            'toxic_action' => $toxicAction, 'toxic_threshold' => $toxicThreshold,
        ]);
    }

    /**
     * Remove guardrail configuration.
     * DELETE /v1/sem/:token/guardrails/:namespace
     */
    public function deleteGuardrail(string $namespace): void
    {
        $this->apiRequest('DELETE', rtrim($this->vectorUrl ?? '', '/') . '/guardrails/' . urlencode($namespace));
    }

    /**
     * Check text against configured guardrails.
     * POST /v1/sem/:token/guardrails/check
     *
     * @return array{safe: bool, violations: array<int, array{type: string, pattern: string, action: string}>}
     */
    public function checkGuardrail(string $text, string $namespace = 'cachly:sem'): array
    {
        return $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/guardrails/check',
            ['text' => $text, 'namespace' => $namespace])
            ?? ['safe' => true, 'violations' => []];
    }

    /**
     * Re-warm semantic cache from existing entries.
     * POST /v1/sem/:token/warmup/snapshot
     *
     * @return array{warmed: int, duration_ms: int}
     */
    public function snapshotWarmup(string $namespace = 'cachly:sem', int $limit = 100): array
    {
        return $this->apiRequest('POST', rtrim($this->vectorUrl ?? '', '/') . '/warmup/snapshot',
            ['namespace' => $namespace, 'limit' => $limit])
            ?? ['warmed' => 0, 'duration_ms' => 0];
    }

    // ── Private HTTP helper ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function apiRequest(string $method, string $url, ?array $body = null): ?array
    {
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => "Content-Type: application/json\r\nAccept: application/json",
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $ctx  = stream_context_create($opts);
        $raw  = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') { return null; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

// ── New DTOs (SDK Feature Gap) ────────────────────────────────────────────────

/** Cache statistics returned by SemanticCache::stats(). */
final class CacheStats
{
    public function __construct(
        public readonly int   $hits,
        public readonly int   $misses,
        public readonly float $hitRate,
        public readonly int   $total,
        /** @var array<int, array<string, mixed>> */
        public readonly array $namespaces = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            hits:       (int)   ($data['hits']       ?? 0),
            misses:     (int)   ($data['misses']      ?? 0),
            hitRate:    (float) ($data['hit_rate']    ?? 0.0),
            total:      (int)   ($data['total']       ?? 0),
            namespaces: (array) ($data['namespaces']  ?? []),
        );
    }
}

/** Result of SemanticCache::batchIndex(). */
final class BatchIndexResult
{
    public function __construct(
        public readonly int $indexed,
        public readonly int $skipped,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int)($data['indexed'] ?? 0), (int)($data['skipped'] ?? 0));
    }
}

/** Tags associated with a cache key. */
final class TagsResult
{
    public function __construct(
        public readonly string $key,
        /** @var string[] */
        public readonly array  $tags,
        public readonly bool   $ok = true,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $fallbackKey = ''): self
    {
        return new self(
            key:  (string) ($data['key'] ?? $fallbackKey),
            tags: (array)  ($data['tags'] ?? []),
            ok:   (bool)   ($data['ok']   ?? true),
        );
    }
}

/** Result of CachlyClient::invalidateTag(). */
final class InvalidateTagResult
{
    public function __construct(
        public readonly string $tag,
        public readonly int    $keysDeleted,
        /** @var string[] */
        public readonly array  $keys,
        public readonly int    $durationMs,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $fallbackTag = ''): self
    {
        return new self(
            tag:        (string) ($data['tag']          ?? $fallbackTag),
            keysDeleted:(int)    ($data['keys_deleted'] ?? 0),
            keys:       (array)  ($data['keys']         ?? []),
            durationMs: (int)    ($data['duration_ms']  ?? 0),
        );
    }
}

/** One entry in the SWR registry. */
final class SwrEntry
{
    public function __construct(
        public readonly string  $key,
        public readonly ?string $fetcherHint,
        public readonly ?string $staleFor,
        public readonly ?string $refreshAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key:         (string)       ($data['key']          ?? ''),
            fetcherHint: ($data['fetcher_hint'] ?? null) ?: null,
            staleFor:    ($data['stale_for']    ?? null) ?: null,
            refreshAt:   ($data['refresh_at']   ?? null) ?: null,
        );
    }
}

/** Result of CachlyClient::swrCheck(). */
final class SwrCheckResult
{
    public function __construct(
        /** @var SwrEntry[] */
        public readonly array  $staleKeys,
        public readonly int    $count,
        public readonly string $checkedAt,
    ) {}
}

/** Result of CachlyClient::bulkWarmup(). */
final class BulkWarmupResult
{
    public function __construct(
        public readonly int $warmed,
        public readonly int $skipped,
        public readonly int $durationMs,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int)($data['warmed'] ?? 0), (int)($data['skipped'] ?? 0), (int)($data['duration_ms'] ?? 0));
    }
}

/** Result of SemanticCache::snapshotWarmup(). */
final class SnapshotWarmupResult
{
    public function __construct(
        public readonly int $warmed,
        public readonly int $durationMs,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int)($data['warmed'] ?? 0), (int)($data['duration_ms'] ?? 0));
    }
}

/** LLM proxy statistics. */
final class LlmProxyStatsResult
{
    public function __construct(
        public readonly int   $totalRequests,
        public readonly int   $cacheHits,
        public readonly int   $cacheMisses,
        public readonly float $estimatedSavedUsd,
        public readonly int   $avgLatencyMsCached,
        public readonly int   $avgLatencyMsUncached,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            totalRequests:       (int)   ($data['total_requests']          ?? 0),
            cacheHits:           (int)   ($data['cache_hits']              ?? 0),
            cacheMisses:         (int)   ($data['cache_misses']            ?? 0),
            estimatedSavedUsd:   (float) ($data['estimated_saved_usd']     ?? 0.0),
            avgLatencyMsCached:  (int)   ($data['avg_latency_ms_cached']   ?? 0),
            avgLatencyMsUncached:(int)   ($data['avg_latency_ms_uncached'] ?? 0),
        );
    }
}

/** A Pub/Sub message. */
final class PubSubMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $message,
        public readonly string $at,
    ) {}
}

/** A workflow checkpoint. */
final class WorkflowCheckpoint
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $runId,
        public readonly int     $stepIndex,
        public readonly string  $stepName,
        public readonly string  $agentName,
        public readonly string  $status,
        public readonly ?string $state      = null,
        public readonly ?string $output     = null,
        public readonly ?int    $durationMs = null,
        public readonly ?string $createdAt  = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:        (string)  ($data['id']          ?? ''),
            runId:     (string)  ($data['run_id']      ?? ''),
            stepIndex: (int)     ($data['step_index']  ?? 0),
            stepName:  (string)  ($data['step_name']   ?? ''),
            agentName: (string)  ($data['agent_name']  ?? ''),
            status:    (string)  ($data['status']      ?? ''),
            state:     isset($data['state'])      ? (string)$data['state']      : null,
            output:    isset($data['output'])     ? (string)$data['output']     : null,
            durationMs:isset($data['duration_ms'])? (int)$data['duration_ms']   : null,
            createdAt: isset($data['created_at']) ? (string)$data['created_at'] : null,
        );
    }
}

/** Summary of a workflow run. */
final class WorkflowRun
{
    public function __construct(
        public readonly string $runId,
        public readonly int    $steps,
        public readonly string $latestStatus,
        /** @var WorkflowCheckpoint[] */
        public readonly array  $checkpoints = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cps = array_map(
            [WorkflowCheckpoint::class, 'fromArray'],
            (array)($data['checkpoints'] ?? [])
        );
        return new self(
            runId:        (string) ($data['run_id']        ?? ''),
            steps:        (int)    ($data['steps']         ?? count($cps)),
            latestStatus: (string) ($data['latest_status'] ?? ''),
            checkpoints:  $cps,
        );
    }
}
 *
 * Call release() in a finally block to free the lock early.
 * The lock auto-expires after the configured TTL even if release() is never called.
 */
final class LockHandle
{
    private const RELEASE_SCRIPT = <<<LUA
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        else
            return 0
        end
    LUA;

    public function __construct(
        private readonly PredisClient $redis,
        private readonly string $lockKey,
        private readonly string $token,
    ) {}

    /** Unique fencing token for this lock acquisition. */
    public function getToken(): string { return $this->token; }

    /** Release the lock atomically. No-op if the lock has already expired. */
    public function release(): void
    {
        $this->redis->eval(self::RELEASE_SCRIPT, 1, $this->lockKey, $this->token);
    }
}

/**
 * CachlyClient – official PHP client for cachly.dev.
 *
 * Typed wrapper around Predis with optional semantic AI caching.
 *
 * @example
 * ```php
 * $cache = new CachlyClient(
 *     url: 'redis://:password@host:port',
 *     vectorUrl: getenv('CACHLY_VECTOR_URL'),
 * );
 * $result = $cache->semantic()->getOrSet(
 *     prompt: $question,
 *     fn:      fn() => $openAI->ask($question),
 *     embedFn: fn(string $text) => $openAI->embed($text),
 * );
 * ```
 */
final class CachlyClient
{
    private readonly PredisClient $redis;
    private ?SemanticCache $semanticCache = null;

    /**
     * @param string               $url         Redis connection URL: redis://:password@host:port
     * @param string|null          $vectorUrl   Optional pgvector API URL
     * @param array<string, mixed> $options     Additional Predis client options
     * @param string|null          $batchUrl    Optional KV Batch/Tags/SWR API URL
     * @param string|null          $pubsubUrl   Optional Pub/Sub API URL
     * @param string|null          $workflowUrl Optional Workflow API URL
     * @param string|null          $llmProxyUrl Optional LLM Proxy stats URL
     * @param string|null          $edgeApiUrl  Optional Edge Cache management API URL (https://api.cachly.dev/v1/edge/{token})
     * @param string|null          $edgeUrl     Optional Edge Worker read URL (https://edge.cachly.dev/v1/sem/{token})
     */
    public function __construct(
        string $url,
        private readonly ?string $vectorUrl   = null,
        array  $options                       = [],
        private readonly ?string $batchUrl    = null,
        private readonly ?string $pubsubUrl   = null,
        private readonly ?string $workflowUrl = null,
        private readonly ?string $llmProxyUrl = null,
        private readonly ?string $edgeApiUrl  = null,
        private readonly ?string $edgeUrl     = null,
    ) {
        $this->redis = new PredisClient($url, $options);
    }

    // ── Basic operations ──────────────────────────────────────────────────────

    /** Retrieve a value by key. Returns null when not found. */
    public function get(string $key): mixed
    {
        $raw = $this->redis->get($key);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return $decoded ?? $raw;
    }

    /** Store a value. Pass $ttl (seconds) for automatic expiry. */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
        if ($ttl !== null) {
            $this->redis->setex($key, $ttl, $serialized);
        } else {
            $this->redis->set($key, $serialized);
        }
    }

    /** Delete one or more keys. Returns the number of keys deleted. */
    public function del(string ...$keys): int
    {
        return (int) $this->redis->del($keys);
    }

    /** Check whether a key exists. */
    public function exists(string $key): bool
    {
        return (int) $this->redis->exists($key) > 0;
    }

    /** Update TTL for an existing key. */
    public function expire(string $key, int $seconds): void
    {
        $this->redis->expire($key, $seconds);
    }

    /**
     * Return the remaining TTL for a key in seconds.
     * Returns -1 if the key has no expiry, -2 if the key does not exist.
     */
    public function ttl(string $key): int
    {
        return (int) $this->redis->ttl($key);
    }

    /**
     * Check connectivity to the cache server.
     * Returns true if the server responds to PING.
     */
    public function ping(): bool
    {
        try {
            $response = $this->redis->ping();
            // Predis returns a Status object or string "PONG"
            return strtoupper((string) $response) === 'PONG'
                || (is_object($response) && method_exists($response, 'getPayload')
                    && strtoupper($response->getPayload()) === 'PONG');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Atomic counter increment. Returns the new value. */
    public function incr(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    /** Get-or-set: return cached value or call $fn, cache and return result. */
    public function getOrSet(string $key, callable $fn, ?int $ttl = null): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $fn();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // ── Bulk operations ───────────────────────────────────────────────────────

    /**
     * Set multiple key-value pairs in a single pipeline round-trip.
     * Supports per-key TTL – unlike native MSET which has no expiry option.
     *
     * Each item is an array with keys: 'key' (string), 'value' (mixed), 'ttl' (int|null).
     *
     * @param  array<array{key:string,value:mixed,ttl?:int|null}> $items
     *
     * @example
     * ```php
     * $cache->mset([
     *     ['key' => 'user:1', 'value' => ['name' => 'Alice'], 'ttl' => 300],
     *     ['key' => 'user:2', 'value' => ['name' => 'Bob']],
     * ]);
     * ```
     */
    public function mset(array $items): void
    {
        if (empty($items)) {
            return;
        }
        $pipeline = $this->redis->pipeline();
        foreach ($items as $item) {
            $serialized = is_string($item['value'])
                ? $item['value']
                : json_encode($item['value'], JSON_THROW_ON_ERROR);
            $ttl = $item['ttl'] ?? null;
            if ($ttl !== null) {
                $pipeline->setex($item['key'], (int) $ttl, $serialized);
            } else {
                $pipeline->set($item['key'], $serialized);
            }
        }
        $pipeline->execute();
    }

    /**
     * Retrieve multiple keys in one round-trip (native MGET).
     * Returns an array in the same order as $keys; missing keys are null.
     *
     * @param  string[] $keys
     * @return array<mixed|null>
     *
     * @example
     * ```php
     * [$alice, $bob] = $cache->mget(['user:1', 'user:2']);
     * ```
     */
    public function mget(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $raws = $this->redis->mget($keys);
        $result = [];
        foreach ($raws as $raw) {
            if ($raw === null || $raw === false) {
                $result[] = null;
                continue;
            }
            $decoded = json_decode($raw, true);
            $result[] = $decoded ?? $raw;
        }
        return $result;
    }

    // ── Distributed lock ──────────────────────────────────────────────────────

    /**
     * Acquire a distributed lock using Redis SET NX PX (Redlock-lite pattern).
     *
     * Returns a LockHandle on success, or null when all attempts are exhausted.
     * The lock auto-expires after $ttlMs to prevent deadlocks.
     *
     * @param  string $key          Resource identifier.
     * @param  int    $ttlMs        Safety TTL in milliseconds.
     * @param  int    $retries      Max acquire attempts. Default: 3.
     * @param  int    $retryDelayMs Milliseconds between retries. Default: 50.
     *
     * @example
     * ```php
     * $lock = $cache->lock('job:invoice:42', 5000, 5);
     * if ($lock === null) throw new \RuntimeException('Busy');
     * try {
     *     processInvoice();
     * } finally {
     *     $lock->release();
     * }
     * ```
     */
    public function lock(string $key, int $ttlMs, int $retries = 3, int $retryDelayMs = 50): ?LockHandle
    {
        $lockKey = "cachly:lock:{$key}";
        $token   = bin2hex(random_bytes(16));

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $result = $this->redis->set($lockKey, $token, 'PX', $ttlMs, 'NX');
            if ($result === 'OK' || $result === true) {
                return new LockHandle($this->redis, $lockKey, $token);
            }
            if ($attempt < $retries) {
                usleep($retryDelayMs * 1000);
            }
        }
        return null;
    }

    // ── Batch API ─────────────────────────────────────────────────────────────

    /**
     * Execute multiple cache operations in a **single round-trip**.
     *
     * When `$batchUrl` is configured (constructor param), all ops are sent via
     * `POST {batchUrl}/batch` (one HTTP request).
     * Otherwise they fall back to a Predis pipeline.
     *
     * Each op is an associative array with keys:
     *   - `op`    – "get" | "set" | "del" | "exists" | "ttl" (required)
     *   - `key`   – cache key (required)
     *   - `value` – value to store (required for "set")
     *   - `ttl`   – TTL in seconds (optional, "set" only)
     *
     * Returns an array of results in the same order:
     *   - "get"    → `string|null`
     *   - "set"    → `bool`
     *   - "del"    → `bool`
     *   - "exists" → `bool`
     *   - "ttl"    → `int` (seconds; -1 = no expiry, -2 = not found)
     *
     * @param  array<array{op: string, key: string, value?: string, ttl?: int}> $ops
     * @return array<int, string|bool|int|null>
     *
     * @example
     * ```php
     * [$user, $config, $ok] = $cache->batch([
     *     ['op' => 'get', 'key' => 'user:1'],
     *     ['op' => 'get', 'key' => 'config:app'],
     *     ['op' => 'set', 'key' => 'visits', 'value' => (string) time(), 'ttl' => 86400],
     * ]);
     * ```
     */
    public function batch(array $ops): array
    {
        if (empty($ops)) {
            return [];
        }
        $baseUrl = $this->batchUrl !== null ? rtrim($this->batchUrl, '/') : null;
        if ($baseUrl !== null) {
            return $this->batchViaHttp($ops, $baseUrl);
        }
        return $this->batchViaPipeline($ops);
    }

    /** @param array<array<string,mixed>> $ops */
    private function batchViaHttp(array $ops, string $baseUrl): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode(['ops' => $ops], JSON_THROW_ON_ERROR),
                'timeout' => 5,
            ],
        ]);
        $raw  = file_get_contents("{$baseUrl}/batch", false, $ctx);
        $data = $raw !== false ? json_decode($raw, true) : [];
        $resultsRaw = $data['results'] ?? [];
        $out = [];
        foreach ($ops as $i => $op) {
            $r = $resultsRaw[$i] ?? [];
            $out[] = match ($op['op']) {
                'get'    => $r['value'] ?? null,
                'exists' => (bool) ($r['exists'] ?? false),
                'ttl'    => (int)  ($r['ttl_seconds'] ?? -2),
                'del'    => ((int) ($r['deleted'] ?? 0)) > 0,
                default  => (bool) ($r['ok'] ?? false), // set
            };
        }
        return $out;
    }

    /** @param array<array<string,mixed>> $ops */
    private function batchViaPipeline(array $ops): array
    {
        $responses = $this->redis->pipeline(function ($pipe) use ($ops): void {
            foreach ($ops as $op) {
                match ($op['op']) {
                    'get'    => $pipe->get($op['key']),
                    'set'    => isset($op['ttl'])
                        ? $pipe->setex($op['key'], (int) $op['ttl'], (string) ($op['value'] ?? ''))
                        : $pipe->set($op['key'], (string) ($op['value'] ?? '')),
                    'del'    => $pipe->del([$op['key']]),
                    'exists' => $pipe->exists($op['key']),
                    'ttl'    => $pipe->ttl($op['key']),
                    default  => null,
                };
            }
        });
        $out = [];
        foreach ($ops as $i => $op) {
            $val = $responses[$i];
            $out[] = match ($op['op']) {
                'get'    => $val !== null ? (is_string($val) ? ($val) : null) : null,
                'set'    => $val === 'OK' || $val === true,
                'del'    => ((int) $val) > 0,
                'exists' => ((int) $val) > 0,
                'ttl'    => (int) $val,
                default  => $val,
            };
        }
        return $out;
    }

    // ── Streaming cache ───────────────────────────────────────────────────────

    /**
     * Cache a streaming response chunk-by-chunk via Redis RPUSH.
     * Replay with {@see streamGet()}.
     *
     * @param string           $key        Cache key.
     * @param iterable<string> $chunks     Iterable of string chunks.
     * @param int|null         $ttl        Expiry in seconds; null = no expiry.
     *
     * @example
     * ```php
     * $cache->streamSet('chat:42', $tokenChunks, 3600);
     * ```
     */
    public function streamSet(string $key, iterable $chunks, ?int $ttl = null): void
    {
        $listKey = "cachly:stream:{$key}";
        $this->redis->del($listKey);
        foreach ($chunks as $chunk) {
            $this->redis->rpush($listKey, [$chunk]);
        }
        if ($ttl !== null) {
            $this->redis->expire($listKey, $ttl);
        }
    }

    /**
     * Retrieve a cached stream as a Generator<string>.
     * Returns null on cache miss (key absent or empty list).
     *
     * @param string $key           Cache key.
     * @param int    $replayDelayMs Artificial delay between chunks (ms) for SSE pacing.
     * @return \Generator<string>|null
     *
     * @example
     * ```php
     * $cached = $cache->streamGet('chat:42');
     * if ($cached !== null) {
     *     foreach ($cached as $chunk) echo $chunk;
     * }
     * ```
     */
    public function streamGet(string $key, int $replayDelayMs = 0): ?\Generator
    {
        $listKey = "cachly:stream:{$key}";
        $length  = (int) $this->redis->llen($listKey);
        if ($length === 0) {
            return null;
        }

        return (static function (PredisClient $redis, string $lk, int $len, int $delay): \Generator {
            for ($i = 0; $i < $len; $i++) {
                $raw = $redis->lindex($lk, $i);
                if ($raw === null) {
                    return;
                }
                yield $raw;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        })($this->redis, $listKey, $length, $replayDelayMs);
    }

    // ── Semantic cache ────────────────────────────────────────────────────────

    /** Return the SemanticCache helper (lazily initialised). */
    public function semantic(): SemanticCache
    {
        return $this->semanticCache ??= new SemanticCache($this->redis, $this->vectorUrl, $this->edgeUrl);
    }

    // ── Advanced ─────────────────────────────────────────────────────────────

    /** Direct access to the underlying Predis client for advanced operations. */
    public function raw(): PredisClient
    {
        return $this->redis;
    }

    /** Flush the entire database. Use with caution. */
    public function flushDb(): void
    {
        $this->redis->flushdb();
    }

    // ── Tag-based invalidation ────────────────────────────────────────────────

    /**
     * Associate a cache key with tags. POST /v1/cache/:token/tags
     *
     * @param string[] $tags
     */
    public function setTags(string $key, array $tags): TagsResult
    {
        $resp = $this->batchApiRequest('POST', rtrim($this->batchUrl ?? '', '/') . '/tags',
            ['key' => $key, 'tags' => $tags]);
        return TagsResult::fromArray($resp ?? [], $key);
    }

    /**
     * Delete all keys associated with a tag. POST /v1/cache/:token/invalidate
     */
    public function invalidateTag(string $tag): InvalidateTagResult
    {
        $resp = $this->batchApiRequest('POST', rtrim($this->batchUrl ?? '', '/') . '/invalidate',
            ['tag' => $tag]);
        return InvalidateTagResult::fromArray($resp ?? [], $tag);
    }

    /**
     * Get tags for a key. GET /v1/cache/:token/tags/:key
     */
    public function getTags(string $key): TagsResult
    {
        $resp = $this->batchApiRequest('GET',
            rtrim($this->batchUrl ?? '', '/') . '/tags/' . urlencode($key));
        return TagsResult::fromArray($resp ?? [], $key);
    }

    /**
     * Remove all tag associations for a key. DELETE /v1/cache/:token/tags/:key
     */
    public function deleteTags(string $key): void
    {
        $this->batchApiRequest('DELETE',
            rtrim($this->batchUrl ?? '', '/') . '/tags/' . urlencode($key));
    }

    // ── Stale-While-Revalidate (SWR) ─────────────────────────────────────────

    /**
     * Register a key for SWR. POST /v1/cache/:token/swr/register
     */
    public function swrRegister(
        string $key,
        int $ttlSeconds,
        int $staleWindowSeconds,
        ?string $fetcherHint = null,
    ): void {
        $body = ['key' => $key, 'ttl_seconds' => $ttlSeconds, 'stale_window_seconds' => $staleWindowSeconds];
        if ($fetcherHint !== null) { $body['fetcher_hint'] = $fetcherHint; }
        $this->batchApiRequest('POST', rtrim($this->batchUrl ?? '', '/') . '/swr/register', $body);
    }

    /**
     * Query stale keys. POST /v1/cache/:token/swr/check
     */
    public function swrCheck(): SwrCheckResult
    {
        $resp      = $this->batchApiRequest('POST', rtrim($this->batchUrl ?? '', '/') . '/swr/check', []) ?? [];
        $rawKeys   = (array)($resp['stale_keys'] ?? []);
        $staleKeys = array_map([SwrEntry::class, 'fromArray'], $rawKeys);
        return new SwrCheckResult(
            staleKeys: $staleKeys,
            count:     (int)($resp['count'] ?? count($staleKeys)),
            checkedAt: (string)($resp['checked_at'] ?? ''),
        );
    }

    /**
     * Remove a key from the SWR registry. DELETE /v1/cache/:token/swr/:key
     */
    public function swrRemove(string $key): void
    {
        $this->batchApiRequest('DELETE', rtrim($this->batchUrl ?? '', '/') . '/swr/' . urlencode($key));
    }

    // ── Bulk Warmup ───────────────────────────────────────────────────────────

    /**
     * Bulk-warm the KV cache. POST /v1/cache/:token/warm
     *
     * @param array<int, array{key: string, value: string, ttl?: int}> $entries
     */
    public function bulkWarmup(array $entries): BulkWarmupResult
    {
        $resp = $this->batchApiRequest('POST', rtrim($this->batchUrl ?? '', '/') . '/warm',
            ['entries' => $entries]) ?? [];
        return BulkWarmupResult::fromArray($resp);
    }

    // ── LLM Proxy Stats ───────────────────────────────────────────────────────

    /**
     * Return LLM proxy statistics. GET /v1/llm-proxy/:token/stats
     */
    public function llmProxyStats(): LlmProxyStatsResult
    {
        $resp = $this->batchApiRequest('GET', rtrim($this->llmProxyUrl ?? '', '/') . '/stats') ?? [];
        return LlmProxyStatsResult::fromArray($resp);
    }

    // ── Sub-client accessors ──────────────────────────────────────────────────

    /**
     * Return a PubSubClient. Requires $pubsubUrl in the constructor.
     * Returns null when not configured.
     */
    public function pubSub(): ?PubSubClient
    {
        return $this->pubsubUrl !== null ? new PubSubClient($this->pubsubUrl) : null;
    }

    /**
     * Return a WorkflowClient. Requires $workflowUrl in the constructor.
     * Returns null when not configured.
     */
    public function workflow(): ?WorkflowClient
    {
        return $this->workflowUrl !== null ? new WorkflowClient($this->workflowUrl) : null;
    }

    /**
     * Return an EdgeCacheClient. Requires $edgeApiUrl in the constructor.
     * Returns null when not configured.
     */
    public function edge(): ?EdgeCacheClient
    {
        return $this->edgeApiUrl !== null ? new EdgeCacheClient($this->edgeApiUrl) : null;
    }

    // ── Private HTTP helper ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function batchApiRequest(string $method, string $url, ?array $body = null): ?array
    {
        if (!$this->batchUrl && !$this->llmProxyUrl) {
            throw new \LogicException('batchUrl / llmProxyUrl is required for this method');
        }
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => "Content-Type: application/json\r\nAccept: application/json",
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $ctx  = stream_context_create($opts);
        $raw  = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') { return null; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

// ── PubSubClient ──────────────────────────────────────────────────────────────

/**
 * Pub/Sub client for cachly.dev.
 * Obtain via CachlyClient::pubSub() (requires $pubsubUrl in constructor).
 */
final class PubSubClient
{
    public function __construct(private readonly string $pubsubUrl) {}

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function apiRequest(string $method, string $url, ?array $body = null): ?array
    {
        $opts = ['http' => ['method' => $method,
            'header' => "Content-Type: application/json\r\nAccept: application/json",
            'ignore_errors' => true]];
        if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR); }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false || $raw === '') { return null; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Publish a message. POST /v1/pubsub/:token/publish
     */
    public function publish(string $channel, string $message): void
    {
        $this->apiRequest('POST', rtrim($this->pubsubUrl, '/') . '/publish',
            ['channel' => $channel, 'message' => $message]);
    }

    /**
     * Subscribe to channels via SSE – yields PubSubMessage items.
     * POST /v1/pubsub/:token/subscribe
     *
     * @param string[] $channels
     * @return \Generator<PubSubMessage>
     */
    public function subscribe(array $channels): \Generator
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAccept: text/event-stream",
            'content' => json_encode(['channels' => $channels], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]);
        $stream = @fopen(rtrim($this->pubsubUrl, '/') . '/subscribe', 'r', false, $ctx);
        if ($stream === false) { return; }
        try {
            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) { break; }
                $line = rtrim($line);
                if (!str_starts_with($line, 'data:')) { continue; }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '{}') { continue; }
                $decoded = json_decode($data, true);
                if (!is_array($decoded)) { continue; }
                yield new PubSubMessage(
                    channel: (string)($decoded['channel'] ?? ''),
                    message: (string)($decoded['message'] ?? ''),
                    at:      (string)($decoded['at']      ?? ''),
                );
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * List active channels. GET /v1/pubsub/:token/channels
     * @return array<string, mixed>
     */
    public function channels(): array
    {
        return $this->apiRequest('GET', rtrim($this->pubsubUrl, '/') . '/channels') ?? [];
    }

    /**
     * Pub/Sub statistics. GET /v1/pubsub/:token/stats
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->apiRequest('GET', rtrim($this->pubsubUrl, '/') . '/stats') ?? [];
    }
}

// ── WorkflowClient ────────────────────────────────────────────────────────────

/**
 * Agent-workflow checkpoint client for cachly.dev.
 * Obtain via CachlyClient::workflow() (requires $workflowUrl in constructor).
 */
final class WorkflowClient
{
    public function __construct(private readonly string $workflowUrl) {}

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function apiRequest(string $method, string $url, ?array $body = null): ?array
    {
        $opts = ['http' => ['method' => $method,
            'header' => "Content-Type: application/json\r\nAccept: application/json",
            'ignore_errors' => true]];
        if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR); }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false || $raw === '') { return null; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Save a workflow checkpoint. POST /v1/workflow/:token/checkpoints
     */
    public function saveCheckpoint(
        string  $runId,
        int     $stepIndex,
        string  $stepName,
        string  $agentName,
        string  $status,
        ?string $state      = null,
        ?string $output     = null,
        ?int    $durationMs = null,
    ): WorkflowCheckpoint {
        $body = ['run_id' => $runId, 'step_index' => $stepIndex, 'step_name' => $stepName,
                 'agent_name' => $agentName, 'status' => $status];
        if ($state      !== null) { $body['state']       = $state; }
        if ($output     !== null) { $body['output']      = $output; }
        if ($durationMs !== null) { $body['duration_ms'] = $durationMs; }
        $resp = $this->apiRequest('POST', rtrim($this->workflowUrl, '/') . '/checkpoints', $body);
        return WorkflowCheckpoint::fromArray($resp ?? []);
    }

    /**
     * List all workflow runs. GET /v1/workflow/:token/runs
     *
     * @return WorkflowRun[]
     */
    public function listRuns(): array
    {
        $resp = $this->apiRequest('GET', rtrim($this->workflowUrl, '/') . '/runs');
        return array_map([WorkflowRun::class, 'fromArray'], (array)($resp['runs'] ?? []));
    }

    /**
     * Get checkpoints for a run. GET /v1/workflow/:token/runs/:runId
     */
    public function getRun(string $runId): WorkflowRun
    {
        $resp = $this->apiRequest('GET', rtrim($this->workflowUrl, '/') . '/runs/' . urlencode($runId));
        return WorkflowRun::fromArray($resp ?? []);
    }

    /**
     * Get the latest checkpoint. GET /v1/workflow/:token/runs/:runId/latest
     */
    public function latestCheckpoint(string $runId): WorkflowCheckpoint
    {
        $resp = $this->apiRequest('GET',
            rtrim($this->workflowUrl, '/') . '/runs/' . urlencode($runId) . '/latest');
        return WorkflowCheckpoint::fromArray($resp ?? []);
    }

    /**
     * Delete a workflow run. DELETE /v1/workflow/:token/runs/:runId
     */
    public function deleteRun(string $runId): void
    {
        $this->apiRequest('DELETE',
            rtrim($this->workflowUrl, '/') . '/runs/' . urlencode($runId));
    }
}

// ── EdgeCacheClient ───────────────────────────────────────────────────────────

/** Edge Cache configuration returned by EdgeCacheClient::getConfig(). */
final class EdgeCacheConfig
{
    public readonly string $id;
    public readonly string $instanceId;
    public readonly bool   $enabled;
    /** Cache TTL in seconds (0–86400). */
    public readonly int    $edgeTTL;
    public readonly string $workerUrl;
    public readonly string $cloudflareZoneId;
    public readonly bool   $purgeOnWrite;
    public readonly bool   $cacheSearchResults;
    public readonly int    $totalHits;
    public readonly int    $totalMisses;
    /** Hit rate percentage: totalHits / (totalHits + totalMisses) * 100. */
    public readonly float  $hitRate;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->id                 = (string)  ($data['id']                   ?? '');
        $this->instanceId         = (string)  ($data['instance_id']          ?? '');
        $this->enabled            = (bool)    ($data['enabled']              ?? false);
        $this->edgeTTL            = (int)     ($data['edge_ttl']             ?? 60);
        $this->workerUrl          = (string)  ($data['worker_url']           ?? 'https://edge.cachly.dev');
        $this->cloudflareZoneId   = (string)  ($data['cloudflare_zone_id']   ?? '');
        $this->purgeOnWrite       = (bool)    ($data['purge_on_write']       ?? true);
        $this->cacheSearchResults = (bool)    ($data['cache_search_results'] ?? true);
        $this->totalHits          = (int)     ($data['total_hits']           ?? 0);
        $this->totalMisses        = (int)     ($data['total_misses']         ?? 0);
        $this->hitRate            = (float)   ($data['hit_rate']             ?? 0.0);
    }
}

/**
 * Options for updating edge cache configuration.
 * Only non-null fields are sent to the API.
 */
final class EdgeCacheConfigUpdate
{
    public function __construct(
        public readonly ?bool   $enabled            = null,
        public readonly ?int    $edgeTTL            = null,
        public readonly ?string $workerUrl          = null,
        public readonly ?string $cloudflareZoneId   = null,
        public readonly ?bool   $purgeOnWrite       = null,
        public readonly ?bool   $cacheSearchResults = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = [];
        if ($this->enabled            !== null) { $body['enabled']              = $this->enabled; }
        if ($this->edgeTTL            !== null) { $body['edge_ttl']             = $this->edgeTTL; }
        if ($this->workerUrl          !== null) { $body['worker_url']           = $this->workerUrl; }
        if ($this->cloudflareZoneId   !== null) { $body['cloudflare_zone_id']   = $this->cloudflareZoneId; }
        if ($this->purgeOnWrite       !== null) { $body['purge_on_write']       = $this->purgeOnWrite; }
        if ($this->cacheSearchResults !== null) { $body['cache_search_results'] = $this->cacheSearchResults; }
        return $body;
    }
}

/** Options for purging edge cache entries. */
final class EdgePurgeOptions
{
    /**
     * @param string[]|null $namespaces Namespace patterns to purge (e.g. ['cachly:sem:qa']). null = purge all.
     * @param string[]|null $urls       Exact Cloudflare Worker URLs to purge.
     */
    public function __construct(
        public readonly ?array $namespaces = null,
        public readonly ?array $urls       = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = [];
        if ($this->namespaces !== null) { $body['namespaces'] = $this->namespaces; }
        if ($this->urls       !== null) { $body['urls']       = $this->urls; }
        return $body;
    }
}

/** Hit/miss statistics returned by EdgeCacheClient::stats(). */
final class EdgeCacheStats
{
    public readonly bool   $enabled;
    public readonly string $workerUrl;
    public readonly int    $edgeTTL;
    public readonly int    $totalHits;
    public readonly int    $totalMisses;
    public readonly float  $hitRate;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->enabled     = (bool)  ($data['enabled']      ?? false);
        $this->workerUrl   = (string)($data['worker_url']   ?? 'https://edge.cachly.dev');
        $this->edgeTTL     = (int)   ($data['edge_ttl']     ?? 60);
        $this->totalHits   = (int)   ($data['total_hits']   ?? 0);
        $this->totalMisses = (int)   ($data['total_misses'] ?? 0);
        $this->hitRate     = (float) ($data['hit_rate']     ?? 0.0);
    }
}

/**
 * Edge Cache management client for cachly.dev (Feature #5).
 *
 * Manages the Cloudflare Edge Cache for a cachly instance — configure TTL,
 * purge stale entries by namespace, and monitor hit/miss statistics.
 *
 * Obtained via CachlyClient::edge() (requires $edgeApiUrl in constructor).
 *
 * @example
 * ```php
 * $cache = new CachlyClient(
 *     url:        getenv('CACHLY_URL'),
 *     vectorUrl:  getenv('CACHLY_VECTOR_URL'),
 *     edgeApiUrl: getenv('CACHLY_EDGE_API_URL'),  // https://api.cachly.dev/v1/edge/{token}
 *     edgeUrl:    getenv('CACHLY_EDGE_URL'),       // https://edge.cachly.dev/v1/sem/{token}
 * );
 *
 * // Enable with 120s TTL
 * $cache->edge()->setConfig(new EdgeCacheConfigUpdate(enabled: true, edgeTTL: 120));
 *
 * // Purge a namespace after bulk write
 * $cache->edge()->purge(new EdgePurgeOptions(namespaces: ['cachly:sem:qa']));
 *
 * // Check hit rate
 * $stats = $cache->edge()->stats();
 * echo "Edge hit rate: {$stats->hitRate}%\n";
 * ```
 */
final class EdgeCacheClient
{
    public function __construct(private readonly string $edgeApiUrl) {}

    /** @param array<string, mixed>|null $body */
    private function apiRequest(string $method, string $url, ?array $body = null): array
    {
        $opts = ['http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json",
            'ignore_errors' => true,
        ]];
        if ($body !== null) {
            $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get current edge cache configuration.
     * Returns defaults with enabled=false when not yet configured.
     */
    public function getConfig(): EdgeCacheConfig
    {
        return new EdgeCacheConfig(
            $this->apiRequest('GET', rtrim($this->edgeApiUrl, '/') . '/config')
        );
    }

    /**
     * Update edge cache configuration.
     *
     * @param EdgeCacheConfigUpdate $update Fields to change; null fields are left unchanged.
     * @return EdgeCacheConfig Updated configuration.
     */
    public function setConfig(EdgeCacheConfigUpdate $update): EdgeCacheConfig
    {
        return new EdgeCacheConfig(
            $this->apiRequest('PUT', rtrim($this->edgeApiUrl, '/') . '/config', $update->toArray())
        );
    }

    /**
     * Disable and remove the edge cache configuration.
     *
     * @return array{message: string}
     */
    public function deleteConfig(): array
    {
        return $this->apiRequest('DELETE', rtrim($this->edgeApiUrl, '/') . '/config');
    }

    /**
     * Purge cached entries from Cloudflare CDN.
     *
     * - No options → purges all cached entries for this instance.
     * - namespaces → purges search results for those namespaces.
     * - urls → purges exact cache-key URLs.
     *
     * @param EdgePurgeOptions|null $options null = full purge
     * @return array{purged: int, urls: string[]}
     */
    public function purge(?EdgePurgeOptions $options = null): array
    {
        return $this->apiRequest(
            'POST',
            rtrim($this->edgeApiUrl, '/') . '/purge',
            $options?->toArray() ?? []
        );
    }

    /**
     * Return edge cache hit/miss statistics for this instance.
     */
    public function stats(): EdgeCacheStats
    {
        return new EdgeCacheStats(
            $this->apiRequest('GET', rtrim($this->edgeApiUrl, '/') . '/stats')
        );
    }
}
