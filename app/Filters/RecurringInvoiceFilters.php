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

namespace App\Filters;

use App\Models\RecurringInvoice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * RecurringInvoiceFilters.
 */
class RecurringInvoiceFilters extends QueryFilters
{
    /**
     * Filter based on search text.
     *
     * @param string $filter
     * @return Builder
     * @deprecated
     */
    public function filter(string $filter = ''): Builder
    {
        if (strlen($filter) == 0) {
            return $this->builder;
        }

        return  $this->builder->where(function ($query) use ($filter) {
            $query->where('recurring_invoices.custom_value1', 'like', '%'.$filter.'%')
                          ->orWhere('recurring_invoices.custom_value2', 'like', '%'.$filter.'%')
                          ->orWhere('recurring_invoices.custom_value3', 'like', '%'.$filter.'%')
                          ->orWhere('recurring_invoices.custom_value4', 'like', '%'.$filter.'%');
        });
    }

    /**
     * Filter based on client status.
     *
     * Statuses we need to handle
     * - all
     * - active
     * - paused
     * - completed
     *
     * @param string $value The invoice status as seen by the client
     * @return Builder
     */
    public function client_status(string $value = ''): Builder
    {
        if (strlen($value) == 0) {
            return $this->builder;
        }

        $status_parameters = explode(',', $value);

        if (in_array('all', $status_parameters)) {
            return $this->builder;
        }

        $recurring_filters = [];

        if (in_array('active', $status_parameters)) {
            $recurring_filters[] = RecurringInvoice::STATUS_ACTIVE;
        }


        if (in_array('paused', $status_parameters)) {
            $recurring_filters[] = RecurringInvoice::STATUS_PAUSED;
        }

        if (in_array('completed', $status_parameters)) {
            $recurring_filters[] = RecurringInvoice::STATUS_COMPLETED;
        }

        if (count($recurring_filters) >= 1) {
            return $this->builder->whereIn('status_id', $recurring_filters);
        }

        return $this->builder;
    }

    public function number(string $number = ''): Builder
    {
        if (strlen($number) == 0) {
            return $this->builder;
        }

        return $this->builder->where('number', $number);
    }

    /**
     * Sorts the list based on $sort.
     *
     * @param string $sort formatted as column|asc
     * @return Builder
     */
    public function sort(string $sort = ''): Builder
    {
        $sort_col = explode('|', $sort);

        if (!is_array($sort_col) || count($sort_col) != 2) {
            return $this->builder;
        }

        return $this->builder->orderBy($sort_col[0], $sort_col[1]);
    }

    /**
     * Filters the query by the users company ID.
     *
     * @return Builder
     */
    public function entityFilter(): Builder
    {
        return $this->builder->company();
    }

    /**
     * Filter based on line_items product_key
     *
     * @param string $value Product keys
     * @return Builder
     */
    public function product_key(string $value = ''): Builder
    {
        if (strlen($value) == 0) {
            return $this->builder;
        }

        $key_parameters = explode(',', $value);

        if (count($key_parameters)) {
            return $this->builder->where(function ($query) use ($key_parameters) {
                foreach ($key_parameters as $key) {
                    $query->orWhereJsonContains('line_items', ['product_key' => $key]);
                }
            });
        }

        return $this->builder;
    }

    /**
     * next send date between.
     *
     * @param string $range
     * @return Builder
     */
    public function next_send_between(string $range = ''): Builder
    {
        $parts = explode('|', $range);

        if (!isset($parts[0]) || !isset($parts[1])) {
            return $this->builder;
        }

        if (is_numeric($parts[0])) {
            $startDate = Carbon::createFromTimestamp((int)$parts[0]);
        } else {
            $startDate = Carbon::parse($parts[0]);
        }

        if (is_numeric($parts[1])) {
            $endDate = Carbon::createFromTimestamp((int)$parts[1]);
        } else {
            $endDate = Carbon::parse($parts[1]);
        }

        if (!$startDate || !$endDate) {
            return $this->builder;
        }

        return $this->builder->whereBetween(
            'next_send_date',
            [$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Filter by frequency id.
     *
     * @param string $value
     * @return Builder
     */
    public function frequency_id(string $value = ''): Builder
    {
        if (strlen($value) == 0) {
            return $this->builder;
        }

        $frequencyIds = explode(',', $value);

        return $this->builder->whereIn('frequency_id', $frequencyIds);
    }
}
