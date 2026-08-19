{*
* Pre-flight panel at the top of the module configuration page.
* Answers "is this thing actually going to work?" before the merchant
* discovers the answer at checkout.
*}

<div class="panel">
	<div class="panel-heading">
		<i class="icon-dashboard"></i> {l s='Status' mod='googlepaystripe'}
		{if $gps_test_mode}
			<span class="badge" style="background:#fbb03b;">{l s='TEST MODE' mod='googlepaystripe'}</span>
		{else}
			<span class="badge" style="background:#72c279;">{l s='LIVE MODE' mod='googlepaystripe'}</span>
		{/if}
	</div>

	<table class="table">
		<tbody>
			{foreach from=$gps_checks item=check}
				<tr>
					<td style="width:30px;">
						{if $check.ok}
							<i class="icon-check-circle" style="color:#72c279;"></i>
						{else}
							<i class="icon-warning" style="color:#f0ad4e;"></i>
						{/if}
					</td>
					<td>
						<strong>{$check.label|escape:'html':'UTF-8'}</strong>
						{if !$check.ok}
							<div class="help-block" style="margin:4px 0 0 0;">{$check.hint|escape:'html':'UTF-8'}</div>
						{/if}
					</td>
				</tr>
			{/foreach}
		</tbody>
	</table>

	<div class="panel-footer" style="text-align:left;">
		<p style="margin:0 0 6px 0;">
			<strong>{l s='Webhook endpoint URL' mod='googlepaystripe'}</strong>
			{l s='— paste this into Stripe under Developers > Webhooks:' mod='googlepaystripe'}
		</p>
		<code style="display:block;padding:8px;background:#f4f4f4;word-break:break-all;">{$gps_webhook_url|escape:'html':'UTF-8'}</code>
		<p class="help-block" style="margin:8px 0 0 0;">
			{l s='Subscribe it to these events: payment_intent.succeeded, payment_intent.amount_capturable_updated, payment_intent.payment_failed' mod='googlepaystripe'}
		</p>
	</div>
</div>
