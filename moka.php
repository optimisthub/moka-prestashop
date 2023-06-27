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

if (!defined('_PS_VERSION_')) {
    exit;
}

class Moka extends PaymentModule
{
    public $extra_mail_vars;

    public function __construct()
    {
        $this->name = 'moka';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Optimist Hub';
        $this->need_instance = 1;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName      = $this->l('Moka Payment');
        $this->description      = $this->l('Moka Payment Gateway for PrestaShop');
        $this->confirmUninstall = $this->l('Are you sure?');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);

        $this->extra_mail_vars = array(
            '{instalmentFee}' => '',
        );
    }

    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');

            return false;
        }

        return parent::install() &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('PaymentOptions') &&
            $this->registerHook('paymentReturn');
    }

    public function uninstall()
    {
        return $this->unregisterHook('backOfficeHeader') &&
            $this->unregisterHook('PaymentOptions') &&
            $this->unregisterHook('paymentReturn') &&
            Configuration::deleteByName('moka_api_environment') &&
            Configuration::deleteByName('moka_dealer_code') &&
            Configuration::deleteByName('moka_username') &&
            Configuration::deleteByName('moka_password') &&
            Configuration::deleteByName('moka_option_text') &&
            parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMokaModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('mokaVersion', $this->version);
        $this->context->smarty->assign('languageIsoCode', $this->context->language->iso_code);
        $this->context->smarty->assign('mokaApiEnvironment', Configuration::get('moka_api_environment'));

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        $this->setMokaTitle();

        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->id = 'moka';
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMokaModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('API Environment'),
                        'name' => 'moka_api_environment',
                        'desc' => $this->l('API Environment Live or Sandbox'),
                        'required' => true,
                        'options' => array(
                            'query' => array(
                                array('id' => 'live', 'name' => 'Live'),
                                array('id' => 'test', 'name' => 'Sandbox / Test'),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'moka_dealer_code',
                        'desc' => $this->l('Dealer Code'),
                        'required' => true,
                        'label' => $this->l('Dealer Code'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'moka_username',
                        'desc' => $this->l('Username'),
                        'required' => true,
                        'label' => $this->l('Username'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'moka_password',
                        'desc' => $this->l('Password'),
                        'required' => true,
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'col' => 8,
                        'type' => 'text',
                        'name' => 'moka_option_text',
                        'desc' => $this->l('Payment option text / Provides multi-language support.Example :tr=moka|en=Credit Cart'),
                        'label' => $this->l('Payment Text'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'moka_api_environment' => Configuration::get('moka_api_environment', true),
            'moka_dealer_code' => Configuration::get('moka_dealer_code', true),
            'moka_username' => Configuration::get('moka_username', true),
            'moka_password' => Configuration::get('moka_password', true),
            'moka_option_text' => Configuration::get('moka_option_text', true),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        $this->setMokaTitle();
    }

    /**
     * @return bool
     */
    private function setMokaTitle()
    {
        $title = Configuration::get('moka_option_text');

        if (!$title) {
            Configuration::updateValue('moka_option_text', 'tr=Kredi ve Banka Kartı ile Ödeme |en=Credit and Debit Card');
        }

        return true;
    }

    /**
     * This method is used to render the payment button,
     * Take care if the button should be displayed or not.
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return false;
        }

        if (!isset($params['cart'])) {
            return false;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return false;
        }

        return $this->paymentOptionResult();
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params)
    {
        $order = $params['order'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR')) {
            $this->smarty->assign('status', 'ok');
        }

        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($this->context->cookie->totalPrice, $this->context->currency, false),
        ));

        return $this->display(__FILE__, 'views/templates/front/confirmation.tpl');
    }

    private function getOptionText()
    {
        $title = Configuration::get('moka_option_text');
        $isoCode = $this->context->language->iso_code;

        $title = $this->mokaLanguageChanger($title, $isoCode);

        return $title;
    }

    private function paymentOptionResult()
    {
        $title = $this->getOptionText();
        $newOptions = array();

        $this->context->smarty->assign('module_dir', $this->_path);

        $apiEnvironment = Configuration::get('moka_api_environment');

        $this->context->smarty->assign('api_environment', $apiEnvironment);

        $newOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->trans($title, array(), 'Modules.Moka'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->fetch('module:moka/views/templates/front/moka.tpl'));

        $newOptions[] = $newOption;

        return $newOptions;
    }

    private function checkCurrency()
    {
        $currencies = array('TRY', 'USD', 'EUR', 'GBP');

        $currency = $this->context->currency;

        if (in_array($currency->iso_code, $currencies)) {
            return true;
        }

        return false;
    }

    private function mokaLanguageChanger($title, $isoCode)
    {
        if ($title) {
            $parser = explode('|', $title);

            if (is_array($parser) && count($parser)) {
                foreach ($parser as $parse) {
                    $result = explode('=', $parse);
                    if ($isoCode == $result[0]) {
                        $title = $result[1];
                        break;
                    }
                }
            }
        }

        return $title;
    }
}
