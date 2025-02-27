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

namespace App\Services\Scheduler;

use App\Models\Client;
use App\Models\Scheduler;
use App\Mail\DownloadReport;
use App\Export\CSV\TaskExport;
use App\Export\CSV\QuoteExport;
use App\Utils\Traits\MakesHash;
use App\Export\CSV\ClientExport;
use App\Export\CSV\CreditExport;
use App\Utils\Traits\MakesDates;
use App\Export\CSV\ContactExport;
use App\Export\CSV\ExpenseExport;
use App\Export\CSV\InvoiceExport;
use App\Export\CSV\PaymentExport;
use App\Export\CSV\ProductExport;
use App\Jobs\Mail\NinjaMailerJob;
use App\Export\CSV\DocumentExport;
use App\Export\CSV\QuoteItemExport;
use App\Services\Report\ProfitLoss;
use App\Jobs\Mail\NinjaMailerObject;
use App\Export\CSV\InvoiceItemExport;
use App\Export\CSV\ProductSalesExport;
use App\Services\Report\ARDetailReport;
use App\Services\Report\ARSummaryReport;
use App\Services\Report\UserSalesReport;
use App\Services\Report\TaxSummaryReport;
use App\Export\CSV\RecurringInvoiceExport;
use App\Services\Report\ClientSalesReport;
use App\Services\Report\ClientBalanceReport;

class EmailReport
{
    use MakesHash;
    use MakesDates;

    private Client $client;

    private bool $multiple_clients = false;

    private string $file_name = 'file.csv';

    public function __construct(public Scheduler $scheduler)
    {
    }

    public function run()
    {
        
        $start_end_dates = $this->calculateStartAndEndDates();
        $data = [];

        $data = [
            'start_date' => $start_end_dates[0],
            'end_date' => $start_end_dates[1],
            'date_range' => 'custom',
            'client_id' => null,
            'report_keys' => []
        ];

        if (count($this->scheduler->parameters['clients']) >= 1) {
            $data['clients'] = $this->transformKeys($this->scheduler->parameters['clients']);
        }
        
        $export = false;

        match($this->scheduler->parameters['report_name'])
        {
            'product_sales_report' => $export = (new ProductSalesExport($this->scheduler->company, $data)),
            'email_ar_detailed_report' => $export = (new ARDetailReport($this->scheduler->company, $data)),
            'email_ar_summary_report' => $export = (new ARSummaryReport($this->scheduler->company, $data)),
            'email_tax_summary_report' => $export = (new TaxSummaryReport($this->scheduler->company, $data)),
            'email_client_balance_report' => $export = (new ClientBalanceReport($this->scheduler->company, $data)),
            'email_client_sales_report' => $export = (new ClientSalesReport($this->scheduler->company, $data)),
            'email_user_sales_report' => $export = (new UserSalesReport($this->scheduler->company, $data)),
            'clients' => $export = (new ClientExport($this->scheduler->company, $data)),
            'client_contacts' => $export = (new ContactExport($this->scheduler->company, $data)),
            'credits' => $export = (new CreditExport($this->scheduler->company, $data)),
            'documents' => $export = (new DocumentExport($this->scheduler->company, $data)),
            'expenses' => $export = (new ExpenseExport($this->scheduler->company, $data)),
            'invoices' => $export = (new InvoiceExport($this->scheduler->company, $data)),
            'invoice_items' => $export = (new InvoiceItemExport($this->scheduler->company, $data)),
            'quotes' => $export = (new QuoteExport($this->scheduler->company, $data)),
            'quote_items' => $export = (new QuoteItemExport($this->scheduler->company, $data)),
            'recurring_invoices' => $export = (new RecurringInvoiceExport($this->scheduler->company, $data)),
            'payments' => $export = (new PaymentExport($this->scheduler->company, $data)),
            'products' => $export = (new ProductExport($this->scheduler->company, $data)),
            'tasks' => $export = (new TaskExport($this->scheduler->company, $data)),
            'profitloss' => $export = (new ProfitLoss($this->scheduler->company, $data)),
            default => $export = false,
        };
        
        if(!$export) {
            $this->cancelSchedule();
            return;
        }

        $csv = $export->run();

        //todo - potentially we send this to more than one user.

        $nmo = new NinjaMailerObject;
        $nmo->mailable = new DownloadReport($this->scheduler->company, $csv, $this->file_name);
        $nmo->company = $this->scheduler->company;
        $nmo->settings = $this->scheduler->company->settings;
        $nmo->to_user = $this->scheduler->user;

        NinjaMailerJob::dispatch($nmo);

        //calculate next run dates;
        $this->scheduler->calculateNextRun();
        
    }

    private function cancelSchedule()
    {
        $this->scheduler->forceDelete();
    }
    
   

}
