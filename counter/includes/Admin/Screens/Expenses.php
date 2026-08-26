<?php
namespace Counter\Admin\Screens;

use Counter\Reports\Expenses as ExpensesClass;
use Counter\Pos\Accounts as AccountsClass;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * P5.4 — booking expenses, confirming recurring drafts, and reading the
 * profit and loss. A plain wp-admin form over Reports\Expenses' own
 * validated create()/confirm()/generate_recurring_drafts(), same "the
 * screen cannot drift from what actually validates" principle as every
 * other Admin\Screens class in this plugin (Accounts, Fulfilment, ...).
 *
 * The formula is printed above the P&L's own numbers, unconditionally —
 * Direction's own instruction: "an owner who can see how the number was
 * made will trust it, and will notice when it is wrong."
 */
class Expenses {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Expenses & P&L', 'counter' ),
			__( 'Expenses & P&L', 'counter' ),
			'cntr_manage_expenses',
			'counter-expenses',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_expenses' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_expenses_action'] ) ) {
			check_admin_referer( 'cntr_expenses_action' );
			$notice = self::handle_post();
		}

		$categories = ExpensesClass::categories();
		$accounts   = AccountsClass::all( 'active' );
		$locations  = Locations::all();
		$drafts     = ExpensesClass::drafts();
		$rules      = ExpensesClass::recurring_rules();

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' );
		$pnl  = ExpensesClass::profit_and_loss( [ 'from' => $from, 'to' => $to, 'channel' => 'all' ] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Expenses & profit and loss', 'counter' ); ?></h1>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<?php if ( ! empty( $drafts ) ) : ?>
				<h2><?php esc_html_e( 'Drafts awaiting confirmation', 'counter' ); ?></h2>
				<p><?php esc_html_e( 'A recurring expense lands here first. Nothing here affects the profit and loss until confirmed.', 'counter' ); ?></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Ref', 'counter' ); ?></th><th><?php esc_html_e( 'Date', 'counter' ); ?></th><th><?php esc_html_e( 'Category', 'counter' ); ?></th><th><?php esc_html_e( 'Amount', 'counter' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $drafts as $d ) : $cat = ExpensesClass::category( (int) $d['category_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $d['ref_no'] ); ?></td>
								<td><?php echo esc_html( $d['spent_on'] ); ?></td>
								<td><?php echo esc_html( $cat['name'] ?? '—' ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $d['amount'] ) ); ?></td>
								<td>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
										<input type="hidden" name="expense_id" value="<?php echo (int) $d['id']; ?>">
										<button type="submit" name="cntr_expenses_action" value="confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'counter' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Book an expense', 'counter' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
				<select name="category_id" required>
					<?php foreach ( $categories as $c ) : ?>
						<option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="location_id" required>
					<?php foreach ( $locations as $l ) : ?>
						<option value="<?php echo (int) $l['id']; ?>"><?php echo esc_html( $l['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="account_id" required>
					<?php foreach ( $accounts as $a ) : ?>
						<option value="<?php echo (int) $a['id']; ?>"><?php echo esc_html( $a['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="channel">
					<option value=""><?php esc_html_e( 'Unallocated', 'counter' ); ?></option>
					<option value="pos"><?php esc_html_e( 'POS', 'counter' ); ?></option>
					<option value="online"><?php esc_html_e( 'Online', 'counter' ); ?></option>
				</select>
				<input type="number" step="0.01" name="amount" placeholder="<?php esc_attr_e( 'Amount', 'counter' ); ?>" required>
				<input type="date" name="spent_on" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
				<input type="text" name="note" placeholder="<?php esc_attr_e( 'Note', 'counter' ); ?>">
				<button type="submit" name="cntr_expenses_action" value="book" class="button button-primary"><?php esc_html_e( 'Book', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Categories', 'counter' ); ?></h2>
			<ul>
				<?php foreach ( $categories as $c ) : ?>
					<li><?php echo esc_html( $c['name'] ); ?> (<?php echo esc_html( $c['code'] ); ?>)</li>
				<?php endforeach; ?>
			</ul>
			<form method="post">
				<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name', 'counter' ); ?>" required>
				<input type="text" name="code" placeholder="<?php esc_attr_e( 'Code', 'counter' ); ?>" required>
				<button type="submit" name="cntr_expenses_action" value="add_category" class="button"><?php esc_html_e( 'Add category', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Recurring expenses', 'counter' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Category', 'counter' ); ?></th><th><?php esc_html_e( 'Amount', 'counter' ); ?></th><th><?php esc_html_e( 'Channel', 'counter' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $rules as $r ) : $cat = ExpensesClass::category( (int) ( $r['category_id'] ?? 0 ) ); ?>
						<tr>
							<td><?php echo esc_html( $cat['name'] ?? '—' ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $r['amount'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( $r['channel'] ?: __( 'unallocated', 'counter' ) ); ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
									<input type="hidden" name="rule_id" value="<?php echo (int) ( $r['id'] ?? 0 ); ?>">
									<button type="submit" name="cntr_expenses_action" value="remove_rule" class="button"><?php esc_html_e( 'Remove', 'counter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post">
				<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
				<select name="category_id" required>
					<?php foreach ( $categories as $c ) : ?>
						<option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="location_id" required>
					<?php foreach ( $locations as $l ) : ?>
						<option value="<?php echo (int) $l['id']; ?>"><?php echo esc_html( $l['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="account_id" required>
					<?php foreach ( $accounts as $a ) : ?>
						<option value="<?php echo (int) $a['id']; ?>"><?php echo esc_html( $a['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="channel">
					<option value=""><?php esc_html_e( 'Unallocated', 'counter' ); ?></option>
					<option value="pos"><?php esc_html_e( 'POS', 'counter' ); ?></option>
					<option value="online"><?php esc_html_e( 'Online', 'counter' ); ?></option>
				</select>
				<input type="number" step="0.01" name="amount" placeholder="<?php esc_attr_e( 'Monthly amount', 'counter' ); ?>" required>
				<button type="submit" name="cntr_expenses_action" value="add_rule" class="button"><?php esc_html_e( 'Add recurring rule', 'counter' ); ?></button>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'cntr_expenses_action' ); ?>
				<input type="text" name="period" placeholder="YYYY-MM" value="<?php echo esc_attr( gmdate( 'Y-m' ) ); ?>">
				<button type="submit" name="cntr_expenses_action" value="generate" class="button button-primary"><?php esc_html_e( "Generate this month's drafts", 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Profit and loss', 'counter' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="counter-expenses">
				<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Run', 'counter' ); ?></button>
			</form>
			<p><em><?php esc_html_e( 'Revenue − COGS = Gross margin. Gross margin − Expenses (channel-tagged, plus a clearly unallocated block) = Net.', 'counter' ); ?></em></p>
			<?php if ( is_wp_error( $pnl ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $pnl->get_error_message() ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Revenue', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['revenue'] ) ); ?></td></tr>
						<?php if ( array_key_exists( 'cogs', $pnl ) ) : ?>
							<tr><th><?php esc_html_e( 'COGS', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['cogs'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Gross margin', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['gross_margin'] ) ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $pnl['expenses_by_category'] as $row ) : ?>
							<tr><td colspan="2">— <?php echo esc_html( $row['category_name'] ?: __( 'Uncategorised', 'counter' ) ); ?>: <?php echo wp_kses_post( wc_price( $row['amount'] ) ); ?></td></tr>
						<?php endforeach; ?>
						<tr><th><?php esc_html_e( 'Expenses — POS', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['expenses_channel']['pos'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Expenses — Online', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['expenses_channel']['online'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Expenses — Unallocated', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['expenses_unallocated'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Total expenses', 'counter' ); ?></th><td><?php echo wp_kses_post( wc_price( $pnl['expenses_total'] ) ); ?></td></tr>
						<?php if ( array_key_exists( 'net', $pnl ) ) : ?>
							<tr><th><?php esc_html_e( 'Net', 'counter' ); ?></th><td><strong><?php echo wp_kses_post( wc_price( $pnl['net'] ) ); ?></strong></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_expenses_action'] ?? '' ) );

		if ( 'add_category' === $action ) {
			$result = ExpensesClass::create_category(
				sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				sanitize_key( wp_unslash( $_POST['code'] ?? '' ) )
			);
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'book' === $action ) {
			$result = ExpensesClass::create(
				[
					'category_id' => absint( $_POST['category_id'] ?? 0 ),
					'location_id' => absint( $_POST['location_id'] ?? 0 ),
					'account_id'  => absint( $_POST['account_id'] ?? 0 ),
					'channel'     => sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ),
					'amount'      => sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) ),
					'spent_on'    => sanitize_text_field( wp_unslash( $_POST['spent_on'] ?? '' ) ),
					'note'        => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
				]
			);
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'confirm' === $action ) {
			$result = ExpensesClass::confirm( absint( $_POST['expense_id'] ?? 0 ) );
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'add_rule' === $action ) {
			ExpensesClass::add_recurring_rule(
				[
					'category_id' => absint( $_POST['category_id'] ?? 0 ),
					'location_id' => absint( $_POST['location_id'] ?? 0 ),
					'account_id'  => absint( $_POST['account_id'] ?? 0 ),
					'channel'     => sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ),
					'amount'      => sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) ),
				]
			);
			return '';
		}

		if ( 'remove_rule' === $action ) {
			ExpensesClass::remove_recurring_rule( absint( $_POST['rule_id'] ?? 0 ) );
			return '';
		}

		if ( 'generate' === $action ) {
			ExpensesClass::generate_recurring_drafts( sanitize_text_field( wp_unslash( $_POST['period'] ?? '' ) ) );
			return '';
		}

		return '';
	}
}
