{*
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
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of Moka
*}

<div class="moka-checkout-card" id="moka-checkout-installment">
    <div class="moka-checkout-card-header">{l s='Installement Options' mod='moka'}</div>
    <div class="moka-checkout-card-body">
        <div class="moka-checkout-installment-list">
            {foreach $installment_info_data->BankPaymentInstallmentInfoList[0]->PaymentInstallmentInfoList as $paymentInstallmentInfo}
                {if $paymentInstallmentInfo->InstallmentNumber == 1}
                    <label for="moka-checkout-installment-{$paymentInstallmentInfo->InstallmentNumber}" class="moka-checkout-installment-item">
                        <div class="moka-checkout-installment-text">
                            <div class="moka-checkout-installment-radio-item">
                                <input type="radio" name="moka-checkout-installment" class="moka-checkout-installment-radio-input" id="moka-checkout-installment-{$paymentInstallmentInfo->InstallmentNumber}" value="{$paymentInstallmentInfo->InstallmentNumber}" checked>
                                <div class="moka-checkout-installment-check">
                                    <svg width="12" height="12" viewBox="0 0 8 8" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="white" fill-rule="nonzero" d="M1.829 6.825a.832.832 0 0 0 1.162.02l4.733-4.46a.833.833 0 1 0-1.142-1.211L2.44 5.076 1.434 4.06A.833.833 0 1 0 .25 5.232l1.578 1.593z"></path>
                                    </svg>
                                </div>
                            </div>
                            <span class="moka-checkout-installment-number">{l s='Single Payment' mod='moka'}</span>
                        </div>
                        <div class="moka-checkout-installment-total">{$paymentInstallmentInfo->AmountFormatted}</div>
                    </label>
                {/if}
                {if $paymentInstallmentInfo->InstallmentNumber > 1}
                    <label for="moka-checkout-installment-{$paymentInstallmentInfo->InstallmentNumber}" class="moka-checkout-installment-item">
                        <div class="moka-checkout-installment-text">
                            <div class="moka-checkout-installment-radio-item">
                                <input type="radio" name="moka-checkout-installment" class="moka-checkout-installment-radio-input" id="moka-checkout-installment-{$paymentInstallmentInfo->InstallmentNumber}" value="{$paymentInstallmentInfo->InstallmentNumber}">
                                <div class="moka-checkout-installment-check">
                                    <svg width="12" height="12" viewBox="0 0 8 8" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="white" fill-rule="nonzero" d="M1.829 6.825a.832.832 0 0 0 1.162.02l4.733-4.46a.833.833 0 1 0-1.142-1.211L2.44 5.076 1.434 4.06A.833.833 0 1 0 .25 5.232l1.578 1.593z"></path>
                                    </svg>
                                </div>
                            </div>
                            <span class="moka-checkout-installment-number">{$paymentInstallmentInfo->InstallmentNumber}</span>
                            <span class="moka-checkout-installment-cross">x</span>
                            <span class="moka-checkout-installment-price">{$paymentInstallmentInfo->PerInstallmentAmountFormatted}</span>
                        </div>
                        <div class="moka-checkout-installment-total">{$paymentInstallmentInfo->AmountFormatted}</div>
                    </label>
                {/if}
            {/foreach}
        </div>
    </div>
</div>