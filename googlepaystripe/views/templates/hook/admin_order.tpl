{*
* Transaction panel on the back office order page.
* Everything the merchant needs to reconcile the order against Stripe,
* without a card number ever being stored.
*}

<div class="panel">
	<div class="panel-heading">
		<i class="icon-google"></i> {l s='Google Pay / Stripe transaction' mod='googlepaystripe'}
		{if !$gps_payment.live_mode}
			<span class="badge" style="background:#fbb03b;">{l s='TEST' mod='googlepaystripe'}</span>
		{/if}
	</div>

	<table class="table">
		<tbody>
			<tr>
				<td style="width:220px;"><strong>{l s='Payment intent' mod='googlepaystripe'}</strong></td>
				<td><code>{$gps_payment.payment_intent_id|escape:'html':'UTF-8'}</code></td>
			</tr>
			{if $gps_payment.charge_id}
				<tr>
					<td><strong>{l s='Charge' mod='googlepaystripe'}</strong></td>
					<td><code>{$gps_payment.charge_id|escape:'html':'UTF-8'}</code></td>
				</tr>
			{/if}
			<tr>
				<td><strong>{l s='Status' mod='googlepaystripe'}</strong></td>
				<td>{$gps_payment.status|escape:'html':'UTF-8'}</td>
			</tr>
			<tr>
				<td><strong>{l s='Amount' mod='googlepaystripe'}</strong></td>
				{* The column is DECIMAL(20,6), so the raw value reads
				   "1.220000 EUR". The stored figure is exact and unchanged —
				   this only affects how it is displayed. *}
				<td>{$gps_amount_display|escape:'html':'UTF-8'}</td>
			</tr>
			{if $gps_payment.card_brand}
				<tr>
					<td><strong>{l s='Card' mod='googlepaystripe'}</strong></td>
					<td>
						{$gps_payment.card_brand|ucfirst|escape:'html':'UTF-8'} ****{$gps_payment.card_last4|escape:'html':'UTF-8'}
						{if $gps_payment.card_funding} ({$gps_payment.card_funding|escape:'html':'UTF-8'}){/if}
					</td>
				</tr>
			{/if}
			{if $gps_payment.wallet_type}
				<tr>
					<td><strong>{l s='Wallet' mod='googlepaystripe'}</strong></td>
					<td>{$gps_payment.wallet_type|escape:'html':'UTF-8'}</td>
				</tr>
			{/if}
			<tr>
				<td><strong>{l s='Date' mod='googlepaystripe'}</strong></td>
				<td>{$gps_payment.date_upd|escape:'html':'UTF-8'}</td>
			</tr>
			<tr>
				<td><strong>{l s='In Stripe' mod='googlepaystripe'}</strong></td>
				<td>
					<a href="{$gps_dashboard_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="btn btn-default">
						<i class="icon-external-link"></i> {l s='Open this payment in Stripe' mod='googlepaystripe'}
					</a>
				</td>
			</tr>
		</tbody>
	</table>
</div>
