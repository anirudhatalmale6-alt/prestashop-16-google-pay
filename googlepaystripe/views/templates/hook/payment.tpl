{*
* Google Pay payment option, rendered inside the standard PrestaShop 1.6
* payment step alongside the shop's other methods.
*
* The block starts hidden. It is revealed by JavaScript only once Stripe
* confirms this browser can actually pay with Google Pay, so shoppers on
* browsers without it never see a button that cannot work.
*}

{if $gps_error}
	<div class="alert alert-danger gps-alert">{$gps_error|escape:'html':'UTF-8'}</div>
{/if}

<div class="row" id="gps-block" style="display:none;">
	<div class="col-xs-12">
		<div class="payment_module gps-payment-module">
			<div class="gps-heading">
				<span class="gps-title">{$gps_title|escape:'html':'UTF-8'}</span>
				{if $gps_test_mode}
					<span class="gps-badge-test">{l s='TEST MODE' mod='googlepaystripe'}</span>
				{/if}
			</div>

			<p class="gps-subtitle">
				{l s='Pay in a couple of taps with the card saved in your Google account. You will be asked to confirm before anything is charged.' mod='googlepaystripe'}
			</p>

			{* Stripe mounts the official Google Pay button in here. *}
			<div id="gps-button"></div>

			<div id="gps-processing" class="gps-processing" style="display:none;">
				<span class="gps-spinner"></span>
				<span id="gps-processing-text"></span>
			</div>

			<div id="gps-error" class="alert alert-danger gps-inline-error" style="display:none;"></div>
		</div>
	</div>
</div>

{*
* Loaded straight from Stripe, never bundled or minified into the shop's own
* JS. Stripe requires this for the shop to stay in PCI DSS SAQ A scope.
*}
<script src="https://js.stripe.com/v3/"></script>
