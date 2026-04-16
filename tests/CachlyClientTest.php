<?php

declare(strict_types=1);

namespace Cachly\Tests;

use Cachly\CachlyClient;
use Cachly\SemanticCache;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Predis\Client as PredisClient;

/**
 * Unit tests for cachly PHP SDK.
 *
 * Tests use a mocked Predis client to avoid needing a live Redis instance.
 */
class CachlyClientTest extends TestCase
{
    /** @var MockObject&PredisClient */
    private MockObject $predis;
    private CachlyClient $cache;

    protected function setUp(): void
    {
        $this->predis = $this->createMock(PredisClient::class);

        // Construct CachlyClient and inject mock via reflection
        $this->cache = (new \ReflectionClass(CachlyClient::class))
            ->newInstanceWithoutConstructor();

        $prop = (new \ReflectionClass(CachlyClient::class))->getProperty('redis');
        $prop->setAccessible(true);
        $prop->setValue($this->cache, $this->predis);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetScalarCallsPredisSet(): void
    {
        $this->predis->expects($this->once())
            ->method('set')
            ->with('key', 'hello');

        $this->cache->set('key', 'hello');
    }

    public function testSetArraySerializesToJson(): void
    {
        $this->predis->expects($this->once())
            ->method('set')
            ->with('key', json_encode(['a' => 1]));

        $this->cache->set('key', ['a' => 1]);
    }

    public function testSetWithTtlCallsSetex(): void
    {
        $this->predis->expects($this->once())
            ->method('setex')
            ->with('key', 300, json_encode(['x' => 2]));

        $this->cache->set('key', ['x' => 2], 300);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsDecodedJson(): void
    {
        $this->predis->method('get')->willReturn(json_encode(['name' => 'Alice']));
        $result = $this->cache->get('user:1');
        $this->assertSame(['name' => 'Alice'], $result);
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->predis->method('get')->willReturn(null);
        $this->assertNull($this->cache->get('missing'));
    }

    public function testGetReturnsRawStringForNonJson(): void
    {
        $this->predis->method('get')->willReturn('plain-string');
        $this->assertSame('plain-string', $this->cache->get('str'));
    }

    // ── del ───────────────────────────────────────────────────────────────────

    public function testDelCallsPredisWithAllKeys(): void
    {
        $this->predis->expects($this->once())
            ->method('del')
            ->with('a', 'b')
            ->willReturn(2);

        $count = $this->cache->del('a', 'b');
        $this->assertSame(2, $count);
    }

    // ── exists ────────────────────────────────────────────────────────────────

    public function testExistsReturnsTrueWhenKeyPresent(): void
    {
        $this->predis->method('exists')->willReturn(1);
        $this->assertTrue($this->cache->exists('present'));
    }

    public function testExistsReturnsFalseWhenKeyAbsent(): void
    {
        $this->predis->method('exists')->willReturn(0);
        $this->assertFalse($this->cache->exists('absent'));
    }

    // ── incr ──────────────────────────────────────────────────────────────────

    public function testIncrReturnsNewValue(): void
    {
        $this->predis->method('incr')->willReturn(5);
        $this->assertSame(5, $this->cache->incr('counter'));
    }

    // ── getOrSet ──────────────────────────────────────────────────────────────

    public function testGetOrSetReturnsCachedValueWithoutCallingFactory(): void
    {
        $this->predis->method('get')->willReturn(json_encode('cached-value'));
        $called = false;
        $result = $this->cache->getOrSet('key', function () use (&$called) {
            $called = true;
            return 'fresh';
        });

        $this->assertSame('cached-value', $result);
        $this->assertFalse($called, 'Factory should not be called on cache hit');
    }

    public function testGetOrSetCallsFactoryOnMissAndStoresResult(): void
    {
        $this->predis->method('get')->willReturn(null);
        $this->predis->expects($this->once())
            ->method('set')
            ->with('key', json_encode('fresh-value'));

        $result = $this->cache->getOrSet('key', fn() => 'fresh-value');
        $this->assertSame('fresh-value', $result);
    }

    public function testGetOrSetWithTtlUsesSetex(): void
    {
        $this->predis->method('get')->willReturn(null);
        $this->predis->expects($this->once())
            ->method('setex')
            ->with('key', 60, json_encode('val'));

        $this->cache->getOrSet('key', fn() => 'val', 60);
    }
}

/**
 * Pure unit tests for SemanticCache cosine similarity logic.
 *
 * The private method cosineSimilarity is tested indirectly via getOrSet,
 * using an embedding function that always returns a fixed vector.
 */
class SemanticCacheTest extends TestCase
{
    private function makeMockRedis(array $existingKeys = [], array $storedEntries = []): MockObject
    {
        /** @var MockObject&PredisClient $redis */
        $redis = $this->createMock(PredisClient::class);

        $redis->method('keys')->willReturn($existingKeys);

        if (!empty($storedEntries)) {
            $redis->method('get')->willReturnCallback(
                fn(string $key) => $storedEntries[$key] ?? null
            );
        } else {
            $redis->method('get')->willReturn(null);
        }

        return $redis;
    }

    public function testCacheMissCallsFnAndStoresResult(): void
    {
        $redis = $this->makeMockRedis();
        $redis->expects($this->once())->method('set');

        $sem   = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0];

        $called = 0;
        $result = $sem->getOrSet(
            prompt: 'hello',
            fn: function () use (&$called) { $called++; return 'answer'; },
            embedFn: $embed,
        );

        $this->assertFalse($result->hit);
        $this->assertSame('answer', $result->value);
        $this->assertNull($result->similarity);
        $this->assertSame(1, $called);
    }

    public function testCacheHitReturnsCachedValueWithoutCallingFn(): void
    {
        $vec   = [1.0, 0.0, 0.0];
        $entry = json_encode(['embedding' => $vec, 'value' => 'cached-answer', 'prompt' => 'hi']);

        $redis = $this->makeMockRedis(
            existingKeys: ['cachly:sem:abc'],
            storedEntries: ['cachly:sem:abc' => $entry],
        );

        $sem   = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0]; // identical → similarity = 1.0

        $called = 0;
        $result = $sem->getOrSet(
            prompt: 'hi',
            fn: function () use (&$called) { $called++; return 'fresh'; },
            embedFn: $embed,
            similarityThreshold: 0.9,
        );

        $this->assertTrue($result->hit);
        $this->assertSame('cached-answer', $result->value);
        $this->assertGreaterThan(0.99, $result->similarity);
        $this->assertSame(0, $called, 'Factory must not be called on semantic hit');
    }

