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

class MokaCallbackModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();

        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        try {
            if (!Tools::getValue('hashValue')) {
                $errorMessage = $this->module->l('hashValueNotFound', 'callback');

                throw new \Exception($errorMessage);
            }

            $codeForHash = hash('sha256', $this->context->cookie->code_for_hash . 'T');
            $hashValue = Tools::getValue('hashValue');

            if ($codeForHash == $hashValue) {
                $cart = $this->context->cart;
                $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
                $customer = new Customer($cart->id_customer);

                $currency = $this->context->currency;
                $currenyId = (int) $currency->id;
                $customerSecureKey = $customer->secure_key;

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

                $paymentDetailRequest = new \Moka\Model\RetrievePaymentDetailRequest();
                $paymentDetailRequest->setOtherTrxCode($cart->id);

                $paymentDetail = $moka->payments()->retrieve($paymentDetailRequest);

                $paymentDetailResultCode = $paymentDetail->getResultCode();

                $mailVars = array();

                if ($paymentDetailResultCode == 'Success') {
                    $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $cartTotal, $this->module->displayName, NULL, $mailVars, $currenyId, false, $customerSecureKey);
                }

                if ($paymentDetailResultCode !== 'Success') {
                    $errorMessage = $this->module->l('paymentNotFound', 'callback');

                    throw new \Exception($errorMessage);
                }

                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customerSecureKey);
            } else {
                $errorMessage = $this->module->l('orderNotFound', 'callback');

                throw new \Exception($errorMessage);
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();

            $this->context->smarty->assign(array(
                'errorMessage' => $errorMessage,
            ));

            $this->setTemplate('module:moka/views/templates/front/moka_error.tpl');
        }
    }
}
