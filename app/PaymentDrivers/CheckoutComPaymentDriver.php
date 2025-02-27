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

namespace App\PaymentDrivers;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Http\Requests\Gateways\Checkout3ds\Checkout3dsRequest;
use App\Http\Requests\Payments\PaymentWebhookRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\ClientGatewayToken;
use App\Models\Company;
use App\Models\GatewayType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\CheckoutCom\CreditCard;
use App\PaymentDrivers\CheckoutCom\Utilities;
use App\Utils\Traits\SystemLogTrait;
use Checkout\CheckoutApi;
use Checkout\CheckoutApiException;
use Checkout\CheckoutArgumentException;
use Checkout\CheckoutAuthorizationException;
use Checkout\CheckoutDefaultSdk;
use Checkout\CheckoutFourSdk;
use Checkout\Common\Phone;
use Checkout\Customers\CustomerRequest;
use Checkout\Customers\Four\CustomerRequest as FourCustomerRequest;
use Checkout\Environment;
use Checkout\Models\Payments\Refund;
use Checkout\Payments\Four\Request\PaymentRequest;
use Checkout\Payments\Four\Request\Source\RequestIdSource as SourceRequestIdSource;
use Checkout\Payments\PaymentRequest as PaymentsPaymentRequest;
use Checkout\Payments\RefundRequest;
use Checkout\Payments\Source\RequestIdSource;
use Exception;
use Illuminate\Support\Facades\Auth;

class CheckoutComPaymentDriver extends BaseDriver
{
    use SystemLogTrait, Utilities;

    /* The company gateway instance*/
    public $company_gateway;

    /* The Invitation */
    public $invitation;

    /* Gateway capabilities */
    public $refundable = true;

    /* Token billing */
    public $token_billing = true;

    /* Authorise payment methods */
    public $can_authorise_credit_card = true;

    public $is_four_api = false;

    /**
     * @var CheckoutApi;
     */
    public $gateway;

    /**
     * @var
     */
    public $payment_method; //the gateway type id

    public static $methods = [
        GatewayType::CREDIT_CARD => CreditCard::class,
    ];

    const SYSTEM_LOG_TYPE = SystemLog::TYPE_CHECKOUT;

    /**
     * Returns the default gateway type.
     */
    public function gatewayTypes(): array
    {
        $types = [];

        $types[] = GatewayType::CREDIT_CARD;

        return $types;
    }

    /**
     * Since with Checkout.com we handle only credit cards, this method should be empty.
     * @param int|null $payment_method
     * @return CheckoutComPaymentDriver
     */
    public function setPaymentMethod($payment_method = null): self
    {
        // At the moment Checkout.com payment
        // driver only supports payments using credit card.

        $class = self::$methods[GatewayType::CREDIT_CARD];

        $this->payment_method = new $class($this);

        return $this;
    }

    /**
     * Initialize the checkout payment driver
     * @return $this
     */
    public function init()
    {
        $config = [
            'secret' => $this->company_gateway->getConfigField('secretApiKey'),
            'public' => $this->company_gateway->getConfigField('publicApiKey'),
            'sandbox' => $this->company_gateway->getConfigField('testMode'),
        ];

        if (strlen($config['secret']) <= 38) {
            $this->is_four_api = true;
            $builder = CheckoutFourSdk::staticKeys();
            $builder->setPublicKey($config['public']); // optional, only required for operations related with tokens
            $builder->setSecretKey($config['secret']);
            $builder->setEnvironment($config['sandbox'] ? Environment::sandbox() : Environment::production());
            $this->gateway = $builder->build();
        } else {
            $builder = CheckoutDefaultSdk::staticKeys();
            $builder->setPublicKey($config['public']); // optional, only required for operations related with tokens
            $builder->setSecretKey($config['secret']);
            $builder->setEnvironment($config['sandbox'] ? Environment::sandbox() : Environment::production());
            $this->gateway = $builder->build();
        }

        return $this;
    }

    /**
     * Process different view depending on payment type
     * @param int $gateway_type_id The gateway type
     * @return string                       The view string
     */
    public function viewForType($gateway_type_id)
    {
        return 'gateways.checkout.credit_card.pay';
    }