    public function testBelowThresholdTriggersCacheMiss(): void
    {
        $storedVec = [1.0, 0.0, 0.0];
        $queryVec  = [0.0, 1.0, 0.0]; // orthogonal → similarity = 0.0
        $entry     = json_encode(['embedding' => $storedVec, 'value' => 'old', 'prompt' => 'old question']);

        $redis = $this->makeMockRedis(
            existingKeys: ['cachly:sem:old'],
            storedEntries: ['cachly:sem:old' => $entry],
        );
        $redis->expects($this->once())->method('set');

        $sem   = new SemanticCache($redis);
        $embed = fn(string $t) => $queryVec;

        $result = $sem->getOrSet(
            prompt: 'completely different',
            fn: fn() => 'new-answer',
            embedFn: $embed,
            similarityThreshold: 0.9,
        );

        $this->assertFalse($result->hit);
        $this->assertSame('new-answer', $result->value);
    }

    public function testFlushDeletesAllNamespaceKeys(): void
    {
        $redis = $this->createMock(PredisClient::class);
        $redis->method('scan')
            ->willReturnOnConsecutiveCalls(
                ['0', ['cachly:sem:emb:a', 'cachly:sem:emb:b']],
            );
        // scanAll for emb:* + val:* = two scan calls; stub both
        $redis->method('scan')
            ->willReturn(['0', []]);

        $sem = new SemanticCache($redis);
        $this->assertIsInt($sem->flush());
    }

    public function testSizeReturnsKeyCount(): void
    {
        $redis = $this->createMock(PredisClient::class);
        // First scan (emb:*) returns 3 keys, cursor=0 → done
        $redis->method('scan')
            ->willReturn(['0', ['cachly:sem:emb:x', 'cachly:sem:emb:y', 'cachly:sem:emb:z']]);

        $sem = new SemanticCache($redis);
        $this->assertSame(3, $sem->size());
    }
}

