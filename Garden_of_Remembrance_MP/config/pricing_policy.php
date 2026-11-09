<?php
/**
 * Official CMIS Pricing Policy & Source of Truth
 * 
 * Source: Official Client Price Sheet (November 15, 2025) & Official Interment Fee Receipt.
 * Reference files: price_sheet.jpg.jpg, interment_fee.jpg.jpg
 * 
 * Defines official lot pricing, interment fees, monthly amortizations,
 * and helper functions for map, popup, booking, reservation, and admin.
 */

if (!function_exists('get_official_pricing_config')) {
    function get_official_pricing_config(): array
    {
        return [
            'lawn' => [
                'regular' => [
                    'label' => 'Lawn (Regular)',
                    'price_per_lot' => 85000.00,
                    'num_lots' => 1,
                    'total_price' => 85000.00,
                    'reservation_deposit_pct' => 0.05,
                    'reservation_deposit' => 4250.00,
                    'amortization' => [
                        12 => ['months' => 12, 'monthly_payment' => 7226.00, 'total' => 85000.00],
                        24 => ['months' => 24, 'monthly_payment' => 4495.00, 'total' => 85000.00],
                        36 => ['months' => 36, 'monthly_payment' => 3336.00, 'total' => 85000.00],
                        48 => ['months' => 48, 'monthly_payment' => 2772.00, 'total' => 85000.00],
                        60 => ['months' => 60, 'monthly_payment' => 2446.00, 'total' => 85000.00],
                    ],
                ],
                'premium' => [
                    'label' => 'Lawn (Premium)',
                    'price_per_lot' => 89000.00,
                    'num_lots' => 1,
                    'total_price' => 89000.00,
                    'reservation_deposit_pct' => 0.05,
                    'reservation_deposit' => 4450.00,
                    'amortization' => [
                        12 => ['months' => 12, 'monthly_payment' => 7565.00, 'total' => 89000.00],
                        24 => ['months' => 24, 'monthly_payment' => 4707.00, 'total' => 89000.00],
                        36 => ['months' => 36, 'monthly_payment' => 3493.00, 'total' => 89000.00],
                        48 => ['months' => 48, 'monthly_payment' => 2903.00, 'total' => 89000.00],
                        60 => ['months' => 60, 'monthly_payment' => 2561.00, 'total' => 89000.00],
                    ],
                ],
                'special_premium' => [
                    'label' => 'Lawn (Special Premium)',
                    'price_per_lot' => 94000.00,
                    'num_lots' => 2,
                    'total_price' => 188000.00,
                    'reservation_deposit_pct' => 0.05,
                    'reservation_deposit' => 9400.00,
                    'amortization' => [
                        12 => ['months' => 12, 'monthly_payment' => 15981.00, 'total' => 188000.00],
                        24 => ['months' => 24, 'monthly_payment' => 9941.00, 'total' => 188000.00],
                        36 => ['months' => 36, 'monthly_payment' => 7377.00, 'total' => 188000.00],
                        48 => ['months' => 48, 'monthly_payment' => 6130.00, 'total' => 188000.00],
                        60 => ['months' => 60, 'monthly_payment' => 5409.00, 'total' => 188000.00],
                    ],
                ],
            ],
            'garden' => [
                'regular' => [
                    'label' => 'Garden (Regular)',
                    'price_per_lot' => 87000.00,
                    'num_lots' => 4,
                    'total_price' => 348000.00,
                ],
                'premium' => [
                    'label' => 'Garden (Premium)',
                    'price_per_lot' => 90000.00,
                    'num_lots' => 4,
                    'total_price' => 360000.00,
                ],
            ],
            'family_estate' => [
                'special_premium' => [
                    'label' => 'Family Estate (Special Premium)',
                    'price_per_lot' => 98000.00,
                    'num_lots' => 12,
                    'total_price' => 1176000.00,
                ],
            ],
            'interment_fees' => [
                'promo' => [
                    'label' => 'Promo Price',
                    'amount' => 38000.00,
                    'description' => 'Official Promo Interment Fee Package',
                ],
                'standard' => [
                    'label' => 'Standard Package Price',
                    'amount' => 45000.00,
                    'description' => 'Standard Interment Fee Package',
                ],
                'senior_pwd' => [
                    'label' => 'SC / PWD Price',
                    'amount' => 36000.00,
                    'description' => 'Discounted Senior Citizen / PWD Interment Package',
                ],
            ],
        ];
    }
}

