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

namespace App\Console\Commands;

use App;
use Exception;
use App\Models\User;
use App\Utils\Ninja;
use App\Models\Quote;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Vendor;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\CompanyUser;
use Illuminate\Support\Str;
use App\Models\CompanyToken;
use App\Models\ClientContact;
use App\Models\CompanyLedger;
use App\Models\PurchaseOrder;
use App\Models\VendorContact;
use App\Models\BankTransaction;
use App\Models\QuoteInvitation;
use Illuminate\Console\Command;
use App\Models\CreditInvitation;
use App\Models\InvoiceInvitation;
use App\DataMapper\ClientSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Factory\ClientContactFactory;
use App\Factory\VendorContactFactory;
use App\Jobs\Company\CreateCompanyToken;
use App\Models\RecurringInvoiceInvitation;
use Symfony\Component\Console\Input\InputOption;

/*

##################################################################
WARNING: Please backup your database before running this script
##################################################################

If you have any questions please email us at contact@invoiceninja.com

Usage:

php artisan ninja:check-data

Options:

--client_id:<value>

    Limits the script to a single client

--fix=true

    By default the script only checks for errors, adding this option
    makes the script apply the fixes.

--fast=true

    Skip using phantomjs

*/

/**
 * Class CheckData.
 */
class CheckData extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ninja:check-data {--database=} {--fix=} {--portal_url=} {--client_id=} {--vendor_id=} {--paid_to_date=} {--client_balance=} {--ledger_balance=} {--balance_status=} {--bank_transaction=}';

    /**
     * @var string
     */
    protected $description = 'Check/fix data';

    protected $log = '';

    protected $isValid = true;

    protected $wrong_paid_to_dates = 0;

    protected $wrong_balances = 0;

    protected $wrong_paid_status = 0;


    public function handle()
    {
        $time_start = microtime(true);

        $database_connection = $this->option('database') ? $this->option('database') : 'Connected to Default DB';
        $fix_status = $this->option('fix') ? "Fixing Issues" : "Just checking issues ";

        $this->logMessage(date('Y-m-d h:i:s').' Running CheckData... on ' . $database_connection . " Fix Status = {$fix_status}");

        if ($database = $this->option('database')) {
            config(['database.default' => $database]);
        }

        $this->checkInvoiceBalances();
        $this->checkClientBalanceEdgeCases();
        $this->checkPaidToDatesNew();
        $this->checkContacts();
        $this->checkVendorContacts();
        $this->checkEntityInvitations();
        $this->checkCompanyData();
        $this->checkBalanceVsPaidStatus();
        $this->checkDuplicateRecurringInvoices();
        $this->checkOauthSanity();
        $this->checkVendorSettings();
        $this->checkClientSettings();
        $this->checkCompanyTokens();
        $this->checkUserState();
        
        if (Ninja::isHosted()) {
            $this->checkAccountStatuses();
            $this->checkNinjaPortalUrls();
        }

        if (! $this->option('client_id')) {
            $this->checkOAuth();
        }

        if($this->option('bank_transaction')) {
            $this->fixBankTransactions();
        }

        $this->logMessage('Done: '.strtoupper($this->isValid ? Account::RESULT_SUCCESS : Account::RESULT_FAILURE));
        $this->logMessage('Total execution time in seconds: ' . (microtime(true) - $time_start));

        $errorEmail = config('ninja.error_email');

        if (strlen($errorEmail) > 1) {
            Mail::raw($this->log, function ($message) use ($errorEmail, $database) {
                $message->to($errorEmail)
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->subject('Check-Data: '.strtoupper($this->isValid ? Account::RESULT_SUCCESS : Account::RESULT_FAILURE)." [{$database}]");
            });
        } elseif (! $this->isValid) {
            new Exception("Check data failed!!\n".$this->log);
        }
    }

    private function logMessage($str)
    {
        $str = date('Y-m-d h:i:s').' '.$str;
        $this->info($str);
        $this->log .= $str."\n";
    }

    private function checkCompanyTokens()
    {
        // CompanyUser::whereDoesntHave('token', function ($query){
        //   return $query->where('is_system', 1);
        // })->cursor()->each(function ($cu){
        //     if ($cu->user) {
        //         $this->logMessage("Creating missing company token for user # {$cu->user->id} for company id # {$cu->company->id}");
        //         (new CreateCompanyToken($cu->company, $cu->user, 'System'))->handle();
        //     } else {
        //         $this->logMessage("Dangling User ID # {$cu->id}");
        //     }
        // });

        CompanyUser::query()->cursor()->each(function ($cu) {
            if (CompanyToken::where('user_id', $cu->user_id)->where('company_id', $cu->company_id)->where('is_system', 1)->doesntExist()) {
                $this->logMessage("Creating missing company token for user # {$cu->user_id} for company id # {$cu->company_id}");

                if ($cu->company && $cu->user) {
                    (new CreateCompanyToken($cu->company, $cu->user, 'System'))->handle();
                }
            }
        });
    }
    
    /**
     * checkOauthSanity
     *
     * @return void
     */
    private function checkOauthSanity()
    {
        User::where('oauth_provider_id', '1')->cursor()->each(function ($user) {
            $this->logMessage("Invalid provider ID for user id# {$user->id}");
        });
    }

    private function checkDuplicateRecurringInvoices()
    {
        if (Ninja::isHosted()) {
            $c = Client::on('db-ninja-01')->where('company_id', config('ninja.ninja_default_company_id'))
                ->with('recurring_invoices')
                ->cursor()
                ->each(function ($client) {
                    if ($client->recurring_invoices()->where('is_deleted', 0)->where('deleted_at', null)->count() > 1) {
                        $this->logMessage("Duplicate Recurring Invoice => {$client->custom_value1}");
                    }
                });
        }
    }


    private function checkOAuth()
    {
        // check for duplicate oauth ids
        $users = DB::table('users')
                    ->whereNotNull('oauth_user_id')
                    ->groupBy('users.oauth_user_id')
                    ->havingRaw('count(users.id) > 1')
                    ->get(['users.oauth_user_id']);

        $this->logMessage($users->count().' users with duplicate oauth ids');

        if ($users->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            foreach ($users as $user) {
                $first = true;
                $this->logMessage('checking '.$user->oauth_user_id);
                $matches = DB::table('users')
                            ->where('oauth_user_id', '=', $user->oauth_user_id)
                            ->orderBy('id')
                            ->get(['id']);

                foreach ($matches as $match) {
                    if ($first) {
                        $this->logMessage('skipping '.$match->id);
                        $first = false;
                        continue;
                    }
                    $this->logMessage('updating '.$match->id);

                    DB::table('users')
                        ->where('id', '=', $match->id)
                        ->where('oauth_user_id', '=', $user->oauth_user_id)
                        ->update([
                            'oauth_user_id' => null,
                            'oauth_provider_id' => null,
                        ]);
                }
            }
        }
    }

    private function checkContacts()
    {
        // check for contacts with the contact_key value set
        $contacts = DB::table('client_contacts')
                        ->whereNull('contact_key')
                        ->orderBy('id')
                        ->get(['id']);
        $this->logMessage($contacts->count().' contacts without a contact_key');

        if ($contacts->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            foreach ($contacts as $contact) {
                DB::table('client_contacts')
                    ->where('id', '=', $contact->id)
                    ->whereNull('contact_key')
                    ->update([
                        'contact_key' => Str::random(config('ninja.key_length')),
                    ]);
            }
        }

        // check for missing contacts
        $clients = DB::table('clients')
                    ->leftJoin('client_contacts', function ($join) {
                        $join->on('client_contacts.client_id', '=', 'clients.id')
                            ->whereNull('client_contacts.deleted_at');
                    })
                    ->groupBy('clients.id', 'clients.user_id', 'clients.company_id')
                    ->havingRaw('count(client_contacts.id) = 0');

        if ($this->option('client_id')) {
            $clients->where('clients.id', '=', $this->option('client_id'));
        }

        $clients = $clients->get(['clients.id', 'clients.user_id', 'clients.company_id']);
        $this->logMessage($clients->count().' clients without any contacts');

        if ($clients->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            foreach ($clients as $client) {
                $this->logMessage("Fixing missing contacts #{$client->id}");
                
                $new_contact = ClientContactFactory::create($client->company_id, $client->user_id);
                $new_contact->client_id = $client->id;
                $new_contact->contact_key = Str::random(40);
                $new_contact->is_primary = true;
                $new_contact->save();
            }
        }
    }

    private function checkVendorContacts()
    {
        // check for contacts with the contact_key value set
        $contacts = DB::table('vendor_contacts')
                        ->whereNull('contact_key')
                        ->orderBy('id')
                        ->get(['id']);
        $this->logMessage($contacts->count().' contacts without a contact_key');

        if ($contacts->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            foreach ($contacts as $contact) {
                DB::table('vendor_contacts')
                    ->where('id', '=', $contact->id)
                    ->whereNull('contact_key')
                    ->update([
                        'contact_key' => Str::random(config('ninja.key_length')),
                    ]);
            }
        }

        // check for missing contacts
        $vendors = DB::table('vendors')
                    ->leftJoin('vendor_contacts', function ($join) {
                        $join->on('vendor_contacts.vendor_id', '=', 'vendors.id')
                            ->whereNull('vendor_contacts.deleted_at');
                    })
                    ->groupBy('vendors.id', 'vendors.user_id', 'vendors.company_id')
                    ->havingRaw('count(vendor_contacts.id) = 0');

        if ($this->option('vendor_id')) {
            $vendors->where('vendors.id', '=', $this->option('vendor_id'));
        }

        $vendors = $vendors->get(['vendors.id', 'vendors.user_id', 'vendors.company_id']);
        $this->logMessage($vendors->count().' vendors without any contacts');

        if ($vendors->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            $vendors = Vendor::withTrashed()->doesntHave('contacts')->get();

            foreach ($vendors as $vendor) {
                $this->logMessage("Fixing missing vendor contacts #{$vendor->id}");
                
                $new_contact = VendorContactFactory::create($vendor->company_id, $vendor->user_id);
                $new_contact->vendor_id = $vendor->id;
                $new_contact->contact_key = Str::random(40);
                $new_contact->is_primary = true;
                $new_contact->save();
            }
        }
    }


    private function checkFailedJobs()
    {
        if (config('ninja.testvars.travis')) {
            return;
        }

        $queueDB = config('queue.connections.database.connection');
        $count = DB::connection($queueDB)->table('failed_jobs')->count();

        if ($count > 25) {
            $this->isValid = false;
        }

        $this->logMessage($count.' failed jobs');
    }

    private function checkInvitations()
    {
        $invoices = DB::table('invoices')
                    ->leftJoin('invoice_invitations', function ($join) {
                        $join->on('invoice_invitations.invoice_id', '=', 'invoices.id')
                             ->whereNull('invoice_invitations.deleted_at');
                    })
                    ->groupBy('invoices.id', 'invoices.user_id', 'invoices.company_id', 'invoices.client_id')
                    ->havingRaw('count(invoice_invitations.id) = 0')
                    ->get(['invoices.id', 'invoices.user_id', 'invoices.company_id', 'invoices.client_id']);

        $this->logMessage($invoices->count().' invoices without any invitations');

        if ($invoices->count() > 0) {
            $this->isValid = false;
        }

        if ($this->option('fix') == 'true') {
            foreach ($invoices as $invoice) {
                $invitation = new InvoiceInvitation();
                $invitation->company_id = $invoice->company_id;
                $invitation->user_id = $invoice->user_id;
                $invitation->invoice_id = $invoice->id;
                $invitation->client_contact_id = ClientContact::whereClientId($invoice->client_id)->first()->id;
                $invitation->key = Str::random(config('ninja.key_length'));
                $invitation->save();
            }
        }
    }

    private function checkUserState()
    {
        User::withTrashed()
            ->where('deleted_at', '0000-00-00 00:00:00.000000')
            ->cursor()
            ->each(function ($user) {
                $user->restore();
            });
    }

    private function checkEntityInvitations()
    {
        RecurringInvoiceInvitation::where('deleted_at', "0000-00-00 00:00:00.000000")->withTrashed()->update(['deleted_at' => null]);
        InvoiceInvitation::where('deleted_at', "0000-00-00 00:00:00.000000")->withTrashed()->update(['deleted_at' => null]);
        QuoteInvitation::where('deleted_at', "0000-00-00 00:00:00.000000")->withTrashed()->update(['deleted_at' => null]);
        CreditInvitation::where('deleted_at', "0000-00-00 00:00:00.000000")->withTrashed()->update(['deleted_at' => null]);


        collect([Invoice::class, Quote::class, Credit::class, PurchaseOrder::class])->each(function ($entity) {
            if ($entity::doesntHave('invitations')->count() > 0) {
                $entity::doesntHave('invitations')->cursor()->each(function ($entity) {
                    $client_vendor_key = 'client_id';
                    $contact_id = 'client_contact_id';
                    $contact_class = ClientContact::class;

                    $entity_key = \Illuminate\Support\Str::of(class_basename($entity))->snake()->append('_id')->toString();
                    $entity_obj = get_class($entity).'Invitation';

                    if ($entity instanceof PurchaseOrder) {
                        $client_vendor_key = 'vendor_id';
                        $contact_id = 'vendor_contact_id';
                        $contact_class = VendorContact::class;
                    }

                    $invitation = false;

                    //check contact exists!
                    if ($contact_class::where('company_id', $entity->company_id)->where($client_vendor_key, $entity->{$client_vendor_key})->exists()) {
                        $contact = $contact_class::where('company_id', $entity->company_id)->where($client_vendor_key, $entity->{$client_vendor_key})->first();

                        //double check if an archived invite exists
                        if ($contact && $entity_obj::withTrashed()->where($entity_key, $entity->id)->where($contact_id, $contact->id)->count() != 0) {
                            $i = $entity_obj::withTrashed()->where($entity_key, $entity->id)->where($contact_id, $contact->id)->first();
                            $i->restore();
                            $this->logMessage("Found a valid contact and invitation restoring for {$entity_key} - {$entity->id}");
                        } else {
                            $invitation = new $entity_obj();
                            $invitation->company_id = $entity->company_id;
                            $invitation->user_id = $entity->user_id;
                            $invitation->{$entity_key} = $entity->id;
                            $invitation->{$contact_id} = $contact->id;
                            $invitation->key = Str::random(config('ninja.key_length'));
                            $this->logMessage("Add invitation for {$entity_key} - {$entity->id}");
                        }
                    } else {
                        $this->logMessage("No contact present, so cannot add invitation for {$entity_key} - {$entity->id}");
                    }

                    try {
                        if ($invitation) {
                            $invitation->save();
                        }
                    } catch(\Exception $e) {
                        $this->logMessage($e->getMessage());
                        $invitation = null;
                    }
                });
            }
        });
    }

    private function fixInvitations($entities, $entity)
    {
        $entity_key = "{$entity}_id";

        $entity_obj = 'App\Models\\'.ucfirst(Str::camel($entity)).'Invitation';

        foreach ($entities as $entity) {
            $invitation = new $entity_obj();
            $invitation->company_id = $entity->company_id;
            $invitation->user_id = $entity->user_id;
            $invitation->{$entity_key} = $entity->id;
            $invitation->client_contact_id = ClientContact::whereClientId($entity->client_id)->first()->id;
            $invitation->key = Str::random(config('ninja.key_length'));

            try {
                $invitation->save();
            } catch(\Exception $e) {
                $invitation = null;
            }
        }
    }

    private function clientPaidToDateQuery()
    {
        $results = \DB::select(\DB::raw("
         SELECT 
         clients.id as client_id, 
         clients.paid_to_date as client_paid_to_date,
         SUM(coalesce(payments.amount - payments.refunded,0)) as payments_applied
         FROM clients 
         INNER JOIN
         payments ON 
         clients.id=payments.client_id 
         WHERE payments.status_id IN (1,4,5,6)
         AND clients.is_deleted = false
         AND payments.is_deleted = false
         GROUP BY clients.id
         HAVING payments_applied != client_paid_to_date
         ORDER BY clients.id;
        "));
    
        return $results;
    }

    private function clientCreditPaymentables($client)
    {
        $results = \DB::select(\DB::raw("
        SELECT 
        SUM(paymentables.amount - paymentables.refunded) as credit_payment
        FROM payments
        LEFT JOIN paymentables
        ON
        payments.id = paymentables.payment_id
        WHERE paymentable_type = ?
        AND paymentables.deleted_at is NULL
        AND paymentables.amount > 0
        AND payments.is_deleted = 0
        AND payments.client_id = ?;
        "), [App\Models\Credit::class, $client->id]);
    
        return $results;
    }

    private function checkPaidToDatesNew()
    {
        $clients_to_check = $this->clientPaidToDateQuery();

        $this->wrong_paid_to_dates = 0;
    
        foreach ($clients_to_check as $_client) {
            $client = Client::withTrashed()->find($_client->client_id);

            $credits_from_reversal = Credit::withTrashed()->where('client_id', $client->id)->where('is_deleted', 0)->whereNotNull('invoice_id')->sum('amount');

            $credits_used_for_payments = $this->clientCreditPaymentables($client);

            $total_paid_to_date = $_client->payments_applied + $credits_used_for_payments[0]->credit_payment - $credits_from_reversal;

            if (round($total_paid_to_date, 2) != round($_client->client_paid_to_date, 2)) {
                $this->wrong_paid_to_dates++;

                $this->logMessage($client->present()->name().' id = # '.$client->id." - Client Paid To Date = {$client->paid_to_date} != Invoice Payments = {$total_paid_to_date} - {$_client->payments_applied} + {$credits_used_for_payments[0]->credit_payment}");

                $this->isValid = false;

                if ($this->option('paid_to_date')) {
                    $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." Fixing {$client->paid_to_date} to {$total_paid_to_date}");
                    $client->paid_to_date = $total_paid_to_date;
                    $client->save();
                }
            }
        }

        $this->logMessage("{$this->wrong_paid_to_dates} clients with incorrect paid to dates");
    }

    private function checkPaidToDates()
    {
        $this->wrong_paid_to_dates = 0;
        $credit_total_applied = 0;

        $clients = DB::table('clients')
                    ->leftJoin('payments', function ($join) {
                        $join->on('payments.client_id', '=', 'clients.id')
                            ->where('payments.is_deleted', 0)
                            ->whereIn('payments.status_id', [Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED]);
                    })
                    ->where('clients.is_deleted', 0)
                    ->where('clients.updated_at', '>', now()->subDays(2))
                    ->groupBy('clients.id')
                    ->havingRaw('clients.paid_to_date != sum(coalesce(payments.amount - payments.refunded, 0))')
                    ->get(['clients.id', 'clients.paid_to_date', DB::raw('sum(coalesce(payments.amount - payments.refunded, 0)) as amount')]);

        /* Due to accounting differences we need to perform a second loop here to ensure there actually is an issue */
        $clients->each(function ($client_record) use ($credit_total_applied) {
            $client = Client::withTrashed()->find($client_record->id);

            $total_invoice_payments = 0;

            foreach ($client->invoices()->where('is_deleted', false)->where('status_id', '>', 1)->get() as $invoice) {
                $total_invoice_payments += $invoice->payments()
                                                    ->where('is_deleted', false)->whereIn('status_id', [Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
                                                    ->selectRaw('sum(paymentables.amount - paymentables.refunded) as p')
                                                    ->pluck('p')
                                                    ->first();
            }

            //commented IN 27/06/2021 - sums ALL client payments AND the unapplied amounts to match the client paid to date
            $p = Payment::where('client_id', $client->id)
            ->where('is_deleted', 0)
            ->whereIn('status_id', [Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
            ->sum(DB::Raw('amount - applied'));

            $total_invoice_payments += $p;

            // 10/02/21
            foreach ($client->payments as $payment) {
                $credit_total_applied += $payment->paymentables()
                                                ->where('paymentable_type', App\Models\Credit::class)
                                                ->selectRaw('sum(paymentables.amount - paymentables.refunded) as p')
                                                ->pluck('p')
                                                ->first();
            }

            if ($credit_total_applied < 0) {
                $total_invoice_payments += $credit_total_applied;
            }

            if (round($total_invoice_payments, 2) != round($client->paid_to_date, 2)) {
                $this->wrong_paid_to_dates++;

                $this->logMessage($client->present()->name().' id = # '.$client->id." - Paid to date does not match Client Paid To Date = {$client->paid_to_date} - Invoice Payments = {$total_invoice_payments}");

                $this->isValid = false;

                if ($this->option('paid_to_date')) {
                    $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." Fixing {$client->paid_to_date} to {$total_invoice_payments}");
                    $client->paid_to_date = $total_invoice_payments;
                    $client->save();
                }
            }
        });

        $this->logMessage("{$this->wrong_paid_to_dates} clients with incorrect paid to dates");
    }

    private function checkInvoicePayments()
    {
        $this->wrong_balances = 0;

        Client::cursor()->where('is_deleted', 0)->where('clients.updated_at', '>', now()->subDays(2))->each(function ($client) {
            $client->invoices->where('is_deleted', false)->whereIn('status_id', '!=', Invoice::STATUS_DRAFT)->each(function ($invoice) use ($client) {
                $total_paid = $invoice->payments()
                                    ->where('is_deleted', false)->whereIn('status_id', [Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
                                    ->selectRaw('sum(paymentables.amount - paymentables.refunded) as p')
                                    ->pluck('p')
                                    ->first();

                $total_credit = $invoice->credits()->get()->sum('amount');

                $calculated_paid_amount = $invoice->amount - $invoice->balance - $total_credit;

                if ((string)$total_paid != (string)($invoice->amount - $invoice->balance - $total_credit)) {
                    $this->wrong_balances++;

                    $this->logMessage($client->present()->name().' - '.$client->id." - Total Paid = {$total_paid} != Calculated Total = {$calculated_paid_amount}");

                    $this->isValid = false;
                }
            });
        });

        $this->logMessage("{$this->wrong_balances} clients with incorrect invoice balances");
    }

    private function clientBalanceQuery()
    {
        $results = \DB::select(\DB::raw("
        SELECT         
        SUM(invoices.balance) as invoice_balance, 
        clients.id as client_id, 
        clients.balance as client_balance
        FROM clients
        LEFT JOIN
        invoices ON 
        clients.id=invoices.client_id 
        WHERE invoices.is_deleted = false 
        AND invoices.status_id IN (2,3) 
        GROUP BY clients.id
        HAVING invoice_balance != clients.balance
        ORDER BY clients.id;
        "));
    
        return $results;
    }

    private function checkClientBalances()
    {
        $this->wrong_balances = 0;
        $this->wrong_paid_to_dates = 0;

        $clients = $this->clientBalanceQuery();

        foreach ($clients as $client) {
            $client = (array)$client;
            
            if ((string) $client['invoice_balance'] != (string) $client['client_balance']) {
                $this->wrong_paid_to_dates++;

                $client_object = Client::withTrashed()->find($client['client_id']);

                $this->logMessage($client_object->present()->name().' - '.$client_object->id." - calculated client balances do not match Invoice Balances = ". $client['invoice_balance'] ." - Client Balance = ".rtrim($client['client_balance'], '0'));
 
                if ($this->option('client_balance')) {
                    $this->logMessage("# {$client_object->id} " . $client_object->present()->name().' - '.$client_object->number." Fixing {$client_object->balance} to " . $client['invoice_balance']);
                    $client_object->balance = $client['invoice_balance'];
                    $client_object->save();
                }

                $this->isValid = false;
            }
        }

        $this->logMessage("{$this->wrong_paid_to_dates} clients with incorrect client balances");
    }

    private function checkClientBalanceEdgeCases()
    {
        Client::query()
              ->where('is_deleted', false)
              ->where('balance', '!=', 0)
              ->cursor()
              ->each(function ($client) {
                  $count = Invoice::withTrashed()
                              ->where('client_id', $client->id)
                              ->where('is_deleted', false)
                              ->whereIn('status_id', [2,3])
                              ->count();

                  if ($count == 0) {
                      //factor in over payments to the client balance
                      $over_payment = Payment::where('client_id', $client->id)
                                              ->where('is_deleted', 0)
                                              ->whereIn('status_id', [1,4])
                                              ->selectRaw('sum(amount - applied) as p')
                                              ->pluck('p')
                                              ->first();

                      $over_payment = $over_payment*-1;

                      if (floatval($over_payment) == floatval($client->balance)) {
                      } else {
                          $this->logMessage("# {$client->id} # {$client->name} {$client->balance} is invalid should be {$over_payment}");
                      }


                      if ($this->option('client_balance') && (floatval($over_payment) != floatval($client->balance))) {
                          $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." Fixing {$client->balance} to 0");

                          $client->balance = $over_payment;
                          $client->save();
                      }
                  }
              });
    }

    private function invoiceBalanceQuery()
    {
        $results = \DB::select(\DB::raw("
        SELECT 
        clients.id,
        clients.balance,
        SUM(invoices.balance) as invoices_balance
        FROM clients
        JOIN invoices
        ON invoices.client_id = clients.id
        WHERE invoices.is_deleted = 0
        AND clients.is_deleted = 0
        AND invoices.status_id IN (2,3)
        GROUP BY clients.id
        HAVING(invoices_balance != clients.balance)
        ORDER BY clients.id;
        "));
    
        return $results;
    }

    private function checkInvoiceBalances()
    {
        $this->wrong_balances = 0;
        $this->wrong_paid_to_dates = 0;

        $_clients = $this->invoiceBalanceQuery();

        foreach ($_clients as $_client) {
            $client = Client::withTrashed()->find($_client->id);

            $invoice_balance = $client->invoices()->where('is_deleted', false)->whereIn('status_id', [2,3])->sum('balance');

            $ledger = CompanyLedger::where('client_id', $client->id)->orderBy('id', 'DESC')->first();

            if (number_format($invoice_balance, 4) != number_format($client->balance, 4)) {
                $this->wrong_balances++;
                $ledger_balance = $ledger ? $ledger->balance : 0;

                $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." - Balance Failure - Invoice Balances = {$invoice_balance} Client Balance = {$client->balance} Ledger Balance = {$ledger_balance}");

                $this->isValid = false;

                if ($this->option('client_balance')) {
                    $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." Fixing {$client->balance} to {$invoice_balance}");
                    $client->balance = $invoice_balance;
                    $client->save();
                }

                if ($ledger && (number_format($invoice_balance, 4) != number_format($ledger->balance, 4))) {
                    $ledger->adjustment = $invoice_balance;
                    $ledger->balance = $invoice_balance;
                    $ledger->notes = 'Ledger Adjustment';
                    $ledger->save();
                }
            }
        }

        $this->logMessage("{$this->wrong_balances} clients with incorrect balances");
    }

    private function checkLedgerBalances()
    {
        $this->wrong_balances = 0;
        $this->wrong_paid_to_dates = 0;

        foreach (Client::where('is_deleted', 0)->where('clients.updated_at', '>', now()->subDays(2))->cursor() as $client) {
            $invoice_balance = $client->invoices()->where('is_deleted', false)->whereIn('status_id', [2,3])->sum('balance');
            $ledger = CompanyLedger::where('client_id', $client->id)->orderBy('id', 'DESC')->first();

            if ($ledger && number_format($ledger->balance, 4) != number_format($client->balance, 4)) {
                $this->wrong_balances++;
                $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." - Balance Failure - Client Balance = {$client->balance} Ledger Balance = {$ledger->balance}");

                $this->isValid = false;


                if ($this->option('ledger_balance')) {
                    $this->logMessage("# {$client->id} " . $client->present()->name().' - '.$client->number." Fixing {$client->balance} to {$invoice_balance}");
                    $client->balance = $invoice_balance;
                    $client->save();

                    $ledger->adjustment = $invoice_balance;
                    $ledger->balance = $invoice_balance;
                    $ledger->notes = 'Ledger Adjustment';
                    $ledger->save();
                }
            }
        }

        $this->logMessage("{$this->wrong_balances} clients with incorrect ledger balances");
    }

    private function checkLogoFiles()
    {
    }

    /**
     * @return array
     */
    protected function getArguments()
    {
        return [];
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['fix', null, InputOption::VALUE_OPTIONAL, 'Fix data', null],
            ['fast', null, InputOption::VALUE_OPTIONAL, 'Fast', null],
            ['client_id', null, InputOption::VALUE_OPTIONAL, 'Client id', null],
            ['database', null, InputOption::VALUE_OPTIONAL, 'Database', null],
        ];
    }

    private function checkCompanyData()
    {
        $tables = [
            'activities' => [
                'invoice',
                'client',
                'client_contact',
                'payment',
                'recurring_invoice',
            ],
            'invoices' => [
                'client',
            ],
            'payments' => [
                'client',
            ],
            'products' => [

            ],
        ];

        foreach ($tables as $table => $entityTypes) {
            foreach ($entityTypes as $entityType) {
                $tableName = $this->pluralizeEntityType($entityType);
                $field = $entityType;
                if ($table == 'companies') {
                    $company_id = 'id';
                } else {
                    $company_id = 'company_id';
                }
                $records = DB::table($table)
                                ->join($tableName, "{$tableName}.id", '=', "{$table}.{$field}_id")
                                ->where("{$table}.{$company_id}", '!=', DB::raw("{$tableName}.company_id"))
                                ->get(["{$table}.id"]);

                if ($records->count()) {
                    $this->isValid = false;
                    $this->logMessage($records->count()." {$table} records with incorrect {$entityType} company id");
                }
            }
        }

        // foreach(User::cursor() as $user) {

        //     $records = Company::where('account_id',)

        // }
    }

    public function pluralizeEntityType($type)
    {
        if ($type === 'company') {
            return 'companies';
        }

        return $type.'s';
    }

    public function checkAccountStatuses()
    {
        Account::where('plan_expires', '<=', now()->subDays(2))->cursor()->each(function ($account) {
            $client = Client::on('db-ninja-01')->where('company_id', config('ninja.ninja_default_company_id'))->where('custom_value2', $account->key)->first();
              
            if ($client) {
                $payment = Payment::on('db-ninja-01')
                              ->where('company_id', config('ninja.ninja_default_company_id'))
                              ->where('client_id', $client->id)
                              ->where('date', '>=', now()->subDays(2))
                              ->exists();
              
                if ($payment) {
                    $this->logMessage("I found a payment for {$account->key}");
                }
            }
        });
    }


    public function checkClientSettings()
    {
        if ($this->option('fix') == 'true') {
            // Client::query()->whereNull('settings->currency_id')->cursor()->each(function ($client){

            //     if(is_array($client->settings) && count($client->settings) == 0)
            //     {
            //         $settings = ClientSettings::defaults();
            //         $settings->currency_id = $client->company->settings->currency_id;
            //     }
            //     else {
            //         $settings = $client->settings;
            //         $settings->currency_id = $client->company->settings->currency_id;
            //     }

            //     $client->settings = $settings;
            //     $client->save();

            //     $this->logMessage("Fixing currency for # {$client->id}");

            // });


            Client::query()->whereNull('country_id')->cursor()->each(function ($client) {
                $client->country_id = $client->company->settings->country_id;
                $client->save();

                $this->logMessage("Fixing country for # {$client->id}");
            });
        }
    }

    public function checkVendorSettings()
    {
        if ($this->option('fix') == 'true') {
            Vendor::query()->whereNull('currency_id')->orWhere('currency_id', '')->cursor()->each(function ($vendor) {
                $vendor->currency_id = $vendor->company->settings->currency_id;
                $vendor->save();

                $this->logMessage("Fixing vendor currency for # {$vendor->id}");
            });
        }
    }



    public function checkBalanceVsPaidStatus()
    {
        $this->wrong_paid_status = 0;

        foreach (Invoice::with(['payments'])->where('is_deleted', 0)->where('balance', '>', 0)->whereHas('payments')->where('status_id', 4)->cursor() as $invoice) {
            $this->wrong_paid_status++;
            
            $this->logMessage("# {$invoice->id} " . ' - '.$invoice->number." - Marked as paid, but balance = {$invoice->balance}");

            if ($this->option('balance_status')) {
                $val = $invoice->balance;

                $invoice->balance = 0;
                $invoice->paid_to_date=$val;
                $invoice->save();

                $p = $invoice->payments->first();

                if ($p && (int)$p->amount == 0) {
                    $p->amount = $val;
                    $p->applied = $val;
                    $p->save();

                    $pivot = $p->paymentables->first();
                    $pivot->amount = $val;
                    $pivot->save();
                }


                $this->logMessage("Fixing {$invoice->id} settings payment to {$val}");
            }
        }

        $this->logMessage($this->wrong_paid_status." wrong invoices with bad balance state");
    }

    public function checkNinjaPortalUrls()
    {
        $wrong_count = CompanyUser::where('is_owner', 1)->where('ninja_portal_url', '')->count();

        $this->logMessage("Missing ninja portal Urls = {$wrong_count}");

        if (!$this->option('portal_url')) {
            return;
        }

        CompanyUser::where('is_owner', 1)->where('ninja_portal_url', '')->cursor()->each(function ($cu) {
            $cc = ClientContact::on('db-ninja-01')->where('company_id', config('ninja.ninja_default_company_id'))->where('email', $cu->user->email)->first();

            if ($cc) {
                $ninja_portal_url = "https://invoiceninja.invoicing.co/client/ninja/{$cc->contact_key}/{$cu->account->key}";

                $cu->ninja_portal_url = $ninja_portal_url;
                $cu->save();

                $this->logMessage("Fixing - {$ninja_portal_url}");
            } else {
                $c =  Client::on('db-ninja-01')->where("company_id", config('ninja.ninja_default_company_id'))->where('custom_value2', $cu->account->key)->first();

                if ($c) {
                    $cc = $c->contacts()->first();
                      
                    if ($cc) {
                        $ninja_portal_url = "https://invoiceninja.invoicing.co/client/ninja/{$cc->contact_key}/{$cu->account->key}";

                        $cu->ninja_portal_url = $ninja_portal_url;
                        $cu->save();

                        $this->logMessage("Fixing - {$ninja_portal_url}");
                    }
                }
            }
        });
    }

    public function fixBankTransactions()
    {
        $this->logMessage("checking bank transactions");

        BankTransaction::with('payment')->withTrashed()->where('invoice_ids', ',,,,,,,,')->cursor()->each(function ($bt){

            if($bt->payment->exists()) {

                $bt->invoice_ids = collect($bt->payment->invoices)->pluck('hashed_id')->implode(',');
                $bt->save();

                $this->logMessage("Fixing - {$bt->id}");
                
            }

        });

    }
}
