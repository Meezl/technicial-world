<?php

/**
 * The Property Management & Corporate module.
 *
 * Off everywhere until the module is finished. The work lands in phases (see
 * PROPERTY_MANAGEMENT_MODULE_PLAN.md) and each phase ships to main long before
 * the whole is usable, so the flag is what stops a half-built corporate journey
 * being reachable by a real client on a Railway deploy.
 *
 * It gates entry points only — routes, menu items, the segment picker. It must
 * never gate the reading of data that already exists: a corporate request that
 * was created while the flag was on stays out of the retail queues when it goes
 * off again, because leaking it back in is the failure this flag exists to
 * prevent.
 */
return [
    'enabled' => env('CORPORATE_MODULE_ENABLED', false),

    /*
     * Kenyan tax rates, as at the time of writing.
     *
     * Set by law rather than by us, and they move. Configuration rather than
     * constants so a rate change is a deploy setting and not a code edit, and
     * overridable per organisation on client_organisations for the cases where
     * a particular client is treated differently.
     *
     * Both withholdings are computed on the VAT-exclusive value — see
     * InvoicingService::taxBreakdown, which reconciles against the worked
     * example in the brief.
     */
    'tax' => [
        'vat_rate' => (float) env('CORPORATE_VAT_RATE', 16),
        'whvat_rate' => (float) env('CORPORATE_WHVAT_RATE', 2),
        'wht_rate' => (float) env('CORPORATE_WHT_RATE', 3),
    ],

    /*
     * What goes on every invoice. The brief lists these explicitly: our PIN,
     * our bank details and our logo have to appear on the paperwork the client
     * files against their own accounts.
     */
    'issuer' => [
        'name' => env('CORPORATE_ISSUER_NAME', 'Technician World'),
        'kra_pin' => env('CORPORATE_ISSUER_KRA_PIN', ''),
        'address' => env('CORPORATE_ISSUER_ADDRESS', 'Jitegemea Flats, Suite F3, Jabavu Road, Hurlingham, Nairobi'),
        'email' => env('CORPORATE_ISSUER_EMAIL', 'tech.worldwide16@gmail.com'),
        'phone' => env('CORPORATE_ISSUER_PHONE', ''),
        'bank_name' => env('CORPORATE_BANK_NAME', ''),
        'bank_branch' => env('CORPORATE_BANK_BRANCH', ''),
        'bank_account_name' => env('CORPORATE_BANK_ACCOUNT_NAME', ''),
        'bank_account_number' => env('CORPORATE_BANK_ACCOUNT_NUMBER', ''),
        'bank_swift' => env('CORPORATE_BANK_SWIFT', ''),
    ],
];
