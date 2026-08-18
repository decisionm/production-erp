<?php

namespace App\Modules\Core\Exports;

use BackedEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use ReflectionProperty;
use Stringable;
use UnitEnum;

/**
 * A kind's Laravel validation rules, DESCRIBED for a client that has to
 * draw a filter form — one field per top-level rule key. The rules stay the
 * single grammar (the client never re-validates); this is a rendering hint
 * derived from them, so a rule added to a List*Request shows up on the
 * Center's form without anyone touching a second list.
 *
 * Field shape: {name, type, required, multiple, options}
 *   type      date | integer | number | boolean | select | text
 *   multiple  true for an `array` rule (its element type is read from the
 *             `key.*` rules)
 *   options   the accepted values of an in:/Rule::in/Rule::enum rule; null
 *             otherwise
 * Anything unrecognised is 'text' — the server still validates it.
 */
final class FilterSchema
{
    /**
     * @param  array<string, mixed>  $rules
     * @return list<array{name: string, type: string, required: bool, multiple: bool, options: ?list<string|int>}>
     */
    public static function describe(array $rules): array
    {
        $fields = [];

        foreach ($rules as $name => $ruleSet) {
            // `status.*` and deeper paths describe elements, not fields.
            if (str_contains((string) $name, '.')) {
                continue;
            }

            $set = self::normalise($ruleSet);
            $multiple = in_array('array', $set, true);
            $elementSet = $multiple ? self::normalise($rules["{$name}.*"] ?? []) : $set;
            [$type, $options] = self::typeOf($elementSet);

            $fields[] = [
                'name' => (string) $name,
                'type' => $type,
                'required' => in_array('required', $set, true),
                'multiple' => $multiple,
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * A rule set as Laravel accepts it — a pipe string, an array of strings
     * and rule objects — as a flat list.
     *
     * @return list<mixed>
     */
    private static function normalise(mixed $ruleSet): array
    {
        if (is_string($ruleSet)) {
            return $ruleSet === '' ? [] : explode('|', $ruleSet);
        }

        return is_array($ruleSet) ? array_values($ruleSet) : [$ruleSet];
    }

    /**
     * @param  list<mixed>  $set
     * @return array{0: string, 1: ?list<string|int>}
     */
    private static function typeOf(array $set): array
    {
        $type = 'text';
        $options = null;

        foreach ($set as $rule) {
            if ($rule instanceof Enum) {
                $type = 'select';
                $options = self::enumOptions($rule);

                continue;
            }

            if ($rule instanceof In) {
                $type = 'select';
                $options = self::inOptions((string) $rule);

                continue;
            }

            if ($rule instanceof Exists) {
                $type = 'integer';

                continue;
            }

            if ($rule instanceof ValidationRule || $rule instanceof Stringable || ! is_string($rule)) {
                continue;
            }

            $keyword = strtolower((string) strtok($rule, ':'));

            match ($keyword) {
                'date', 'date_format' => $type = 'date',
                'integer', 'exists' => $type = 'integer',
                'numeric', 'decimal' => $type = 'number',
                'boolean' => $type = 'boolean',
                'in' => [$type, $options] = ['select', self::inOptions($rule)],
                default => null,
            };
        }

        return [$type, $options];
    }

    /**
     * The cases Rule::enum accepts, as their wire values (a backed enum's
     * value, else the case name).
     *
     * @return list<string|int>
     */
    private static function enumOptions(Enum $rule): array
    {
        $type = (new ReflectionProperty($rule, 'type'))->getValue($rule);
        $only = (new ReflectionProperty($rule, 'only'))->getValue($rule);
        $except = (new ReflectionProperty($rule, 'except'))->getValue($rule);

        if (! is_string($type) || ! enum_exists($type)) {
            return [];
        }

        /** @var list<UnitEnum> $cases */
        $cases = $type::cases();
        if ($only !== []) {
            $cases = array_values(array_filter($cases, fn (UnitEnum $case) => in_array($case, $only, true)));
        }
        if ($except !== []) {
            $cases = array_values(array_filter($cases, fn (UnitEnum $case) => ! in_array($case, $except, true)));
        }

        return array_map(fn (UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name, $cases);
    }

    /**
     * The values of an `in:` rule — Rule::in quotes each ("a","b") and a
     * hand-written string does not (a,b); both parse the same way.
     *
     * @return list<string>
     */
    private static function inOptions(string $rule): array
    {
        $list = substr($rule, strlen('in:'));
        if ($list === '') {
            return [];
        }

        return array_map(
            fn (?string $value) => (string) $value,
            str_getcsv($list, ',', '"', ''),
        );
    }
}