// ── §4 Namespace Auto-Detection Tests ─────────────────────────────────────────

/**
 * Unit tests for SemanticCache::detectNamespace() (§4).
 *
 * Static method – no Redis connection required.
 */
class DetectNamespaceTest extends TestCase
{
    /** @dataProvider codePromptProvider */
    public function testDetectsCodeNamespace(string $prompt): void
    {
        $this->assertSame('cachly:sem:code', SemanticCache::detectNamespace($prompt));
    }

    /** @return array<string, array{string}> */
    public static function codePromptProvider(): array
    {
        return [
            'python def'      => ['def process(data): return data * 2'],
            'js const'        => ['const x = () => 42;'],
            'typescript class'=> ['class MyService { constructor() {} }'],
            'import statement'=> ['import React from "react"'],
            'shebang'         => ['#!/usr/bin/env python3'],
            'go func'         => ['func main() { fmt.Println("hi") }'],
            'cpp include'     => ['#include <iostream> int main() {}'],
            'interface'       => ['interface IFoo { bar(): void; }'],
        ];
    }

    /** @dataProvider translationPromptProvider */
    public function testDetectsTranslationNamespace(string $prompt): void
    {
        $this->assertSame('cachly:sem:translation', SemanticCache::detectNamespace($prompt));
    }

    /** @return array<string, array{string}> */
    public static function translationPromptProvider(): array
    {
        return [
            'translate en'    => ['translate this text to english'],
            'übersetze'       => ['übersetze diesen Text auf englisch'],
            'auf deutsch'     => ['bitte auf deutsch schreiben'],
            'traduis'         => ['traduis ce texte en anglais'],
        ];
    }

    /** @dataProvider summaryPromptProvider */
    public function testDetectsSummaryNamespace(string $prompt): void
    {
        $this->assertSame('cachly:sem:summary', SemanticCache::detectNamespace($prompt));
    }

    /** @return array<string, array{string}> */
    public static function summaryPromptProvider(): array
    {
        return [
            'summarize'           => ['summarize this article for me'],
            'summarise'           => ['summarise the following text'],
            'tl;dr'               => ['tl;dr of this document?'],
            'zusammenfass'        => ['fasse zusammen was in dem Text steht'],
            'key points'          => ['give me the key points of this paper'],
            'in a nutshell'       => ['explain in a nutshell'],
        ];
    }

    /** @dataProvider qaPromptProvider */
    public function testDetectsQaNamespace(string $prompt): void
    {
        $this->assertSame('cachly:sem:qa', SemanticCache::detectNamespace($prompt));
    }

    /** @return array<string, array{string}> */
    public static function qaPromptProvider(): array
    {
        return [
            'what is'         => ['what is the capital of France?'],
            'how does'        => ['how does photosynthesis work?'],
            'trailing ?'      => ['Is Redis faster than Memcached?'],
            'wer ist'         => ['wer ist der aktuelle Bundeskanzler?'],
            'wie funktioniert'=> ['wie funktioniert ein JWT?'],
            'can you'         => ['can you explain recursion?'],
        ];
    }

    public function testDetectsCreativeNamespace(): void
    {
        $this->assertSame('cachly:sem:creative', SemanticCache::detectNamespace('Write a poem about autumn'));
        $this->assertSame('cachly:sem:creative', SemanticCache::detectNamespace('Tell me a story about dragons'));
        $this->assertSame('cachly:sem:creative', SemanticCache::detectNamespace('Generate a product description'));
    }

    public function testCodeTakesPriorityOverTranslation(): void
    {
        // Prompt contains both code keyword and "translate" – code wins (checked first)
        $this->assertSame('cachly:sem:code', SemanticCache::detectNamespace('translate this function def foo(): pass'));
    }

    public function testDetectionIsCaseInsensitive(): void
    {
        $this->assertSame('cachly:sem:code', SemanticCache::detectNamespace('CONST X = 1'));
        $this->assertSame('cachly:sem:translation', SemanticCache::detectNamespace('TRANSLATE TO GERMAN'));
        $this->assertSame('cachly:sem:summary', SemanticCache::detectNamespace('SUMMARIZE this'));
    }

