{*
* Shown on the order confirmation page after a successful Google Pay payment.
*}

<div class="box gps-return">
	<h3 class="page-subheading">{l s='Payment confirmed' mod='googlepaystripe'}</h3>

	<p>
		{l s='Thank you. Your payment with Google Pay was accepted.' mod='googlepaystripe'}
	</p>

	<ul class="gps-return-details">
		<li>
			<strong>{l s='Order reference:' mod='googlepaystripe'}</strong>
			{$gps_order_reference|escape:'html':'UTF-8'}
		</li>
		<li>
			<strong>{l s='Amount paid:' mod='googlepaystripe'}</strong>
			{$gps_total_paid}
		</li>
		{if $gps_payment && $gps_payment.card_brand}
			<li>
				<strong>{l s='Paid with:' mod='googlepaystripe'}</strong>
				{$gps_payment.card_brand|ucfirst|escape:'html':'UTF-8'} ****{$gps_payment.card_last4|escape:'html':'UTF-8'}
			</li>
		{/if}
	</ul>

	<p>
		{l s='A confirmation email is on its way. If you have any questions about your order, please get in touch and quote the reference above.' mod='googlepaystripe'}
	</p>
</div>
