<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper\Tax;

interface RuleInterface
{
    public function init();

    public function tax($item = null);

    public function taxByType($type);

    public function taxExempt();
    
    public function taxDigital();

    public function taxService();

    public function taxShipping();

    public function taxPhysical();

    public function taxReduced();

    public function default();

    public function override();

    public function calculateRates();
}