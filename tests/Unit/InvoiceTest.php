<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Invoice;
use Tests\MockAccountData;
use App\DataMapper\InvoiceItem;
use App\Factory\InvoiceFactory;
use App\Factory\InvoiceItemFactory;
use App\Helpers\Invoice\InvoiceSum;
use App\Helpers\Invoice\InvoiceSumInclusive;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * @test
 * @covers  App\Helpers\Invoice\InvoiceSum
 */
class InvoiceTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    public $invoice;

    public $invoice_calc;

    public $settings;

    protected function setUp() :void
    {
        parent::setUp();

        $this->makeTestData();

        $this->invoice->line_items = $this->buildLineItems();

        $this->invoice->uses_inclusive_taxes = true;

        $this->invoice_calc = new InvoiceSum($this->invoice);
    }

    public function testRoundingWithLargeUnitCostPrecision()
    {
        $invoice = InvoiceFactory::create($this->company->id, $this->user->id);
        $invoice->client_id = $this->client->id;
        $invoice->uses_inclusive_taxes = false;

        $line_items = [];
              
        $line_item = new InvoiceItem;
        $line_item->quantity = 1;
        $line_item->cost = 82.6446;
        $line_item->tax_rate1 = 21;
        $line_item->tax_name1 = 'Test';
        $line_item->product_key = 'Test';
        $line_item->notes = 'Test';
        $line_items[] = $line_item;

        $invoice->line_items = $line_items;
        $invoice->save();

        $invoice = $invoice->calc()->getInvoice();

        $this->assertEquals(100, $invoice->amount);

    }

    public function testInclusiveRounding()
    {
        $this->invoice->line_items = [];
        $this->invoice->discount = 0;
        $this->invoice->uses_inclusive_taxes = true;
        $this->invoice->save();


        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 50;
        $item->tax_name1 = "taxy";
        $item->tax_rate1 = 19;

        $line_items[] = $item;

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 50;
        $item->tax_name1 = "taxy";
        $item->tax_rate1 = 19;
        
        $line_items[] = $item;

        $this->invoice->line_items = $line_items;
        $this->invoice->save();

        $invoice_calc = new InvoiceSumInclusive($this->invoice);

        $invoice_calc->build();
        // $this->invoice->save();

        $this->assertEquals($invoice_calc->getTotalTaxes(), 15.96);
    }

    private function buildLineItems()
    {
        $line_items = [];

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;

        $line_items[] = $item;

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;

        $line_items[] = $item;

        return $line_items;
    }

    public function testInvoiceTotals()
    {
        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        $this->assertEquals($this->invoice_calc->getTotal(), 20);
    }

    public function testInvoiceTotalsWithDiscount()
    {
        $this->invoice->discount = 5;

        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        //$this->assertEquals($this->invoice_calc->getTotal(), 15);
        //$this->assertEquals($this->invoice_calc->getBalance(), 15);
    }

    public function testInvoiceTotalsWithDiscountWithSurcharge()
    {
        $this->invoice->discount = 5;
        $this->invoice->custom_surcharge1 = 5;

        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        //$this->assertEquals($this->invoice_calc->getTotal(), 20);
        //$this->assertEquals($this->invoice_calc->getBalance(), 20);
    }

    public function testInvoiceTotalsWithDiscountWithSurchargeWithInclusiveTax()
    {
        $this->invoice->discount = 5;
        $this->invoice->custom_surcharge1 = 5;
        $this->invoice->tax_name1 = 'GST';
        $this->invoice->tax_rate1 = 10;

        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        // $this->assertEquals($this->invoice_calc->getTotal(), 21.5);
        //$this->assertEquals($this->invoice_calc->getBalance(), 20);
    }

    public function testInvoiceTotalsWithDiscountWithSurchargeWithExclusiveTax()
    {
        $this->invoice->discount = 5;
        $this->invoice->custom_surcharge1 = 5;
        $this->invoice->tax_name1 = 'GST';
        $this->invoice->tax_rate1 = 10;
        $this->invoice->uses_inclusive_taxes = false;
        $this->invoice->is_amount_discount = true;

        //$this->invoice_calc = new InvoiceSum($this->invoice, $this->settings);

        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        // $this->assertEquals($this->invoice_calc->getGrossSubTotal(), 22);
        $this->assertEquals($this->invoice_calc->getTotal(), 21.5);
        //$this->assertEquals($this->invoice_calc->getBalance(), 21.5);
        //$this->assertEquals($this->invoice_calc->getTotalTaxes(), 1.5);
    }

    public function testInvoiceTotalsWithDiscountWithSurchargeWithDoubleExclusiveTax()
    {
        $this->invoice_calc = new InvoiceSum($this->invoice);

        $this->invoice->discount = 5;
        $this->invoice->custom_surcharge1 = 5;
        $this->invoice->tax_name1 = 'GST';
        $this->invoice->tax_rate1 = 10;
        $this->invoice->tax_name2 = 'GST';
        $this->invoice->tax_rate2 = 10;
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        $this->assertEquals($this->invoice_calc->getTotal(), 23);
        //$this->assertEquals($this->invoice_calc->getBalance(), 23);
        //$this->assertEquals($this->invoice_calc->getTotalTaxes(), 3);
    }

    public function testLineItemTaxRatesInclusiveTaxes()
    {
        $line_items = [];

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;
        $item->tax_rate1 = 10;
        $item->tax_name1 = 10;

        $line_items[] = $item;

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;
        $item->tax_rate1 = 10;
        $item->tax_name1 = 10;

        $line_items[] = $item;

        $this->invoice->line_items = $line_items;

        $this->invoice->uses_inclusive_taxes = true;
        $this->invoice->discount = 0;
        $this->invoice->custom_value1 = 0;

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        //$this->assertEquals($this->invoice_calc->getTotal(), 20);
        //$this->assertEquals($this->invoice_calc->getBalance(), 20);
        //$this->assertEquals($this->invoice_calc->getTotalTaxes(), 1.82);
        $this->assertEquals(count($this->invoice_calc->getTaxMap()), 1);
    }

    public function testLineItemTaxRatesExclusiveTaxes()
    {
        $line_items = [];

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;
        $item->tax_rate1 = 10;
        $item->tax_name1 = 10;

        $line_items[] = $item;

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;
        $item->tax_rate1 = 10;
        $item->tax_name1 = 10;

        $line_items[] = $item;

        $this->invoice->line_items = $line_items;
        $this->invoice->discount = 0;
        $this->invoice->tax_name1 = 'GST';
        $this->invoice->tax_rate1 = 10;
        $this->invoice->tax_name2 = 'GST';
        $this->invoice->tax_rate2 = 10;

        $this->invoice->uses_inclusive_taxes = false;
        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->assertEquals($this->invoice_calc->getSubTotal(), 20);
        // $this->assertEquals($this->invoice_calc->getGrossSubTotal(), 22);
        $this->assertEquals($this->invoice_calc->getTotal(), 26);
        //$this->assertEquals($this->invoice_calc->getBalance(), 26);
        //$this->assertEquals($this->invoice_calc->getTotalTaxes(), 4);
        //$this->assertEquals(count($this->invoice_calc->getTaxMap()), 1);
    }
}
