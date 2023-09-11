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

class MokaInstallmentModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();

        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        if (Tools::getValue('bin_number')) {
            $this->getInstallmentInfo();
        }

        exit;
    }

    protected function getInstallmentInfo()
    {
        $binNumber = Tools::getValue('bin_number');

        $currency = $this->context->currency;
        $orderAmount = $this->context->cart->getOrderTotal(true, Cart::BOTH);

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

        $retrieveInstallmentInfoRequest = new Moka\Model\RetrieveInstallmentInfoRequest();
        $retrieveInstallmentInfoRequest->setBinNumber($binNumber);
        $retrieveInstallmentInfoRequest->setCurrency($this->getCurrency($currency->iso_code));
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

        foreach ($retrieveInstallmentInfoData->BankPaymentInstallmentInfoList as $bankPaymentInstallmentInfo) {
            foreach ($bankPaymentInstallmentInfo->PaymentInstallmentInfoList as $installment) {
                $installment->AmountFormatted = $this->context->currentLocale->formatPrice($installment->Amount, $currency->iso_code);
                $installment->PerInstallmentAmountFormatted = $this->context->currentLocale->formatPrice($installment->PerInstallmentAmount, $currency->iso_code);
            }
        }

        $this->context->smarty->assign('installment_info_data', $retrieveInstallmentInfoData);
        $this->context->smarty->assign('status', $retrieveInstallmentInfo->getResultCode());
        $this->context->smarty->assign('message', $retrieveInstallmentInfo->getResultMessage());

        $response = array(
            'html' => $this->context->smarty->fetch('module:moka/views/templates/front/installment.tpl'),
            'status' => $retrieveInstallmentInfo->getResultCode(),
            'message' => $retrieveInstallmentInfo->getResultMessage(),
        );

        echo json_encode($response);
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
}
