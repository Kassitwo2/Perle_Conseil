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

namespace App\DataMapper\Tax\DE;

use App\Models\Product;
use App\DataMapper\Tax\BaseRule;
use App\DataMapper\Tax\RuleInterface;

class Rule extends BaseRule implements RuleInterface
{    
    /** @var string $seller_region */
    public string $seller_region = 'EU';
    
    /** @var bool $consumer_tax_exempt */
    public bool $consumer_tax_exempt = false;
    
    /** @var bool $business_tax_exempt */
    public bool $business_tax_exempt = false;
    
    /** @var bool $eu_business_tax_exempt */
    public bool $eu_business_tax_exempt = true;
    
    /** @var bool $foreign_business_tax_exempt */
    public bool $foreign_business_tax_exempt = true;
    
    /** @var bool $foreign_consumer_tax_exempt */
    public bool $foreign_consumer_tax_exempt = true;
    
    /** @var float $tax_rate */
    public float $tax_rate = 0;
    
    /** @var float $reduced_tax_rate */
    public float $reduced_tax_rate = 0;
    
    /**
     * Initializes the rules and builds any required data.
     *
     * @return self
     */
    public function init(): self
    {
        $this->calculateRates();
        
        return $this;
    }
    
    /**
     * Sets the correct tax rate based on the product type.
     *
     * @param  mixed $product_tax_type
     * @return self
     */
    public function taxByType($product_tax_type): self
    {

        if ($this->client->is_tax_exempt) {
            return $this->taxExempt();
        }

        match(intval($product_tax_type)){
            Product::PRODUCT_TYPE_EXEMPT => $this->taxExempt(),
            Product::PRODUCT_TYPE_DIGITAL => $this->taxDigital(),
            Product::PRODUCT_TYPE_SERVICE => $this->taxService(),
            Product::PRODUCT_TYPE_SHIPPING => $this->taxShipping(),
            Product::PRODUCT_TYPE_PHYSICAL => $this->taxPhysical(),
            Product::PRODUCT_TYPE_REDUCED_TAX => $this->taxReduced(),
            Product::PRODUCT_TYPE_OVERRIDE_TAX => $this->override(),
            default => $this->default(),
        };
        
        return $this;
    }
    
    /**
     * Calculates the tax rate for a reduced tax product
     *
     * @return self
     */
    public function taxReduced(): self
    {
        $this->tax_rate1 = $this->reduced_tax_rate;
        $this->tax_name1 = 'ermäßigte MwSt.';

        return $this;
    }
    
    /**
     * Calculates the tax rate for a tax exempt product
     *
     * @return self
     */
    public function taxExempt(): self
    {
        $this->tax_name1 = '';
        $this->tax_rate1 = 0;

        return $this;
    }
    
    /**
     * Calculates the tax rate for a digital product
     *
     * @return self
     */
    public function taxDigital(): self
    {

        $this->tax_rate1 = $this->tax_rate;
        $this->tax_name1 = 'MwSt.';

        return $this;
    }
    
    /**
     * Calculates the tax rate for a service product
     *
     * @return self
     */
    public function taxService(): self
    {

        $this->tax_rate1 = $this->tax_rate;
        $this->tax_name1 = 'MwSt.';

        return $this;
    }
    
    /**
     * Calculates the tax rate for a shipping product
     *
     * @return self
     */
    public function taxShipping(): self
    {

        $this->tax_rate1 = $this->tax_rate;
        $this->tax_name1 = 'MwSt.';

        return $this;
    }
    
    /**
     * Calculates the tax rate for a physical product
     *
     * @return self
     */
    public function taxPhysical(): self
    {

        $this->tax_rate1 = $this->tax_rate;
        $this->tax_name1 = 'MwSt.';

        return $this;
    }
    
    /**
     * Calculates the tax rate for a default product
     *
     * @return self
     */
    public function default(): self
    {
        
        $this->tax_name1 = '';
        $this->tax_rate1 = 0;

        return $this;
    }
    
    /**
     * Calculates the tax rate for an override product
     *
     * @return self
     */
    public function override(): self
    {
        return $this;
    }
    
    /**
     * Calculates the tax rates based on the client's location.
     *
     * @return self
     */
    public function calculateRates(): self
    {
        if ($this->client->is_tax_exempt) {
            // nlog("tax exempt");
            $this->tax_rate = 0;
            $this->reduced_tax_rate = 0;
        }
        elseif($this->client_subregion != $this->client->company->tax_data->seller_subregion && in_array($this->client_subregion, $this->eu_country_codes) && $this->client->has_valid_vat_number && $this->eu_business_tax_exempt)
        {
            // nlog("euro zone and tax exempt");
            $this->tax_rate = 0;
            $this->reduced_tax_rate = 0;
        }
        elseif(!in_array($this->client_subregion, $this->eu_country_codes) && ($this->foreign_consumer_tax_exempt || $this->foreign_business_tax_exempt)) //foreign + tax exempt
        {
            // nlog("foreign and tax exempt");
            $this->tax_rate = 0;
            $this->reduced_tax_rate = 0;
        }
        elseif(in_array($this->client_subregion, $this->eu_country_codes) && !$this->client->has_valid_vat_number) //eu country / no valid vat 
        {   
            if(($this->client->company->tax_data->seller_subregion != $this->client_subregion) && $this->client->company->tax_data->regions->EU->has_sales_above_threshold)
            {
                // nlog("eu zone with sales above threshold");
                $this->tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->country->iso_3166_2}->tax_rate;
                $this->reduced_tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->country->iso_3166_2}->reduced_tax_rate;
            }
            else {
                // nlog("EU with intra-community supply ie DE to DE");
                $this->tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->company->country()->iso_3166_2}->tax_rate;
                $this->reduced_tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->company->country()->iso_3166_2}->reduced_tax_rate;
            }
        }
        else {
            // nlog("default tax");
            $this->tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->company->country()->iso_3166_2}->tax_rate;
            $this->reduced_tax_rate = $this->client->company->tax_data->regions->EU->subregions->{$this->client->company->country()->iso_3166_2}->reduced_tax_rate;
        }

        return $this;

    }

}