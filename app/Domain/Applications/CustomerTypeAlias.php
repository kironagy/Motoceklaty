<?php

namespace App\Domain\Applications;

/**
 * Conversation 753 (2026-10-02): a Talabat rider said "طلبات" twice and the
 * model passed customer_type "delivery_app" - a work type, not a customer
 * type. Every tool answered UNKNOWN_CUSTOMER_TYPE, he was asked what he
 * works three times and left. A work type is a self_employed customer with
 * that work_type, so the tools take either.
 */
class CustomerTypeAlias
{
    public const WORK_TYPES = ['craftsman', 'delivery_app', 'delivery_app_bicycle', 'delivery_company', 'other'];

    private const ALIASES = [
        'freelancer' => 'self_employed',
        'freelance' => 'self_employed',
        'self-employed' => 'self_employed',
        'retired' => 'pension',
        'pensioner' => 'pension',
        'retiree' => 'pension',
        'employed' => 'employee',
        'government_employee' => 'employee',
        'private_employee' => 'employee',
        'business' => 'business_owner',
        'owner' => 'business_owner',
    ];

    /**
     * @return array{0: array, 1: ?string} the args with a real customer type, and the work type it carried
     */
    public static function normalize(array $args): array
    {
        if (! isset($args['customer_type']) || ! is_string($args['customer_type'])) {
            return [$args, null];
        }

        $type = strtolower(trim($args['customer_type']));

        if (in_array($type, self::WORK_TYPES, true)) {
            $args['customer_type'] = 'self_employed';

            return [$args, $type];
        }

        if (isset(self::ALIASES[$type])) {
            $args['customer_type'] = self::ALIASES[$type];
        }

        return [$args, null];
    }
}