    public function authorizeView($data)
    {
        return $this->payment_method->authorizeView($data);
    }

    public function authorizeResponse($data)
    {
        return $this->payment_method->authorizeResponse($data);
    }

    /**
     * Payment View
     *
     * @param array $data Payment data array
     * @return view         The payment view
     */
    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    /**
     * Process the payment response
     *
     * @param Request $request The payment request
     * @return view             The payment response view
     */
    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    public function storePaymentMethod(array $data)
    {
        return $this->storeGatewayToken($data);
    }

    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        $this->init();

        $request = new RefundRequest();
        $request->reference = "{$payment->transaction_reference} ".now();
        $request->amount = $this->convertToCheckoutAmount($amount, $this->client->getCurrencyCode());

        try {
            // or, refundPayment("payment_id") for a full refund
            $response = $this->gateway->getPaymentsClient()->refundPayment($payment->transaction_reference, $request);

            return [
                'transaction_reference' => $response['action_id'],
                'transaction_response' => json_encode($response),
                'success' => true,
                'description' => $response['reference'],
                'code' => 202,
            ];
        } catch (CheckoutApiException $e) {
            // API error
            throw new PaymentFailed($e->getMessage(), $e->getCode());
        } catch (CheckoutArgumentException $e) {
            // Bad arguments

            throw new PaymentFailed($e->getMessage(), $e->getCode());

            return [
                'transaction_reference' => null,
                'transaction_response' => json_encode($e->getMessage()),
                'success' => false,
                'description' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
        } catch (CheckoutAuthorizationException $e) {
            // Bad Invalid authorization

            throw new PaymentFailed($e->getMessage(), $e->getCode());

            return [
                'transaction_reference' => null,
                'transaction_response' => json_encode($e->getMessage()),
                'success' => false,
                'description' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
        }
    }

    public function getCustomer()
    {
        try {
            $response = $this->gateway->getCustomersClient()->get($this->client->present()->email());

            return $response;
        } catch (\Exception $e) {
            if ($this->is_four_api) {
                $request = new FourCustomerRequest();
            } else {
                $request = new CustomerRequest();
            }
            
            $phone = new Phone();
            // $phone->number = $this->client->present()->phone();
            $phone->number = substr(str_pad($this->client->present()->phone(), 6, "0", STR_PAD_RIGHT), 0, 24);

            $request->email = $this->client->present()->email();
            $request->name = $this->client->present()->name();
            $request->phone = $phone;

            try {
                $response = $this->gateway->getCustomersClient()->create($request);
            } catch (CheckoutApiException $e) {
                // API error
                $request_id = $e->request_id;
                $http_status_code = $e->http_status_code;
                $error_details = $e->error_details;

                if (is_array($error_details)) {
                    $error_details = end($e->error_details['error_codes']);
                }

                $human_exception = $error_details ? new \Exception($error_details, 400) : $e;


                throw new PaymentFailed($human_exception);
            } catch (CheckoutArgumentException $e) {
                // Bad arguments

                $error_details = $e->error_details;

                if (is_array($error_details)) {
                    $error_details = end($e->error_details['error_codes']);
                }

                $human_exception = $error_details ? new \Exception($error_details, 400) : $e;

                throw new PaymentFailed($human_exception);
            } catch (CheckoutAuthorizationException $e) {
                // Bad Invalid authorization
          
                $error_details = $e->error_details;
         
                if (is_array($error_details)) {
                    $error_details = end($e->error_details['error_codes']);
                }

                $human_exception = $error_details ? new \Exception($error_details, 400) : $e;

                throw new PaymentFailed($human_exception);
            }

            return $response;
        }
    }

    public function bootTokenRequest($token)
    {
        if ($this->is_four_api) {
            $token_source = new SourceRequestIdSource();
            $token_source->id = $token;
            $request = new PaymentRequest();
            $request->source = $token_source;
        } else {
            $token_source = new RequestIdSource();
            $token_source->id = $token;
            $request = new PaymentsPaymentRequest();
            $request->source = $token_source;
        }

        return $request;
    }

    public function tokenBilling(ClientGatewayToken $cgt, PaymentHash $payment_hash)
    {
        $amount = array_sum(array_column($payment_hash->invoices(), 'amount')) + $payment_hash->fee_total;
        $invoice = Invoice::whereIn('id', $this->transformKeys(array_column($payment_hash->invoices(), 'invoice_id')))->withTrashed()->first();

        $this->init();

        $paymentRequest = $this->bootTokenRequest($cgt->token);
        $paymentRequest->amount = $this->convertToCheckoutAmount($amount, $this->client->getCurrencyCode());
        $paymentRequest->reference = '#'.$invoice->number.' - '.now();
        $paymentRequest->customer = $this->getCustomer();
        $paymentRequest->metadata = ['udf1' => 'Invoice Ninja'];
        $paymentRequest->currency = $this->client->getCurrencyCode();

        $request = new PaymentResponseRequest();
        $request->setMethod('POST');
        $request->request->add(['payment_hash' => $payment_hash->hash]);

        try {
            // $response = $this->gateway->payments()->request($payment);
            $response = $this->gateway->getPaymentsClient()->requestPayment($paymentRequest);

            if ($response['status'] == 'Authorized') {
                $this->confirmGatewayFee($request);

                $data = [
                    'payment_method' => $response['source']['id'],
                    'payment_type' => PaymentType::parseCardType(strtolower($response['source']['scheme'])),
                    'amount' => $amount,
                    'transaction_reference' => $response['id'],
                ];

                $payment = $this->createPayment($data, Payment::STATUS_COMPLETED);

                SystemLogger::dispatch(
                    ['response' => $response, 'data' => $data],
                    SystemLog::CATEGORY_GATEWAY_RESPONSE,
                    SystemLog::EVENT_GATEWAY_SUCCESS,
                    SystemLog::TYPE_CHECKOUT,
                    $this->client
                );

                return $payment;
            }

            if ($response['status'] == 'Declined') {
                $this->unWindGatewayFees($payment_hash);

                $this->sendFailureMail($response['status'].' '.$response['response_summary']);

                $message = [
                    'server_response' => $response,
                    'data' => $payment_hash->data,
                ];

                SystemLogger::dispatch(
                    $message,
                    SystemLog::CATEGORY_GATEWAY_RESPONSE,
                    SystemLog::EVENT_GATEWAY_FAILURE,
                    SystemLog::TYPE_CHECKOUT,
                    $this->client
                );

                return false;
            }
        } catch (Exception | CheckoutApiException $e) {
            $this->unWindGatewayFees($payment_hash);
            $message = $e->getMessage();

            $error_details = '';

            if (property_exists($e, 'error_details')) {
                $error_details = $e->error_details;
            }

            $data = [
                'status' => $e->error_details,
                'error_type' => '',
                'error_code' => $e->getCode(),
                'param' => '',
                'message' => $message,
            ];

            $this->sendFailureMail($message);

            SystemLogger::dispatch(
                $data,
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_CHECKOUT,
                $this->client,
                $this->client->company
            );
        }
    }

    public function processWebhookRequest(PaymentWebhookRequest $request)
    {
        return true;
    }

    public function process3dsConfirmation(Checkout3dsRequest $request)
    {
        $this->init();
        $this->setPaymentHash($request->getPaymentHash());

        //11-08-2022 check the user is authenticated
        if (!Auth::guard('contact')->check()) {
            $client = $request->getClient();
            auth()->guard('contact')->loginUsingId($client->contacts()->first()->id, true);
        }

        try {
            $payment = $this->gateway->getPaymentsClient()->getPaymentDetails(
                $request->query('cko-session-id')
            );

            if (isset($payment['approved']) && $payment['approved']) {
                return $this->processSuccessfulPayment($payment);
            } else {
                return $this->processUnsuccessfulPayment($payment);
            }
        } catch (CheckoutApiException | Exception $e) {
            nlog("checkout");
            nlog($e->getMessage());
            return $this->processInternallyFailedPayment($this, $e);
        }
    }

    public function detach(ClientGatewayToken $clientGatewayToken)
    {
        // Gateway doesn't support this feature.
    }
}