if (!function_exists('is_corner_premium_plot')) {
    /**
     * Determines whether a plot at given coordinates is a corner/premium plot.
     */
    function is_corner_premium_plot(string $section, int $plot_x, int $plot_y): bool
    {
        $sec = strtoupper(trim($section));
        $cornerPlots = [
            'A' => ['1-1', '1-2', '10-1', '10-2'],
            'B' => ['1-1', '1-2', '8-1', '8-2'],
            'C' => ['1-1', '1-2', '6-1', '6-2'],
            'D' => ['1-1', '1-2', '6-1', '6-2'],
            'E' => ['1-1', '1-2', '9-1', '9-2'],
            'F' => ['1-1', '1-2', '6-1', '6-2'],
        ];
        $key = $plot_x . '-' . $plot_y;
        return isset($cornerPlots[$sec]) && in_array($key, $cornerPlots[$sec], true);
    }
}

if (!function_exists('get_plot_official_pricing')) {
    /**
     * Centralized lookup for official plot pricing.
     * 
     * @param string $section Section letter (e.g. 'A' through 'J')
     * @param int $plot_x Plot X coordinate
     * @param int $plot_y Plot Y coordinate
     * @param string $plot_type Explicit plot type or 'Lawn' by default
     * @return array Itemized pricing details
     */
    function get_plot_official_pricing(string $section, int $plot_x = 0, int $plot_y = 0, string $plot_type = 'Lawn'): array
    {
        $sec = strtoupper(trim($section));
        $config = get_official_pricing_config();
        
        // Sections A through J on the cemetery map are classified as Lawn Type
        $isLawnSection = in_array($sec, ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'], true);
        $isLawn = $isLawnSection || stripos($plot_type, 'lawn') !== false;

        if ($isLawn) {
            $isExplicitPremium = (stripos($plot_type, 'premium') !== false);
            $isCorner = ($plot_x > 0 && $plot_y > 0) ? is_corner_premium_plot($sec, $plot_x, $plot_y) : false;
            $tierKey = ($isExplicitPremium || $isCorner) ? 'premium' : 'regular';
            $tier = $config['lawn'][$tierKey];
            $intermentFee = $config['interment_fees']['promo']['amount']; // 38,000.00 promo price
            $lotPrice = (float)$tier['price_per_lot'];
            $totalPrice = $lotPrice + $intermentFee; // Combined package total

            return [
                'plot_category' => 'Lawn',
                'lawn_tier' => $tierKey,
                'lawn_type' => $tier['label'],
                'lot_price' => $lotPrice,
                'price' => $lotPrice,
                'interment_fee' => $intermentFee,
                'total_price' => $totalPrice,
                'reservation_deposit' => $tier['reservation_deposit'],
                'reservation_deposit_pct' => $tier['reservation_deposit_pct'],
                'amortization' => $tier['amortization'],
                'notes' => [
                    'Double interment included for all lawn lots.',
                    'Reservation deposit of 5% is good for 15 days only.',
                ],
            ];
        }

        if (stripos($plot_type, 'garden') !== false) {
            $tier = $config['garden']['regular'];
            return [
                'plot_category' => 'Garden',
                'lawn_tier' => 'regular',
                'lawn_type' => $tier['label'],
                'lot_price' => (float)$tier['price_per_lot'],
                'price' => (float)$tier['price_per_lot'],
                'interment_fee' => 38000.00,
                'total_price' => (float)$tier['total_price'],
                'reservation_deposit' => (float)$tier['total_price'] * 0.05,
                'amortization' => [],
                'notes' => [],
            ];
        }

        if (stripos($plot_type, 'family') !== false) {
            $tier = $config['family_estate']['special_premium'];
            return [
                'plot_category' => 'Family Estate',
                'lawn_tier' => 'special_premium',
                'lawn_type' => $tier['label'],
                'lot_price' => (float)$tier['price_per_lot'],
                'price' => (float)$tier['price_per_lot'],
                'interment_fee' => 38000.00,
                'total_price' => (float)$tier['total_price'],
                'reservation_deposit' => (float)$tier['total_price'] * 0.05,
                'amortization' => [],
                'notes' => [],
            ];
        }

        // Default to Regular Lawn if unspecified
        $tier = $config['lawn']['regular'];
        return [
            'plot_category' => 'Lawn',
            'lawn_tier' => 'regular',
            'lawn_type' => $tier['label'],
            'lot_price' => 85000.00,
            'price' => 85000.00,
            'interment_fee' => 38000.00,
            'total_price' => 123000.00,
            'reservation_deposit' => 4250.00,
            'reservation_deposit_pct' => 0.05,
            'amortization' => $tier['amortization'],
            'notes' => [],
        ];
    }
}
