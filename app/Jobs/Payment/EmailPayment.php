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

namespace App\Jobs\Payment;

use App\Events\Payment\PaymentWasEmailed;
use App\Jobs\Mail\NinjaMailerJob;
use App\Jobs\Mail\NinjaMailerObject;
use App\Libraries\MultiDB;
use App\Mail\Engine\PaymentEmailEngine;
use App\Mail\TemplateEmail;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Payment;
use App\Utils\Ninja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmailPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payment;

    public $email_builder;

    private $contact;

    private $company;

    public $settings;

    /**
     * Create a new job instance.
     *
     * @param Payment $payment
     * @param $email_builder
     * @param $contact
     * @param $company
     */
    public function __construct(Payment $payment, Company $company, ?ClientContact $contact)
    {
        $this->payment = $payment;
        $this->contact = $contact;
        $this->company = $company;
        $this->settings = $payment->client->getMergedSettings();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->company->is_disabled) {
            return true;
        }

        if ($this->contact->email) {
            MultiDB::setDb($this->company->db);

            $this->payment->load('invoices');

            if (!$this->contact) {
                $this->contact = $this->payment->client->contacts()->first();
            }
                
            $this->contact->load('client');

            $email_builder = (new PaymentEmailEngine($this->payment, $this->contact))->build();

            $invitation = null;

            if ($this->payment->invoices && $this->payment->invoices->count() >= 1) {
                $invitation = $this->payment->invoices->first()->invitations()->first();
            }

            $nmo = new NinjaMailerObject;
            $nmo->mailable = new TemplateEmail($email_builder, $this->contact, $invitation);
            $nmo->to_user = $this->contact;
            $nmo->settings = $this->settings;
            $nmo->company = $this->company;
            $nmo->entity = $this->payment;

            (new NinjaMailerJob($nmo))->handle();

            event(new PaymentWasEmailed($this->payment, $this->payment->company, Ninja::eventVars(auth()->user() ? auth()->user()->id : null)));
        }
    }
}
