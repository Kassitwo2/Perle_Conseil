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

namespace App\Jobs\Util;

use App\DataMapper\InvoiceItem;
use App\Factory\InvoiceFactory;
use App\Jobs\Entity\EmailEntity;
use App\Jobs\Ninja\TransactionLog;
use App\Libraries\MultiDB;
use App\Models\Invoice;
use App\Models\TransactionEvent;
use App\Utils\Ninja;
use App\Utils\Traits\MakesDates;
use App\Utils\Traits\MakesReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

class ReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use MakesReminders;
    use MakesDates;

    public $tries = 1;

    public function __construct()
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        set_time_limit(0);

        if (! config('ninja.db.multi_db_enabled')) {
            nlog("Sending invoice reminders on ".now()->format('Y-m-d h:i:s'));

            Invoice::query()
                 ->where('is_deleted', 0)
                 ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
                 ->whereNull('deleted_at')
                 ->where('balance', '>', 0)
                 ->where('next_send_date', '<=', now()->toDateTimeString())
                 ->whereHas('client', function ($query) {
                     $query->where('is_deleted', 0)
                           ->where('deleted_at', null);
                 })
                 ->whereHas('company', function ($query) {
                     $query->where('is_disabled', 0);
                 })
                 ->with('invitations')->chunk(50, function ($invoices) {
                     foreach ($invoices as $invoice) {
                         $this->sendReminderForInvoice($invoice);
                     }

                     sleep(2);
                 });
        } else {
            //multiDB environment, need to

            foreach (MultiDB::$dbs as $db) {
                MultiDB::setDB($db);

                nlog("Sending invoice reminders on db {$db} ".now()->format('Y-m-d h:i:s'));

                Invoice::query()
                     ->where('is_deleted', 0)
                     ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
                     ->whereNull('deleted_at')
                     ->where('balance', '>', 0)
                     ->where('next_send_date', '<=', now()->toDateTimeString())
                     ->whereHas('client', function ($query) {
                         $query->where('is_deleted', 0)
                               ->where('deleted_at', null);
                     })
                     ->whereHas('company', function ($query) {
                         $query->where('is_disabled', 0);
                     })
                     ->with('invitations')->chunk(50, function ($invoices) {
                         // if ($invoice->refresh() && $invoice->isPayable()) {

                         foreach ($invoices as $invoice) {
                             $this->sendReminderForInvoice($invoice);
                         }

                         sleep(2);
                     });
            }
        }
    }

    private function sendReminderForInvoice(Invoice $invoice)
    {
        App::forgetInstance('translator');
        $t = app('translator');
        $t->replace(Ninja::transformTranslations($invoice->client->getMergedSettings()));
        App::setLocale($invoice->client->locale());

        if ($invoice->isPayable()) {
            //Attempts to prevent duplicates from sending
            if ($invoice->reminder_last_sent && Carbon::parse($invoice->reminder_last_sent)->startOfDay()->eq(now()->startOfDay())) {
                nlog("caught a duplicate reminder for invoice {$invoice->number}");
                return;
            }

            $reminder_template = $invoice->calculateTemplate('invoice');
            nlog("reminder template = {$reminder_template}");
            $invoice->service()->touchReminder($reminder_template)->save();
            $fees = $this->calcLateFee($invoice, $reminder_template);

            if(in_array($invoice->client->getSetting('lock_invoices'), ['when_sent','when_paid'])) {
                return $this->addFeeToNewInvoice($invoice, $reminder_template, $fees);
            }
            else
                $invoice = $this->setLateFee($invoice, $fees[0], $fees[1]);


            //20-04-2022 fixes for endless reminders - generic template naming was wrong
            $enabled_reminder = 'enable_'.$reminder_template;
            if ($reminder_template == 'endless_reminder') {
                $enabled_reminder = 'enable_reminder_endless';
            }

            if (in_array($reminder_template, ['reminder1', 'reminder2', 'reminder3', 'reminder_endless', 'endless_reminder']) &&
        $invoice->client->getSetting($enabled_reminder) &&
        $invoice->client->getSetting('send_reminders') &&
        (Ninja::isSelfHost() || $invoice->company->account->isPaidHostedClient())) {
                $invoice->invitations->each(function ($invitation) use ($invoice, $reminder_template) {
                    if ($invitation->contact && !$invitation->contact->trashed() && $invitation->contact->email) {
                        EmailEntity::dispatch($invitation, $invitation->company, $reminder_template);
                        nlog("Firing reminder email for invoice {$invoice->number} - {$reminder_template}");
                        $invoice->entityEmailEvent($invitation, $reminder_template);
                    }
                });
            }
            $invoice->service()->setReminder()->save();
        } else {
            $invoice->next_send_date = null;
            $invoice->save();
        }
    }

    private function addFeeToNewInvoice(Invoice $over_due_invoice, string $reminder_template, array $fees): void
    {

        $amount = $fees[0];
        $percent = $fees[1];

        $temp_invoice_balance = $over_due_invoice->balance;

        if ($amount <= 0 && $percent <= 0) {
            return;
        }

        $fee = $amount;

        if ($over_due_invoice->partial > 0) {
            $fee += round($over_due_invoice->partial * $percent / 100, 2);
        } else {
            $fee += round($over_due_invoice->balance * $percent / 100, 2);
        }

        /** @var \App\Models\Invoice $invoice */
        $invoice = InvoiceFactory::create($over_due_invoice->company_id, $over_due_invoice->user_id);
        $invoice->client_id = $over_due_invoice->client_id;
        $invoice->date = now()->format('Y-m-d');
        $invoice->due_date = now()->format('Y-m-d');
                
        $invoice_item = new InvoiceItem();
        $invoice_item->type_id = '5';
        $invoice_item->product_key = trans('texts.fee');
        $invoice_item->notes = ctrans('texts.late_fee_added_locked_invoice', ['invoice' => $over_due_invoice->number, 'date' => $this->translateDate(now()->startOfDay(), $over_due_invoice->client->date_format(), $over_due_invoice->client->locale())]);
        $invoice_item->quantity = 1;
        $invoice_item->cost = $fee;

        $invoice_items = [];
        $invoice_items[] = $invoice_item;

        $invoice->line_items = $invoice_items;

        /**Refresh Invoice values*/
        $invoice = $invoice->calc()->getInvoice();
        $invoice->service()
                ->createInvitations()
                ->applyNumber()
                ->markSent()
                ->save();
        
        $invoice->service()->touchPdf(true);

        $enabled_reminder = 'enable_'.$reminder_template;
        if ($reminder_template == 'endless_reminder') {
            $enabled_reminder = 'enable_reminder_endless';
        }

        if (in_array($reminder_template, ['reminder1', 'reminder2', 'reminder3', 'reminder_endless', 'endless_reminder']) &&
                $invoice->client->getSetting($enabled_reminder) &&
                $invoice->client->getSetting('send_reminders') &&
                (Ninja::isSelfHost() || $invoice->company->account->isPaidHostedClient())) {
            $invoice->invitations->each(function ($invitation) use ($invoice, $reminder_template) {
                if ($invitation->contact && !$invitation->contact->trashed() && $invitation->contact->email) {
                    EmailEntity::dispatch($invitation, $invitation->company, $reminder_template);
                    nlog("Firing reminder email for invoice {$invoice->number} - {$reminder_template}");
                    $invoice->entityEmailEvent($invitation, $reminder_template);
                }
            });
        }

        $invoice->service()->setReminder()->save();

    }

    /**
     * Calculates the late if - if any - and rebuilds the invoice
     *
     * @param  Invoice $invoice
     * @param  string $template
     * @return array
     */
    private function calcLateFee($invoice, $template): array
    {
        $late_fee_amount = 0;
        $late_fee_percent = 0;

        switch ($template) {
            case 'reminder1':
                $late_fee_amount = $invoice->client->getSetting('late_fee_amount1');
                $late_fee_percent = $invoice->client->getSetting('late_fee_percent1');
                break;
            case 'reminder2':
                $late_fee_amount = $invoice->client->getSetting('late_fee_amount2');
                $late_fee_percent = $invoice->client->getSetting('late_fee_percent2');
                break;
            case 'reminder3':
                $late_fee_amount = $invoice->client->getSetting('late_fee_amount3');
                $late_fee_percent = $invoice->client->getSetting('late_fee_percent3');
                break;
            case 'endless_reminder':
                $late_fee_amount = $invoice->client->getSetting('late_fee_endless_amount');
                $late_fee_percent = $invoice->client->getSetting('late_fee_endless_percent');
                break;
            default:
                $late_fee_amount = 0;
                $late_fee_percent = 0;
                break;
        }

        return [$late_fee_amount, $late_fee_percent];
        // return $this->setLateFee($invoice, $late_fee_amount, $late_fee_percent);
    }

    /**
     * Applies the late fee to the invoice line items
     *
     * @param Invoice $invoice
     * @param float $amount  The fee amount
     * @param float $percent The fee percentage amount
     *
     * @return Invoice
     */
    private function setLateFee($invoice, $amount, $percent): Invoice
    {

        $temp_invoice_balance = $invoice->balance;

        if ($amount <= 0 && $percent <= 0) {
            return $invoice;
        }

        $fee = $amount;

        if ($invoice->partial > 0) {
            $fee += round($invoice->partial * $percent / 100, 2);
        } else {
            $fee += round($invoice->balance * $percent / 100, 2);
        }

        $invoice_item = new InvoiceItem();
        $invoice_item->type_id = '5';
        $invoice_item->product_key = trans('texts.fee');
        $invoice_item->notes = ctrans('texts.late_fee_added', ['date' => $this->translateDate(now()->startOfDay(), $invoice->client->date_format(), $invoice->client->locale())]);
        $invoice_item->quantity = 1;
        $invoice_item->cost = $fee;

        $invoice_items = $invoice->line_items;
        $invoice_items[] = $invoice_item;

        $invoice->line_items = $invoice_items;

        /**Refresh Invoice values*/
        $invoice = $invoice->calc()->getInvoice();
        // $invoice->service()->deletePdf(); 24-11-2022 no need to delete here because we regenerate later anyway

        nlog('adjusting client balance and invoice balance by #'.$invoice->number.' '.($invoice->balance - $temp_invoice_balance));
        $invoice->client->service()->updateBalance($invoice->balance - $temp_invoice_balance);
        $invoice->ledger()->updateInvoiceBalance($invoice->balance - $temp_invoice_balance, "Late Fee Adjustment for invoice {$invoice->number}");

        $invoice->service()->touchPdf(true);

        return $invoice;
    }
}
