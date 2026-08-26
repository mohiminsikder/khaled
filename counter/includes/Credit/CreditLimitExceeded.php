<?php
namespace Counter\Credit;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown from Orders\Builder::build()'s $before_save guard (Rest\Sale::process()
 * constructs it) the instant a credit sale would push a customer over their
 * limit — caught before $order->save() ever runs, so nothing was persisted.
 * A plain \RuntimeException would work too; a named type lets the caller
 * catch this specific refusal without swallowing every other build-time
 * failure the same way.
 */
class CreditLimitExceeded extends \RuntimeException {}
