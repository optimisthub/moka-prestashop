<?php
/**
 * 2023 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author Optimist Hub <hi@optimisthub.com>
 *  @copyright 2023 Moka
 *  @license http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of Moka
 */

require_once _PS_MODULE_DIR_ . 'moka/classes/moka-php/autoload.php';

class MokaCheckoutModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();

        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        $response = array();

        if (($_SERVER['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->checkAndSetCookieSameSite();

            $orderId = $this->context->cookie->id_cart;
            $currency = $this->context->currency;
            $orderAmount = $this->context->cart->getOrderTotal(true, Cart::BOTH);
            $clientIp = Tools::getRemoteAddr();
            $httpProtocol = !Configuration::get('PS_SSL_ENABLED') ? 'http://' : 'https://';

            $cardHolderFullName = Tools::getValue('card_holder_full_name');
            $cardNumber = Tools::getValue('card_number');
            $expiryMonth = Tools::getValue('expiry_month');
            $expiryYear = Tools::getValue('expiry_year');
            $cvcNumber = Tools::getValue('cvc_number');
            $installment = Tools::getValue('installment');
            $callbackUrl = $httpProtocol . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'index.php?module_action=init&fc=module&module=moka&controller=callback';

            $apiEnvironment = Configuration::get('moka_api_environment');
            $dealerCode = Configuration::get('moka_dealer_code');
            $username = Configuration::get('moka_username');
            $password = Configuration::get('moka_password');

            $options = array(
                'dealerCode' => $dealerCode,
                'username' => $username,
                'password' => $password,
            );

            if ($apiEnvironment == 'test') {
                $options['baseUrl'] = 'https://service.refmoka.com';
            }

            $moka = new \Moka\MokaClient($options);

            $request = new Moka\Model\CreatePaymentRequest();

            $request->setCardHolderFullName($cardHolderFullName);
            $request->setCardNumber($cardNumber);
            $request->setExpMonth($expiryMonth);
            $request->setExpYear($expiryYear);
            $request->setCvcNumber($cvcNumber);

            $request->setAmount($orderAmount);
            $request->setCurrency($this->getCurrency($currency->iso_code));
            $request->setInstallmentNumber($installment);
            $request->setClientIp($clientIp);
            $request->setOtherTrxCode($orderId);
            $request->setSoftware('PrestaShop');
            $request->setReturnHash(1);
            $request->setRedirectUrl($callbackUrl);

            // Installment
            $retrieveInstallmentInfoRequest = new Moka\Model\RetrieveInstallmentInfoRequest();
            $retrieveInstallmentInfoRequest->setBinNumber(substr($cardNumber, 0, 6));
            $retrieveInstallmentInfoRequest->setCurrency($currency);
            $retrieveInstallmentInfoRequest->setOrderAmount($orderAmount);
            $retrieveInstallmentInfoRequest->setIsThreeD(1);
            
            $retrieveInstallmentInfo = $moka->payments()->retrieveInstallmentInfo($retrieveInstallmentInfoRequest);

            $retrieveInstallmentInfoData = (object) [
                'BankPaymentInstallmentInfoList' => [
                    (object) [
                        'BankInfoName' => '',
                        'PaymentInstallmentInfoList' => [
                            (object) [
                                'CommissionType' => '',
                                'InstallmentNumber' => 1,
                                'DealerCommissionRate' => 0,
                                'DealerCommissionFixedAmount' => 0,
                                'DealerCommissionAmount' => 0,
                                'PerInstallmentAmount' => $orderAmount,
                                'Amount' => $orderAmount
                            ]
                        ]
                    ]
                ]
            ];

            if ($retrieveInstallmentInfo->getResultCode() == 'Success') {
                $retrieveInstallmentInfoData = $retrieveInstallmentInfo->getData();
            }

            foreach ($retrieveInstallmentInfoData->BankPaymentInstallmentInfoList as $BankPaymentInstallmentInfo) {
                foreach ($BankPaymentInstallmentInfo->PaymentInstallmentInfoList as $paymentInstallmentInfo) {
                    if ($paymentInstallmentInfo->InstallmentNumber == $installment) {
                        $request->setAmount($paymentInstallmentInfo->Amount);
                    }
                }
            }

            $payment = $moka->payments()->createThreeds($request);

            $paymentResultCode = $payment->getResultCode();
            $paymentData = $payment->getData();

            if ($paymentResultCode == 'Success') {
                $this->context->cookie->code_for_hash = $paymentData->CodeForHash;

                $response['redirect'] = $paymentData->Url;
            }

            if ($paymentResultCode !== 'Success') {
                $this->errors[] = $this->getPaymentResultErrorMessage($paymentResultCode);
            }
        }

        $response['errors'] = $this->errors;

        echo json_encode($response);

        exit();
    }

    private function validate()
    {
        function checkExpiryDate($month, $year)
        {
            $currentYear = date('Y');
            $currentMonth = date('m');

            if ($year > $currentYear || ($year == $currentYear && $month >= $currentMonth)) {
                return true;
            }

            return false;
        }

        if (!Tools::getIsset('card_holder_full_name') || empty(trim(Tools::getValue('card_holder_full_name')))) {
            $this->errors['card_holder_full_name'] = $this->module->l('cardHolderFullName', 'checkout');
        }

        if (!Tools::getIsset('card_number') || empty(trim(Tools::getValue('card_number'))) || !preg_match('/^\d{13,19}$/', Tools::getValue('card_number'))) {
            $this->errors['card_number'] = $this->module->l('cardNumber', 'checkout');
        }

        if (!Tools::getIsset('expiry_month') || !Tools::getIsset('expiry_year') || empty(trim(Tools::getValue('expiry_month'))) || empty(trim(Tools::getValue('expiry_year'))) || !checkExpiryDate(Tools::getValue('expiry_month'), Tools::getValue('expiry_year'))) {
            $this->errors['expiry_date'] = $this->module->l('expiryDate', 'checkout');
        }

        if (!Tools::getIsset('cvc_number') || empty(trim(Tools::getValue('cvc_number'))) || !preg_match('/^\d{3,4}$/', Tools::getValue('cvc_number'))) {
            $this->errors['cvc_number'] = $this->module->l('cvcNumber', 'checkout');
        }

        if (!Tools::getIsset('installment') || empty(trim(Tools::getValue('installment'))) || !is_numeric(Tools::getValue('installment')) || Tools::getValue('installment') < 1) {
            $this->errors['installment'] = $this->module->l('installment', 'checkout');
        }

        return !$this->errors;
    }

    private function getCurrency($currencyCode)
    {
        switch ($currencyCode) {
            case "TRY":
                return 'TL';
            case "USD":
                return 'USD';
            case "EUR":
                return 'EUR';
            case "GBP":
                return 'GBP';
            default:
                return 'TL';
        }
    }

    private function getPaymentResultErrorMessage($errorCode)
    {
        $errorCodes = array(
            'PaymentDealer.CheckPaymentDealerAuthentication.InvalidRequest' => $this->module->l('PaymentDealer.CheckPaymentDealerAuthentication.InvalidRequest', 'checkout'),
            'PaymentDealer.CheckPaymentDealerAuthentication.InvalidAccount' => $this->module->l('PaymentDealer.CheckPaymentDealerAuthentication.InvalidAccount', 'checkout'),
            'PaymentDealer.CheckPaymentDealerAuthentication.VirtualPosNotFound' => $this->module->l('PaymentDealer.CheckPaymentDealerAuthentication.VirtualPosNotFound', 'checkout'),
            'PaymentDealer.CheckDealerPaymentLimits.DailyDealerLimitExceeded' => $this->module->l('PaymentDealer.CheckDealerPaymentLimits.DailyDealerLimitExceeded', 'checkout'),
            'PaymentDealer.CheckDealerPaymentLimits.DailyCardLimitExceeded' => $this->module->l('PaymentDealer.CheckDealerPaymentLimits.DailyCardLimitExceeded', 'checkout'),
            'PaymentDealer.CheckCardInfo.InvalidCardInfo' => $this->module->l('PaymentDealer.CheckCardInfo.InvalidCardInfo', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidRequest' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidRequest', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.RedirectUrlRequired' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.RedirectUrlRequired', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidCurrencyCode' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidCurrencyCode', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidInstallmentNumber' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidInstallmentNumber', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InstallmentNotAvailableForForeignCurrencyTransaction' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InstallmentNotAvailableForForeignCurrencyTransaction', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.ForeignCurrencyNotAvailableForThisDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.ForeignCurrencyNotAvailableForThisDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.PaymentMustBeAuthorization' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.PaymentMustBeAuthorization', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.AuthorizationForbiddenForThisDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.AuthorizationForbiddenForThisDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.PoolPaymentNotAvailableForDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.PoolPaymentNotAvailableForDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.PoolPaymentRequiredForDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.PoolPaymentRequiredForDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.TokenizationNotAvailableForDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.TokenizationNotAvailableForDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.CardTokenCannotUseWithSaveCard' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.CardTokenCannotUseWithSaveCard', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.CardTokenNotFound' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.CardTokenNotFound', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.OnlyCardTokenOrCardNumber' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.OnlyCardTokenOrCardNumber', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.ChannelPermissionNotAvailable' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.ChannelPermissionNotAvailable', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.IpAddressNotAllowed' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.IpAddressNotAllowed', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.VirtualPosNotAvailable' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.VirtualPosNotAvailable', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.ThisInstallmentNumberNotAvailableForVirtualPos' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.ThisInstallmentNumberNotAvailableForVirtualPos', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.ThisInstallmentNumberNotAvailableForDealer' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.ThisInstallmentNumberNotAvailableForDealer', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.DealerCommissionRateNotFound' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.DealerCommissionRateNotFound', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.DealerGroupCommissionRateNotFound' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.DealerGroupCommissionRateNotFound', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidSubMerchantName' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidSubMerchantName', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidUnitPrice' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidUnitPrice', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.InvalidQuantityValue' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.InvalidQuantityValue', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.BasketAmountIsNotEqualPaymentAmount' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.BasketAmountIsNotEqualPaymentAmount', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.BasketProductNotFoundInYourProductList' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.BasketProductNotFoundInYourProductList', 'checkout'),
            'PaymentDealer.DoDirectPayment3dRequest.MustBeOneOfDealerProductIdOrProductCode' => $this->module->l('PaymentDealer.DoDirectPayment3dRequest.MustBeOneOfDealerProductIdOrProductCode', 'checkout'),
            'EX' => $this->module->l('EX', 'checkout')
        );

        if (array_key_exists($errorCode, $errorCodes)) {
            return $errorCodes[$errorCode];
        }

        return '';
    }

    private function setcookieSameSite($name, $value, $expire, $path, $domain, $secure, $httponly)
    {
        if (PHP_VERSION_ID < 70300) {
            setcookie($name, $value, $expire, "$path; samesite=None", $domain, $secure, $httponly);
        } else {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => $path,
                'domain' => $domain,
                'samesite' => 'None',
                'secure' => $secure,
                'httponly' => $httponly,
            ]);
        }
    }

    private function checkAndSetCookieSameSite()
    {
        $cookies = array('PHPSESSID', 'OCSESSID', 'default', 'PrestaShop-');

        foreach ($cookies as $cookie) {
            if (isset($_COOKIE[$cookie])) {
                $this->setcookieSameSite($cookie, $_COOKIE[$cookie], time() + 86400, "/", $_SERVER['SERVER_NAME'], true, true);
            }
        }
    }
}
