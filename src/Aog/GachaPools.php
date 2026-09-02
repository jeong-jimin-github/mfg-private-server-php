<?php

declare(strict_types=1);

namespace Mfg\Aog;

final class GachaPools
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<string,mixed> */
    public static function data(): array
    {
        if (self::$data !== null) return self::$data;
        $path = dirname(__DIR__, 2).'/data/gacha_pools.json';
        $raw = @file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Missing data/gacha_pools.json');
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['standard_pool'], $data['series'])) {
            throw new \RuntimeException('Invalid gacha pool data');
        }
        return self::$data = $data;
    }

    /** @return array<string,array<string,mixed>> */
    public static function series(): array
    {
        $s = self::data()['series'] ?? [];
        return is_array($s) ? $s : [];
    }

    /** @return array<string,mixed> */
    public static function seriesEntry(int $id): array
    {
        $entry = self::series()[(string)$id] ?? [];
        return is_array($entry) ? $entry : [];
    }

    /** @return list<string> */
    public static function poolForSeries(int $id): array
    {
        $data = self::data();
        $entry = self::seriesEntry($id);
        if (($entry['type'] ?? '') === 'Music') {
            return self::strings($entry['music_items'] ?? []);
        }
        $items = self::strings($data['standard_pool'] ?? []);
        foreach (self::strings($entry['extra_items'] ?? []) as $oid) {
            if (!in_array($oid, $items, true)) $items[] = $oid;
        }
        return $items;
    }

    /** @return list<string> */
    public static function pickupCharas(int $id): array
    {
        return self::strings(self::seriesEntry($id)['pickup_charas'] ?? []);
    }

    /** @return list<string> */
    public static function customPickupItems(int $id): array
    {
        return self::strings(self::seriesEntry($id)['custom_pickup_items'] ?? []);
    }

    /** @param mixed $v @return list<string> */
    private static function strings(mixed $v): array
    {
        if (!is_array($v)) return [];
        return array_values(array_map('strval', $v));
    }
}
