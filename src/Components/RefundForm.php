<?php
/**
 * Refund Form Component
 *
 * Inline expandable refund panel for issuing full or partial refunds.
 * Designed for use within flyouts displaying order/payment details.
 * Submits via the existing REST action endpoint.
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Money\Currencies;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Flyouts\Utils\Amount;
use SugarCommerce\Money\Money;

class RefundForm implements Renderable {

	/**
	 * Component configuration
	 *
	 * @var array
	 */
	private array $config;

	/**
	 * Stripe-supported refund reasons
	 *
	 * @var array<string, string>
	 */
	private const array REASONS = [
		'requested_by_customer' => 'Requested by customer',
		'duplicate'             => 'Duplicate',
		'fraudulent'            => 'Fraudulent',
	];

	/**
	 * Constructor
	 *
	 * @param array $config Configuration options
	 */
	public function __construct( array $config = [] ) {
		$this->config = wp_parse_args( $config, [
			'name'             => 'refund',
			'action'           => 'issue_refund',
			'amount_paid'      => 0,
			'amount_refunded'  => 0,
			'currency'         => 'USD',
			'reasons'          => self::REASONS,
			'allow_custom'     => true,
			'label'            => 'Refund',
			'class'            => '',

			// The unit amount_paid and amount_refunded are in: `major`
			// (159.84) or `minor` (15984). See Utils\Amount.
			'money'            => Amount::MAJOR,
		] );

		if ( empty( $this->config['id'] ) ) {
			$this->config['id'] = 'refund-form-' . wp_generate_uuid4();
		}
	}

	/**
	 * The maximum refundable amount, in minor units.
	 *
	 * Both amounts are converted before they are subtracted. Doing the
	 * arithmetic in major units first and rounding after leaves the result
	 * at the mercy of binary floating point: 159.84 - 0.0 is exact, but
	 * 0.1 + 0.2 is not, and a refund cap is not a figure to leave to that.
	 *
	 * @return int
	 */
	private function get_refundable(): int {
		$currency = strtoupper( (string) $this->config['currency'] );

		$paid     = $this->to_minor( $this->config['amount_paid'] ?? 0 );
		$refunded = $this->to_minor( $this->config['amount_refunded'] ?? 0 );

		return max( 0, $paid - $refunded );
	}

	/**
	 * A configured amount in minor units, from whichever unit it was given in.
	 *
	 * @param mixed $amount Amount, in the field's `money` unit.
	 *
	 * @return int
	 */
	private function to_minor( $amount ): int {
		return Amount::minor(
			$amount,
			strtoupper( (string) $this->config['currency'] ),
			Amount::unit( $this->config['money'] ?? Amount::MAJOR )
		);
	}

	/**
	 * Format a configured amount for display.
	 *
	 * @param int|float|string $amount Amount, in the field's `money` unit.
	 *
	 * @return string
	 */
	private function format_amount( $amount ): string {
		$currency = strtoupper( (string) $this->config['currency'] );

		return Money::format( $this->to_minor( $amount ), [ 'currency' => $currency ] );
	}

	/**
	 * Format an amount that is already in minor units.
	 *
	 * The refundable balance is computed here rather than configured, and it
	 * is computed in minor units so the subtraction is exact. Passing it to
	 * format_amount() -- which takes major units -- multiplied it by the
	 * currency's exponent a second time, and a $159.84 payment offered a
	 * refund of $15,984.00.
	 *
	 * @param int $amount Amount in minor units.
	 *
	 * @return string
	 */
	private function format_minor( int $amount ): string {
		$currency = strtoupper( (string) $this->config['currency'] );

		return Money::format( $amount, [ 'currency' => $currency ] );
	}

	/**
	 * Format a minor-unit amount for an input's value.
	 *
	 * The number of decimal places comes from the currency rather than
	 * being fixed at two: a yen field wants 5000, not 5000.00, and a
	 * three-decimal dinar field wants all three.
	 *
	 * @param int $amount Amount in minor units.
	 *
	 * @return string
	 */
	private function format_decimal( int $amount ): string {
		return Amount::decimal( $amount, strtoupper( (string) $this->config['currency'] ) );
	}

	/**
	 * Render the component
	 *
	 * @return string
	 */
	#[\Override]
	public function render(): string {
		$refundable = $this->get_refundable();

		// Nothing to refund
		if ( $refundable <= 0 ) {
			return $this->render_fully_refunded();
		}

		$id              = esc_attr( $this->config['id'] );
		$name            = esc_attr( $this->config['name'] );
		$action          = esc_attr( $this->config['action'] );
		$currency        = strtoupper( (string) $this->config['currency'] );
		$amount_paid     = $this->config['amount_paid'];
		$amount_refunded = $this->config['amount_refunded'];
		$decimals        = Currencies::decimals( $currency );

		$classes = 'wp-flyout-refund-form';
		if ( ! empty( $this->config['class'] ) ) {
			$classes .= ' ' . $this->config['class'];
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $classes ); ?>"
			data-action="<?php echo esc_attr( $action ); ?>"
			data-currency="<?php echo esc_attr( $currency ); ?>"
			data-locale="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"
			<?php // Minor units, all three, so the script never has to guess which. ?>
			data-decimals="<?php echo esc_attr( (string) $decimals ); ?>"
			data-paid="<?php echo esc_attr( (string) $this->to_minor( $amount_paid ) ); ?>"
			data-refunded="<?php echo esc_attr( (string) $this->to_minor( $amount_refunded ) ); ?>"
			data-refundable="<?php echo esc_attr( (string) $refundable ); ?>"
			data-error-empty="<?php esc_attr_e( 'Enter a refund amount.', 'sugarcommerce' ); ?>"
			data-error-exceeds="<?php esc_attr_e( 'Amount exceeds the refundable balance.', 'sugarcommerce' ); ?>">

			<?php // ---- Trigger Button ---- ?>
			<button type="button" class="button button-secondary refund-trigger">
				<span class="button-text"><?php echo esc_html( $this->config['label'] ); ?></span>
			</button>

			<?php // ---- Expandable Panel ---- ?>
			<div class="refund-panel" style="display: none;">

				<?php // ---- Summary ---- ?>
				<div class="refund-summary">
					<span class="refund-summary-item">
						<span class="refund-summary-label"><?php esc_html_e( 'Paid', 'sugarcommerce' ); ?></span>
						<span class="refund-summary-value"><?php echo esc_html( $this->format_amount( $amount_paid ) ); ?></span>
					</span>
					<?php if ( $this->to_minor( $amount_refunded ) > 0 ) : ?>
						<span class="refund-summary-sep">&middot;</span>
						<span class="refund-summary-item">
							<span class="refund-summary-label"><?php esc_html_e( 'Refunded', 'sugarcommerce' ); ?></span>
							<span class="refund-summary-value refund-summary-refunded"><?php echo esc_html( $this->format_amount( $amount_refunded ) ); ?></span>
						</span>
					<?php endif; ?>
					<span class="refund-summary-sep">&middot;</span>
					<span class="refund-summary-item">
						<span class="refund-summary-label"><?php esc_html_e( 'Available', 'sugarcommerce' ); ?></span>
						<span class="refund-summary-value refund-summary-available"><?php echo esc_html( $this->format_minor( $refundable ) ); ?></span>
					</span>
				</div>

				<?php // ---- Amount Input ---- ?>
				<div class="refund-field">
					<label for="<?php echo esc_attr( $id ); ?>_amount"><?php esc_html_e( 'Refund amount', 'sugarcommerce' ); ?></label>
					<div class="refund-amount-wrap">
						<span class="refund-currency-symbol"><?php echo esc_html( $currency ); ?></span>
						<input type="text"
								id="<?php echo esc_attr( $id ); ?>_amount"
								name="<?php echo esc_attr( $name ); ?>[amount]"
								class="refund-amount-input"
								value="<?php echo esc_attr( $this->format_decimal( $refundable ) ); ?>"
								placeholder="<?php echo esc_attr( number_format( 0, $decimals, '.', '' ) ); ?>"
								inputmode="decimal"
								autocomplete="off">
					</div>
				</div>

				<?php // ---- Reason Select ---- ?>
				<div class="refund-field">
					<label for="<?php echo esc_attr( $id ); ?>_reason"><?php esc_html_e( 'Reason', 'sugarcommerce' ); ?></label>
					<select id="<?php echo esc_attr( $id ); ?>_reason"
					        name="<?php echo esc_attr( $name ); ?>[reason]"
					        class="refund-reason-select">
						<?php foreach ( $this->config['reasons'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $this->config['allow_custom'] ) : ?>
							<option value="other"><?php esc_html_e( 'Other', 'sugarcommerce' ); ?></option>
						<?php endif; ?>
					</select>
				</div>

				<?php // ---- Custom Reason (hidden by default) ---- ?>
				<?php if ( $this->config['allow_custom'] ) : ?>
					<div class="refund-field refund-custom-reason" style="display: none;">
						<label for="<?php echo esc_attr( $id ); ?>_custom_reason"><?php esc_html_e( 'Details', 'sugarcommerce' ); ?></label>
						<input type="text"
								id="<?php echo esc_attr( $id ); ?>_custom_reason"
								name="<?php echo esc_attr( $name ); ?>[custom_reason]"
								class="refund-custom-input"
								placeholder="<?php esc_attr_e( 'Enter reason...', 'sugarcommerce' ); ?>"
								autocomplete="off">
					</div>
				<?php endif; ?>

				<?php // ---- Actions ---- ?>
				<div class="refund-actions">
					<button type="button"
					        class="button button-primary refund-submit"
					        data-template="<?php /* translators: %s: formatted refund amount */ esc_attr_e( 'Refund %s', 'sugarcommerce' ); ?>">
						<span class="button-text">
							<?php /* translators: %s: formatted refund amount */ printf( esc_html__( 'Refund %s', 'sugarcommerce' ), esc_html( $this->format_minor( $refundable ) ) ); ?>
						</span>
						<span class="button-spinner" style="display: none;">
							<span class="dashicons dashicons-update spin"></span>
						</span>
					</button>
					<button type="button" class="button button-link refund-cancel">
						<?php esc_html_e( 'Cancel', 'sugarcommerce' ); ?>
					</button>
				</div>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render fully refunded state
	 *
	 * @return string
	 */
	private function render_fully_refunded(): string {
		$amount_paid = $this->config['amount_paid'];

		ob_start();
		?>
		<div class="wp-flyout-refund-form is-fully-refunded">
			<div class="refund-summary">
				<span class="refund-summary-item">
					<span class="refund-summary-label"><?php esc_html_e( 'Refunded', 'sugarcommerce' ); ?></span>
					<span class="refund-summary-value refund-summary-refunded"><?php echo esc_html( $this->format_amount( $amount_paid ) ); ?></span>
				</span>
				<span class="refund-summary-sep">&middot;</span>
				<span class="refund-summary-item">
					<span class="refund-fully-badge"><?php esc_html_e( 'Fully refunded', 'sugarcommerce' ); ?></span>
				</span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}