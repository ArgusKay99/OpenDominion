<?php

use Illuminate\Support\Carbon;

if (!function_exists('carbon')) {
    /**
     * Carbon helper function.
     *
     * @see https://github.com/laravel/framework/pull/21660#issuecomment-338359149
     *
     * @param mixed ...$params
     * @return Carbon
     */
    function carbon(...$params)
    {
        if (!$params) {
            return now();
        }

        if ($params[0] instanceof DateTime) {
            return Carbon::instance($params[0]);
        }

        if (is_numeric($params[0]) && ((string)(int)$params[0] === (string)$params[0])) {
            return Carbon::createFromTimestamp(...$params);
        }

        return Carbon::parse(...$params);
    }
}

if (!function_exists('clamp')) {
    /**
     * Clamps $current number between $min and $max.
     *
     * (tfw no generics)
     *
     * @param int|float $current
     * @param int|float $min
     * @param int|float $max
     * @return int|float
     */
    function clamp($current, $min, $max) {
        return max($min, min($max, $current));
    }
}

if (!function_exists('generate_sentence_from_array')) {
    /**
     * Generates a string with conjunction from an array of strings.
     *
     * @param array $stringParts
     * @param string $delimiter
     * @param string $lastDelimiter
     * @return string
     */
    function generate_sentence_from_array(
        array $stringParts,
        string $delimiter = ', ',
        string $lastDelimiter = ' and '
    ): string {
        return str_replace_last($delimiter, $lastDelimiter, implode($delimiter, $stringParts));
    }
}

if (!function_exists('dominion_attr_display')) {
    /**
     * Returns a string suitable for display with prefix removed.
     *
     * @param string $attribute
     * @param float $value
     * @return string
     */
    function dominion_attr_display(string $attribute, float $value = 1): string {
        $pluralAttributeDisplay = [
            'prestige' => 'prestige',
            'morale' => 'morale',
            'spy_strength' => 'percent spy strength',
            'wizard_strength' => 'percent wizard strength',
            'resource_platinum' => 'platinum',
            'resource_food' => 'food',
            'resource_lumber' => 'lumber',
            'resource_mana' => 'mana',
            'resource_ore' => 'ore',
            'resource_tech' => 'tech',
            'land_water' => 'water',
        ];

        if (isset($pluralAttributeDisplay[$attribute])) {
            return $pluralAttributeDisplay[$attribute];
        } else {
            if (strpos($attribute, '_') !== false) {
                $stringParts = explode('_', $attribute);
                array_shift($stringParts);
                return str_plural(str_singular(implode(' ', $stringParts)), $value);
            } else {
                return str_plural(str_singular($attribute), $value);
            }
        }
    }
}

if (!function_exists('random_chance')) {
    $mockRandomChance = false;
    /**
     * Returns whether a random chance check succeeds.
     *
     * Used for the very few RNG checks in OpenDominion.
     *
     * @param float $chance Floating-point number between 0.0 and 1.0, representing 0% and 100%, respectively
     * @return bool
     * @throws Exception
     */
    function random_chance(float $chance): bool
    {
        global $mockRandomChance;
        if ($mockRandomChance === true) {
            return false;
        }

        return ((random_int(0, mt_getrandmax()) / mt_getrandmax()) <= $chance);
    }
}

if (!function_exists('random_distribution')) {
    /**
     * Returns a random value from a normal probability distribution.
     *
     * Uses the Box-Muller Transform method.
     *
     * @param float $mean
     * @param float $standard_deviation
     * @return bool
     * @throws Exception
     */
    function random_distribution(float $mean, float $standard_deviation): float
    {
        $x = mt_rand()/mt_getrandmax();
        $y = mt_rand()/mt_getrandmax();
        return sqrt(-2 * log($x)) * cos(2 * pi() * $y) * $standard_deviation + $mean;
    }
}

if (!function_exists('error_function')) {
    /**
     * Gaussian error function
     *
     * https://github.com/tdebatty/php-stats/blob/master/src/webd/stats/Erf.php
     *
     * @param float $x
     * @return float
     * @throws Exception
     */
    function error_function(float $x): float
    {
        $t =1 / (1 + 0.5 * abs($x));
        $tau = $t * exp(
                - $x * $x
                - 1.26551223
                + 1.00002368 * $t
                + 0.37409196 * $t * $t
                + 0.09678418 * $t * $t * $t
                - 0.18628806 * $t * $t * $t * $t
                + 0.27886807 * $t * $t * $t * $t * $t
                - 1.13520398 * $t * $t * $t * $t * $t * $t
                + 1.48851587 * $t * $t * $t * $t * $t * $t * $t
                - 0.82215223 * $t * $t * $t * $t * $t * $t * $t * $t
                + 0.17087277 * $t * $t * $t * $t * $t * $t * $t * $t * $t);
        if ($x >= 0) {
            return 1 - $tau;
        } else {
            return $tau - 1;
        }
    }
}

if (!function_exists('number_string')) {
    /**
     * Generates a string from a number with number_format, and optionally an
     * explicit + sign prefix.
     *
     * @param int|float $number
     * @param int $numDecimals
     * @param bool $explicitPlusSign
     * @return string
     */
    function number_string($number, int $numDecimals = 0, bool $explicitPlusSign = false): string {
        $string = number_format($number, $numDecimals);

        if ($explicitPlusSign && $number > 0) {
            $string = "+{$string}";
        }

        return $string;
    }
}
