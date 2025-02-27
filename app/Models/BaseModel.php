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

namespace App\Models;

use App\Jobs\Util\WebhookHandler;
use App\Models\Traits\Excludable;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\UserSessionAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException as ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Class BaseModel
 *
 * @method scope() static
 * @package App\Models
 * @property-read mixed $hashed_id
 * @property string $number
 * @property int $company_id
 * @property \App\Models\Company $company
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel|Illuminate\Database\Eloquent\Relations\BelongsTo|\Awobaz\Compoships\Database\Eloquent\Relations\BelongsTo|\App\Models\Company company()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel exclude($columns)
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel query()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel exclude(array $excludeable)
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel scopeExclude()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel find() 
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel whereIn()
 * @method static \Illuminate\Database\Eloquent\Builder|BankIntegration where()
 * @method \App\Models\Company company()
 * @method int companyId()
 * @method Builder|static exclude($columns)
 * @method static \Illuminate\Database\Eloquent\Builder exclude(array $columns)
 * @mixin \Eloquent
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class BaseModel extends Model
{
    use MakesHash;
    use UserSessionAttributes;
    use HasFactory;
    use Excludable;

    protected $appends = [
        'hashed_id',
    ];

    protected $casts = [
        'updated_at' => 'timestamp',
        'created_at' => 'timestamp',
        'deleted_at' => 'timestamp',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getHashedIdAttribute()
    {
        return $this->encodePrimaryKey($this->id);
    }

    public function dateMutator($value)
    {
        if (! empty($value)) {
            return (new Carbon($value))->format('Y-m-d');
        }

        return $value;
    }

    public function __call($method, $params)
    {
        $entity = strtolower(class_basename($this));

        if ($entity) {
            $configPath = "modules.relations.$entity.$method";

            if (config()->has($configPath)) {
                $function = config()->get($configPath);

                return call_user_func_array([$this, $function[0]], $function[1]);
            }
        }

        return parent::__call($method, $params);
    }

    /*
    V2 type of scope
     */
    public function scopeCompany($query)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $query->where('company_id', $user->companyId());

        return $query;
    }

    /*
     V1 type of scope
     */
    public function scopeScope($query)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $query->where($this->getTable().'.company_id', '=', $user->company()->id);

        return $query;
    }

    /**
     * Gets the settings by key.
     *
     * When we need to update a setting value, we need to harvest
     * the object of the setting. This is not possible when using the merged settings
     * as we do not know which object the setting has come from.
     *
     * The following method will return the entire object of the property searched for
     * where a value exists for $key.
     *
     * This object can then be mutated by the handling class,
     * to persist the new settings we will also need to pass back a
     * reference to the parent class.
     *
     * @param $key The key of property
     * @deprecated
     */
    // public function getSettingsByKey($key)
    // {
    //     /* Does Setting Exist @ client level */
    //     if (isset($this->getSettings()->{$key})) {
    //         return $this->getSettings()->{$key};
    //     } else {
    //         return (new CompanySettings($this->company->settings))->{$key};
    //     }
    // }

    // public function setSettingsByEntity($entity, $settings)
    // {
    //     switch ($entity) {
    //         case Client::class:

    //             $this->settings = $settings;
    //             $this->save();
    //             $this->fresh();
    //             break;
    //         case Company::class:

    //             $this->company->settings = $settings;
    //             $this->company->save();
    //             break;
    //             //todo check that saving any other entity (Invoice:: RecurringInvoice::) settings is valid using the default:
    //         default:
    //             $this->client->settings = $settings;
    //             $this->client->save();
    //             break;
    //     }
    // }

    /**
     * Gets the settings.
     *
     * Generic getter for client settings
     *
     * @return ClientSettings.
     */
    // public function getSettings()
    // {
    //     return new ClientSettings($this->settings);
    // }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param null $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            throw new ModelNotFoundException("Record with value {$value} not found");
        }

        return $this
            ->withTrashed()
            ->where('id', $this->decodePrimaryKey($value))->firstOrFail();
    }

    /**
     * @param string $extension
     * @return string
     */
    public function getFileName($extension = 'pdf')
    {
        return $this->numberFormatter().'.'.$extension;
    }

    public function numberFormatter()
    {
        $number = strlen($this->number) >= 1 ? $this->translate_entity() . "_" . $this->number : class_basename($this) . "_" . Str::random(5);

        $formatted_number =  mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $number);

        $formatted_number = mb_ereg_replace("([\.]{2,})", '', $formatted_number);

        $formatted_number = preg_replace('/\s+/', '_', $formatted_number);

        return $formatted_number;
    }

    public function translate_entity()
    {
        return ctrans('texts.item');
    }

    /**
     * Model helper to send events for webhooks
     *
     * @param  int    $event_id
     * @param  string $additional_data optional includes
     *
     * @return void
     */
    public function sendEvent(int $event_id, string $additional_data = ""): void
    {
        $subscriptions = Webhook::where('company_id', $this->company_id)
                                 ->where('event_id', $event_id)
                                 ->exists();
                            
        if ($subscriptions) {
            WebhookHandler::dispatch($event_id, $this, $this->company, $additional_data);
        }
    }
}