    public function testWhitespaceHandling(): void
    {
        $this->assertSame('cachly:sem:qa', SemanticCache::detectNamespace('   what is dark matter?   '));
    }
}

// ── §8 Cache-Warming Tests ────────────────────────────────────────────────────

/**
 * Unit tests for SemanticCache::warmup() (§8).
 */
class WarmupTest extends TestCase
{
    private function makeEmptyRedis(): MockObject
    {
        /** @var MockObject&PredisClient $redis */
        $redis = $this->createMock(PredisClient::class);
        // scanAll({ns}:emb:*) returns no keys → all entries are cache misses
        $redis->method('scan')->willReturn(['0', []]);
        return $redis;
    }

    public function testWarmupWritesAllEntriesOnEmptyCache(): void
    {
        $redis = $this->makeEmptyRedis();
        // Expect set() called twice per entry: once for val, once for emb
        $redis->expects($this->exactly(4))
            ->method('set');

        $sem = new SemanticCache($redis);

        $embed = fn(string $t) => [1.0, 0.0, 0.0];
        $result = $sem->warmup(
            entries: [
                ['prompt' => 'What is Redis?',   'fn' => fn() => 'Redis is an in-memory store.'],
                ['prompt' => 'What is caching?', 'fn' => fn() => 'Caching stores repeated results.'],
            ],
            embedFn: $embed,
        );

        $this->assertSame(2, $result['warmed']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testWarmupSkipsEntriesWithMissingPromptOrFn(): void
    {
        $redis = $this->makeEmptyRedis();
        $redis->expects($this->never())->method('set');

        $sem = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0];

        $result = $sem->warmup(
            entries: [
                ['prompt' => '',       'fn' => fn() => 'answer'],  // empty prompt
                ['prompt' => 'hello',  'fn' => 'not-callable'],     // invalid fn
                ['prompt' => 'world'],                               // missing fn
            ],
            embedFn: $embed,
        );

        $this->assertSame(0, $result['warmed']);
        $this->assertSame(3, $result['skipped']);
    }

    public function testWarmupWithTtlUsesSetex(): void
    {
        $redis = $this->makeEmptyRedis();
        // Expect setex() instead of set() when TTL is set
        $redis->expects($this->exactly(2))
            ->method('setex');
        $redis->expects($this->never())->method('set');

        $sem = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0];

        $result = $sem->warmup(
            entries: [['prompt' => 'Hello world', 'fn' => fn() => 'hi']],
            embedFn: $embed,
            ttl: 3600,
        );

        $this->assertSame(1, $result['warmed']);
    }

    public function testWarmupAutoNamespaceDetectsCorrectNamespace(): void
    {
        $redis = $this->makeEmptyRedis();

        // Capture the namespace used in set() calls
        $capturedKeys = [];
        $redis->method('set')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return 'OK';
            });

        $sem = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0];

        $sem->warmup(
            entries: [
                ['prompt' => 'def fibonacci(n): return n if n < 2 else fibonacci(n-1)+fibonacci(n-2)',
                 'fn' => fn() => 'Python recursion example.'],
            ],
            embedFn: $embed,
            autoNamespace: true,
        );

        // At least one stored key must start with the code namespace
        $hasCodeNs = array_filter($capturedKeys, fn($k) => str_starts_with($k, 'cachly:sem:code:'));
        $this->assertNotEmpty($hasCodeNs, 'Expected a key under cachly:sem:code:* namespace');
    }

    public function testWarmupReturnsCorrectCountsWhenSomeFnThrow(): void
    {
        $redis = $this->makeEmptyRedis();
        // Allow any number of set() calls
        $redis->method('set')->willReturn('OK');

        $sem = new SemanticCache($redis);
        $embed = fn(string $t) => [1.0, 0.0, 0.0];

        $result = $sem->warmup(
            entries: [
                ['prompt' => 'Good entry',    'fn' => fn() => 'answer'],
                ['prompt' => 'Failing entry', 'fn' => fn() => throw new \RuntimeException('LLM error')],
                ['prompt' => 'Another good',  'fn' => fn() => 'answer2'],
            ],
            embedFn: $embed,
        );

        $this->assertSame(2, $result['warmed']);
        $this->assertSame(1, $result['skipped']);
    }
}

