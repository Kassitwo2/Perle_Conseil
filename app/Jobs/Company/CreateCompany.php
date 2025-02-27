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

namespace App\Jobs\Company;

use App\Utils\Ninja;
use App\Models\Company;
use App\Libraries\MultiDB;
use App\Utils\Traits\MakesHash;
use App\DataMapper\Tax\TaxModel;
use App\DataMapper\CompanySettings;
use Illuminate\Foundation\Bus\Dispatchable;
use App\DataMapper\ClientRegistrationFields;

class CreateCompany
{
    use MakesHash;
    use Dispatchable;

    protected $request;

    protected $account;

    /**
     * Create a new job instance.
     *
     * @param array $request
     * @param $account
     */
    public function __construct(array $request, $account)
    {
        $this->request = $request;

        $this->account = $account;
    }

    /**
     * Execute the job.
     *
     * @return Company|null
     */
    public function handle() : ?Company
    {
        $settings = CompanySettings::defaults();

        $settings->name = isset($this->request['name']) ? $this->request['name'] : '';

        $company = new Company();
        $company->account_id = $this->account->id;
        $company->company_key = $this->createHash();
        $company->ip = request()->ip();
        $company->settings = $settings;
        $company->db = config('database.default');
        $company->enabled_modules = config('ninja.enabled_modules');
        $company->subdomain = isset($this->request['subdomain']) ? $this->request['subdomain'] : '';
        $company->custom_fields = new \stdClass;
        $company->default_password_timeout = 1800000;
        $company->client_registration_fields = ClientRegistrationFields::generate();
        $company->markdown_email_enabled = true;
        $company->markdown_enabled = false;
        $company->tax_data = new TaxModel();

        if (Ninja::isHosted()) {
            $company->subdomain = MultiDB::randomSubdomainGenerator();
        } else {
            $company->subdomain = '';
        }

        $company->save();

        return $company;
    }
}
