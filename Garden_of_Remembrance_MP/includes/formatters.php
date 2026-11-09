<?php

if (!function_exists('formatDisplayCurrency')) {
    function formatDisplayCurrency($amount) {
        if ($amount === null || $amount === '') {
            return '₱0';
        }
        $val = (float)$amount;
        if (floor($val) == $val) {
            return '₱' . number_format($val, 0);
        } else {
            return '₱' . number_format($val, 2);
        }
    }
}

if (!function_exists('formatDisplayNumber')) {
    function formatDisplayNumber($num) {
        if ($num === null || $num === '') {
            return '0';
        }
        $val = (float)$num;
        if (floor($val) == $val) {
            return number_format($val, 0);
        } else {
            return rtrim(rtrim(number_format($val, 2), '0'), '.');
        }
    }
}
