<?php
/**
 * Frontend plans grid.
 *
 * @package Memberistic
 */

use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$checkout_url = memberistic_get_page_url( 'checkout_page_id', 'memberistic-checkout', '' );
$brand_color  = memberistic_get_setting( 'primary_brand_color', '#0F2044' );
?>
<div class="memberistic-frontend memberistic-plans-grid-wrap" style="--memberistic-brand: <?php echo esc_attr( $brand_color ); ?>;">
	<?php if ( 'yes' === $atts['show_toggle'] ) : ?>
		<div class="memberistic-billing-toggle" role="group" aria-label="<?php esc_attr_e( 'Billing cycle', 'memberistic' ); ?>">
			<button type="button" class="is-active" data-memberistic-cycle="monthly"><?php esc_html_e( 'Monthly', 'memberistic' ); ?></button>
			<button type="button" data-memberistic-cycle="annual"><?php esc_html_e( 'Annual', 'memberistic' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( empty( $plans ) ) : ?>
		<div class="memberistic-placeholder">
			<p><?php esc_html_e( 'No membership plans are available at this time. Please check back soon.', 'memberistic' ); ?></p>
		</div>
	<?php else : ?>
		<div class="memberistic-plan-grid">
			<?php foreach ( $plans as $plan ) : ?>
				<?php
				$benefits = json_decode( (string) $plan['benefits'], true );
				$benefits = is_array( $benefits ) ? $benefits : array();
				$monthly  = (float) $plan['monthly_price'];
				$annual   = (float) $plan['annual_price'];
				$url      = $checkout_url ? add_query_arg( array( 'memberistic_plan' => $plan['slug'] ), $checkout_url ) : '#';

				// Annual savings: difference between 12 months and annual price.
				$annual_savings = ( $monthly * 12 ) - $annual;
				?>
				<article class="memberistic-plan-card<?php echo ! empty( $plan['is_featured'] ) ? ' is-featured' : ''; ?>">
					<?php if ( ! empty( $plan['is_featured'] ) ) : ?>
						<span class="memberistic-featured-badge"><?php esc_html_e( 'Most Popular', 'memberistic' ); ?></span>
					<?php endif; ?>
					<h3><?php echo esc_html( $plan['name'] ); ?></h3>
					<p class="memberistic-plan-description"><?php echo esc_html( $plan['description'] ); ?></p>
					<div class="memberistic-plan-price">
						<span class="memberistic-price-value" data-monthly="<?php echo esc_attr( number_format( $monthly, 2, '.', '' ) ); ?>" data-annual="<?php echo esc_attr( number_format( $annual, 2, '.', '' ) ); ?>">
							$<?php echo esc_html( number_format_i18n( $monthly, 2 ) ); ?>
						</span>
						<small class="memberistic-price-cycle"><?php esc_html_e( '/ month', 'memberistic' ); ?></small>
					</div>
					<?php if ( $annual_savings > 0 ) : ?>
						<span class="memberistic-annual-savings" data-memberistic-savings-label>
							<?php
							printf(
								/* translators: %s: formatted savings amount */
								esc_html__( 'Save $%s / year', 'memberistic' ),
								esc_html( number_format_i18n( $annual_savings, 2 ) )
							);
							?>
						</span>
					<?php endif; ?>
					<p class="memberistic-included-people">
						<?php
						printf(
							/* translators: %d: included people count */
							esc_html__( 'Includes %d total person(s)', 'memberistic' ),
							(int) $plan['included_people']
						);
						?>
					</p>
					<?php if ( $benefits ) : ?>
						<ul class="memberistic-benefits">
							<?php foreach ( $benefits as $benefit ) : ?>
								<li><?php echo esc_html( $benefit ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<a class="memberistic-plan-button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( sprintf( __( 'Choose %s', 'memberistic' ), $plan['name'] ) ); ?></a>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
