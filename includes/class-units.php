<?php
/**
 * Универсальный конвертер единиц измерения.
 *
 * OneCatalog отдаёт величины в НАИМЕНЬШЕЙ единице: вес — граммы, размеры —
 * миллиметры (подпись единицы в payload локализована: «мм», «г» и т.п.).
 * Магазин (WooCommerce) хранит в настраиваемых единицах
 * (`woocommerce_weight_unit` / `woocommerce_dimension_unit`: кг/г/lbs/oz, см/м/мм/in/yd),
 * зависящих в т.ч. от страны. Конвертер приводит источник → единицы магазина.
 *
 * Реализация самодостаточна (таблицы коэффициентов к базовой единице), чтобы
 * переноситься на любую платформу; локализованные/синонимичные подписи единиц
 * нормализуются к канону.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Units
{
    /** Коэффициенты к базовой единице веса — ГРАММУ. */
    private const WEIGHT = [
        'mg' => 0.001, 'g' => 1.0, 'kg' => 1000.0, 't' => 1_000_000.0,
        'oz' => 28.349523125, 'lb' => 453.59237, 'lbs' => 453.59237,
    ];

    /** Коэффициенты к базовой единице длины — МИЛЛИМЕТРУ. */
    private const LENGTH = [
        'mm' => 1.0, 'cm' => 10.0, 'dm' => 100.0, 'm' => 1000.0, 'km' => 1_000_000.0,
        'in' => 25.4, 'ft' => 304.8, 'yd' => 914.4,
    ];

    /** Локализованные/синонимичные подписи → канон. */
    private const ALIASES = [
        // вес
        'мг' => 'mg', 'миллиграмм' => 'mg',
        'г' => 'g', 'гр' => 'g', 'грамм' => 'g', 'граммы' => 'g', 'gr' => 'g', 'gram' => 'g', 'grams' => 'g', 'gramme' => 'g',
        'кг' => 'kg', 'килограмм' => 'kg', 'kilogram' => 'kg', 'kgs' => 'kg',
        'т' => 't', 'тонна' => 't', 'ton' => 't', 'tonne' => 't',
        'унция' => 'oz', 'ounce' => 'oz',
        'фунт' => 'lb', 'фунты' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
        // длина
        'мм' => 'mm', 'миллиметр' => 'mm', 'millimeter' => 'mm', 'millimetre' => 'mm',
        'см' => 'cm', 'сантиметр' => 'cm', 'centimeter' => 'cm', 'centimetre' => 'cm',
        'дм' => 'dm', 'дециметр' => 'dm',
        'м' => 'm', 'метр' => 'm', 'meter' => 'm', 'metre' => 'm',
        'км' => 'km', 'километр' => 'km', 'kilometer' => 'km',
        'дюйм' => 'in', 'inch' => 'in', 'inches' => 'in', '"' => 'in',
        'фут' => 'ft', 'foot' => 'ft', 'feet' => 'ft',
        'ярд' => 'yd', 'yard' => 'yd',
    ];

    /** Нормализовать подпись единицы к канону (регистр/пробелы/локализация). */
    public static function canon(string $unit): string
    {
        $u = mb_strtolower(trim($unit), 'UTF-8');
        return self::ALIASES[$u] ?? $u;
    }

    /** Конверсия по таблице коэффициентов; неизвестная единица → значение без изменений. */
    private static function convert(float $value, string $from, string $to, array $table): float
    {
        $from = self::canon($from);
        $to   = self::canon($to);
        if (! isset($table[$from]) || ! isset($table[$to])) {
            return $value; // не знаем единицу — лучше не «сломать» число
        }
        if ($from === $to) {
            return $value;
        }
        return $value * $table[$from] / $table[$to];
    }

    /** Вес: $from → $to (например, 'g' → 'kg'). */
    public static function weight(float $value, string $from, string $to): float
    {
        return self::convert($value, $from, $to, self::WEIGHT);
    }

    /** Длина: $from → $to (например, 'mm' → 'cm'). */
    public static function dimension(float $value, string $from, string $to): float
    {
        return self::convert($value, $from, $to, self::LENGTH);
    }

    /** Известна ли единица веса. */
    public static function is_weight(string $unit): bool
    {
        return isset(self::WEIGHT[self::canon($unit)]);
    }

    /** Известна ли единица длины. */
    public static function is_dimension(string $unit): bool
    {
        return isset(self::LENGTH[self::canon($unit)]);
    }

    /** Единица веса магазина (WooCommerce). */
    public static function store_weight_unit(): string
    {
        return (string) (get_option('woocommerce_weight_unit') ?: 'kg');
    }

    /** Единица длины магазина (WooCommerce). */
    public static function store_dimension_unit(): string
    {
        return (string) (get_option('woocommerce_dimension_unit') ?: 'cm');
    }
}
