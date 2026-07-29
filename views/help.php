<?php
/**
 * Help view: ready made patterns and domain guidance.
 *
 * @package EWUC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders one pattern recipe row.
 *
 * @param array $recipe Recipe data.
 * @return void
 */
function ewuc_render_recipe( array $recipe ): void {
	?>
	<tr>
		<td>
			<strong><?php echo esc_html( (string) $recipe['title'] ); ?></strong>
			<p class="ewuc-note"><?php echo esc_html( (string) $recipe['why'] ); ?></p>
		</td>
		<td>
			<code class="ewuc-pattern" data-ewuc-pattern="<?php echo esc_attr( (string) $recipe['pattern'] ); ?>"><?php echo esc_html( (string) $recipe['pattern'] ); ?></code>
			<button type="button" class="button button-small" data-ewuc-copy="<?php echo esc_attr( (string) $recipe['pattern'] ); ?>">
				<?php esc_html_e( 'Copy', 'ew-user-cleaner' ); ?>
			</button>
		</td>
		<td>
			<ul class="ewuc-samples">
				<?php foreach ( (array) $recipe['matches'] as $ewuc_sample ) : ?>
					<li><span class="ewuc-yes" aria-hidden="true">&#10003;</span> <code><?php echo esc_html( (string) $ewuc_sample ); ?></code></li>
				<?php endforeach; ?>
				<?php foreach ( (array) $recipe['ignores'] as $ewuc_sample ) : ?>
					<li><span class="ewuc-no" aria-hidden="true">&times;</span> <code><?php echo esc_html( (string) $ewuc_sample ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</td>
	</tr>
	<?php
}
?>

<section class="ewuc-card">
	<h2><?php esc_html_e( 'How patterns work', 'ew-user-cleaner' ); ?></h2>

	<p>
		<?php esc_html_e( 'Copy a pattern below and paste it into the matching rule field on the Settings tab. You never need to write the slashes or anchors yourself.', 'ew-user-cleaner' ); ?>
	</p>

	<ul class="ewuc-rules-help">
		<li>
			<strong><?php esc_html_e( 'The whole value must match.', 'ew-user-cleaner' ); ?></strong>
			<?php esc_html_e( 'Patterns are wrapped automatically, so "mary" does not match "mary123". To match anywhere inside a value, wrap it yourself like ".*mary.*".', 'ew-user-cleaner' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Case is ignored.', 'ew-user-cleaner' ); ?></strong>
			<?php esc_html_e( 'Values are lowercased before matching, so write patterns in lowercase only.', 'ew-user-cleaner' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Email patterns test the local part only.', 'ew-user-cleaner' ); ?></strong>
			<?php esc_html_e( 'For john@mail.com the pattern is tested against "john". Use the flagged domain list for the part after the @.', 'ew-user-cleaner' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Start with a low weight.', 'ew-user-cleaner' ); ?></strong>
			<?php esc_html_e( 'Run a scan, review the results, then raise the weight once you trust the pattern. Nothing is ever deleted automatically.', 'ew-user-cleaner' ); ?>
		</li>
	</ul>

	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Regex building blocks', 'ew-user-cleaner' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Piece', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ew-user-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$ewuc_blocks = array(
				'[a-z]'      => __( 'any single letter', 'ew-user-cleaner' ),
				'[0-9]'      => __( 'any single digit', 'ew-user-cleaner' ),
				'{5,}'       => __( 'five or more of the previous piece', 'ew-user-cleaner' ),
				'{2,12}'     => __( 'between two and twelve of the previous piece', 'ew-user-cleaner' ),
				'.*'         => __( 'anything, including nothing', 'ew-user-cleaner' ),
				'(?:a|b)'    => __( 'either a or b', 'ew-user-cleaner' ),
				'\.'         => __( 'a literal dot', 'ew-user-cleaner' ),
			);

			foreach ( $ewuc_blocks as $ewuc_piece => $ewuc_meaning ) :
				?>
				<tr>
					<td><code><?php echo esc_html( $ewuc_piece ); ?></code></td>
					<td><?php echo esc_html( $ewuc_meaning ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="ewuc-card">
	<h2><?php esc_html_e( 'Ready made username patterns', 'ew-user-cleaner' ); ?></h2>
	<p class="ewuc-note"><?php esc_html_e( 'Paste into: Username matches custom pattern.', 'ew-user-cleaner' ); ?></p>

	<div class="ewuc-tablewrap">
		<table class="widefat striped ewuc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Username pattern library', 'ew-user-cleaner' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'What it catches', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pattern', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Matches / ignores', 'ew-user-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( EWUC_Patterns::login_patterns() as $ewuc_recipe ) : ?>
					<?php ewuc_render_recipe( $ewuc_recipe ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="ewuc-card">
	<h2><?php esc_html_e( 'Ready made email patterns', 'ew-user-cleaner' ); ?></h2>
	<p class="ewuc-note"><?php esc_html_e( 'Paste into: Email local part matches custom pattern.', 'ew-user-cleaner' ); ?></p>

	<div class="ewuc-tablewrap">
		<table class="widefat striped ewuc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Email pattern library', 'ew-user-cleaner' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'What it catches', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pattern', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Matches / ignores', 'ew-user-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( EWUC_Patterns::email_patterns() as $ewuc_recipe ) : ?>
					<?php ewuc_render_recipe( $ewuc_recipe ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="ewuc-card">
	<h2><?php esc_html_e( 'Flagged email domains', 'ew-user-cleaner' ); ?></h2>

	<p class="ewuc-warning">
		<?php esc_html_e( 'This field is not a regular expression. Enter plain domain names, one per line.', 'ew-user-cleaner' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'A domain entry also covers all of its subdomains, matched on dot boundaries. So one entry replaces a long list:', 'ew-user-cleaner' ); ?>
	</p>

	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Domain matching examples', 'ew-user-cleaner' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'You enter', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Matches', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Does not match', 'ew-user-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>ff.com</code></td>
				<td>
					<code>ff.com</code>, <code>tt.ff.com</code>,
					<code>xx.ff.com</code>, <code>mm.ff.com</code>
				</td>
				<td><code>notff.com</code>, <code>ffx.com</code>, <code>ff.com.evil.net</code></td>
			</tr>
			<tr>
				<td><code>.ff.com</code></td>
				<td><code>xx.ff.com</code></td>
				<td><code>ff.com</code> <?php esc_html_e( '(leading dot means subdomains only)', 'ew-user-cleaner' ); ?></td>
			</tr>
			<tr>
				<td><code>att.net</code></td>
				<td><code>txt.att.net</code>, <code>mms.att.net</code></td>
				<td><code>battnet.net</code></td>
			</tr>
		</tbody>
	</table>

	<p class="ewuc-note">
		<?php esc_html_e( 'Answering the common question: yes, entering ff.com is enough to cover xx.ff.com and mm.ff.com. You do not need to list each subdomain. Matching stops at dot boundaries, so a short entry cannot accidentally catch an unrelated longer domain.', 'ew-user-cleaner' ); ?>
	</p>

	<h3><?php esc_html_e( 'Starter lists', 'ew-user-cleaner' ); ?></h3>
	<p class="ewuc-note">
		<?php esc_html_e( 'Verify these against your own users before enabling the domain rule. A carrier gateway can belong to a real customer.', 'ew-user-cleaner' ); ?>
	</p>

	<div class="ewuc-fieldgrid">
		<?php foreach ( EWUC_Patterns::domain_examples() as $ewuc_group => $ewuc_domains ) : ?>
			<div>
				<h4><?php echo esc_html( (string) $ewuc_group ); ?></h4>
				<textarea class="large-text code" rows="5" readonly
					aria-label="<?php echo esc_attr( (string) $ewuc_group ); ?>"><?php echo esc_textarea( implode( "\n", $ewuc_domains ) ); ?></textarea>
				<button type="button" class="button button-small" data-ewuc-copy="<?php echo esc_attr( implode( "\n", $ewuc_domains ) ); ?>">
					<?php esc_html_e( 'Copy list', 'ew-user-cleaner' ); ?>
				</button>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="ewuc-note">
		<?php esc_html_e( 'Pasted values are cleaned on save. Schemes, ports, paths and anything before an @ are stripped, and entries without a valid domain shape are discarded.', 'ew-user-cleaner' ); ?>
	</p>
</section>

<p class="ewuc-message" data-ewuc-copy-message role="status" aria-live="polite"></p>
