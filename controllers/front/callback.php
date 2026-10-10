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
            $hashValue = Tools::getValue('hashValue');

            if (!is_string($hashValue) || $hashValue === '') {
                throw new \Exception($this->module->l('hashValueNotFound', 'callback'));
            }

            // Doğrulama kaydı, ödeme başlatılırken Moka'ya gönderilen benzersiz
            // işlem koduyla bulunur. Oturum çerezine bağımlı değildir; bu yüzden
            // banka dönüşündeki çapraz site POST'ta çerez gelmese bile çalışır.
            $postedTrxCode = Tools::getValue('OtherTrxCode');
            $postedTrxCode = is_scalar($postedTrxCode) ? (string) $postedTrxCode : '';
            $pending = null;
            $cartId = 0;

            if ($postedTrxCode !== '') {
                $pending = $this->module->getPendingPaymentByTrxCode($postedTrxCode);
            }

            if (is_array($pending)) {
                $cartId = (int) $pending['id_cart'];
            } elseif ($postedTrxCode !== '' && ctype_digit($postedTrxCode)) {
                // Geriye dönük uyumluluk: eski sürümlerde OtherTrxCode sepet kimliğiydi.
                $cartId = (int) $postedTrxCode;
                $pending = $this->module->getPendingPaymentByCart($cartId);
            }

            if ($cartId < 1) {
                $cartId = (int) $this->context->cookie->moka_pending_cart_id;
            }

            $cart = $this->context->cart;

            if ($cartId < 1 && $cart) {
                $cartId = (int) $cart->id;
            }

            // Sepeti oturumdan değil kimliğinden yükle.
            if ($cartId > 0 && (!$cart || (int) $cart->id !== $cartId)) {
                $loadedCart = new \Cart($cartId);

                if (Validate::isLoadedObject($loadedCart)) {
                    $cart = $loadedCart;
                }
            }

            if (!$cart || (int) $cart->id !== $cartId) {
                throw new \Exception($this->module->l('orderNotFound', 'callback'));
            }

            if (!is_array($pending)) {
                $pending = $this->module->getPendingPaymentByCart($cartId);
            }

            // Moka sorgusu, ödeme başlatılırken gönderilen kodla yapılmalı.
            $queryTrxCode = (is_array($pending) && !empty($pending['trx_code']))
                ? (string) $pending['trx_code']
                : ($postedTrxCode !== '' ? $postedTrxCode : (string) $cartId);

            // Kayıtlı kod yoksa hiçbir hash kabul edilmez; aksi halde beklenen değer
            // herkesin hesaplayabildiği sha256("T") sabitine düşer.
            if (is_array($pending)) {
                $storedCode = (string) $pending['code_for_hash'];
                $storedAmount = (float) $pending['amount'];
                $storedCurrency = (string) $pending['currency'];
            } else {
                // Geriye dönük uyumluluk: eski kayıtlar çerezde olabilir.
                $storedCode = $this->context->cookie->moka_pending_code;
                $storedAmount = (float) $this->context->cookie->moka_pending_amount;
                $storedCurrency = (string) $this->context->cookie->moka_pending_currency;

                if ((int) $this->context->cookie->moka_pending_cart_id !== $cartId) {
                    $storedCode = '';
                }
            }

            if (!is_string($storedCode) || $storedCode === '') {
                throw new \Exception($this->module->l('orderNotFound', 'callback'));
            }

            $expected = hash('sha256', $storedCode . 'T');

            if (!$this->hashEquals($expected, $hashValue)) {
                throw new \Exception($this->module->l('orderNotFound', 'callback'));
            }

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
            $paymentDetailRequest->setOtherTrxCode($queryTrxCode);

            $paymentDetail = $moka->payments()->retrieve($paymentDetailRequest);

            if ($paymentDetail->getResultCode() != 'Success') {
                throw new \Exception($this->module->l('paymentNotFound', 'callback'));
            }

            $paymentDetailData = $paymentDetail->getData();

            if (!isset($paymentDetailData->PaymentDetail)) {
                throw new \Exception($this->module->l('paymentNotFound', 'callback'));
            }

            $detail = $paymentDetailData->PaymentDetail;

            // Ödeme yalnızca Moka tarafında gerçekten başarılıysa tamamlanır.
            // Moka durum tablosu: PaymentStatus 2 (Ödeme) + TrxStatus 1
            // (Başarılı) birlikte "ödeme başarılı" demektir; TrxStatus tek
            // başına 1 olduğunda iptal (3) veya ön provizyon (1) olabilir.
            $paymentStatus = (isset($detail->PaymentStatus) && is_numeric($detail->PaymentStatus)) ? (int) $detail->PaymentStatus : 0;
            $trxStatus = (isset($detail->TrxStatus) && is_numeric($detail->TrxStatus)) ? (int) $detail->TrxStatus : 0;

            if ($paymentStatus !== 2 || $trxStatus !== 1) {
                throw new \Exception($this->module->l('paymentNotFound', 'callback'));
            }

            // Tutar, ödeme başlatılırken Moka'ya gönderilen tutarla eşleşmeli.
            $mokaAmount = (isset($detail->Amount) && is_numeric($detail->Amount)) ? (float) $detail->Amount : 0.0;
            $expectedAmount = (float) $storedAmount;

            if (abs($mokaAmount - $expectedAmount) >= 0.01) {
                throw new \Exception($this->module->l('paymentNotFound', 'callback'));
            }

            // Para birimi: TRY ve TL eşdeğerdir, alan boşsa bu adım atlanır.
            $mokaCurrency = isset($detail->CurrencyCode) ? strtoupper((string) $detail->CurrencyCode) : '';
            $expectedCurrency = strtoupper((string) $storedCurrency);

            if ($mokaCurrency === 'TRY') {
                $mokaCurrency = 'TL';
            }

            if ($expectedCurrency === 'TRY') {
                $expectedCurrency = 'TL';
            }

            if ($mokaCurrency !== '' && $expectedCurrency !== '' && $mokaCurrency !== $expectedCurrency) {
                throw new \Exception($this->module->l('paymentNotFound', 'callback'));
            }

            $this->context->cookie->moka_pending_code = '';
            $this->context->cookie->moka_pending_cart_id = 0;
            $this->context->cookie->moka_pending_amount = 0;
            $this->context->cookie->moka_pending_currency = '';

            $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $customer = new Customer($cart->id_customer);

            // Misafir/musteri kaydi tamamlanmamis sepetlerde siparis olusturulamaz;
            // SQL hatasi yerine anlamli bir dogrulama hatasi verilir.
            if (!Validate::isLoadedObject($customer) || empty($customer->secure_key)) {
                throw new \Exception($this->module->l('orderNotFound', 'callback'));
            }

            // Sepetin kendi para birimi kullanılır; oturum yoksa da doğru sonuç verir.
            $currency = new \Currency((int) $cart->id_currency);

            if (!Validate::isLoadedObject($currency)) {
                $currency = $this->context->currency;
            }

            $currenyId = (int) $currency->id;
            $customerSecureKey = $customer->secure_key;

            $mailVars = array();

            // Aynı sepet için sipariş zaten oluştuysa tekrar oluşturulmaz; aynı
            // callback'in tekrar oynatılması durumu burada kesilir.
            $existingOrderId = (int) Order::getOrderByCartId((int) $cart->id);

            if ($existingOrderId > 0) {
                $this->module->deletePendingPayment($cartId);

                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . (int) $this->module->id . '&id_order=' . $existingOrderId . '&key=' . $customerSecureKey);
            }

            $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $cartTotal, $this->module->displayName, NULL, $mailVars, $currenyId, false, $customerSecureKey);

            // Kod tek kullanımlıktır; sipariş gerçekten oluştuktan SONRA tüketilir,
            // böylece sipariş oluşturma başarısız olursa ödeme tekrar denenebilir.
            $this->module->deletePendingPayment($cartId);

            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customerSecureKey);
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();

            $this->context->smarty->assign(array(
                'errorMessage' => $errorMessage,
            ));

            $this->setTemplate('module:moka/views/templates/front/moka_error.tpl');
        }
    }

    /**
     * Sabit zamanlı hash karşılaştırması.
     */
    private function hashEquals($known, $given)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $given);
        }

        // PHP 5.6 öncesi için sabit zamanlı yedek.
        if (!is_string($known) || !is_string($given) || strlen($known) !== strlen($given)) {
            return false;
        }

        $diff = 0;

        for ($i = 0; $i < strlen($known); $i++) {
            $diff |= ord($known[$i]) ^ ord($given[$i]);
        }

        return $diff === 0;
    }
}
