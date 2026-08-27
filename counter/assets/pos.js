/**
 * Counter POS terminal. Plain ES modules, no build step, no framework, no CDN —
 * the terminal must run offline, so nothing here may load from an external host.
 *
 * A full-screen till that finds a product in under 5 ms and never waits on the
 * network to build a cart. The catalogue lives in IndexedDB; search runs
 * against an in-memory index built from it at boot. Never call the REST
 * catalogue endpoint on the hot path of typing/scanning — only the 60s poll
 * and visibilitychange refresh it.
 */

(() => {
	'use strict';

	const CFG = window.CNTR || {};

	/**
	 * U2 — "a cashier should be able to run the terminal entirely in
	 * Bengali" (P8.3's own words) was never actually possible: pos.js had no
	 * translation mechanism at all, and P8.3's own test_i18n() only ever
	 * audited PHP files, so the gap went uncaught through the whole plan.
	 * `templates/pos.php` now bootstraps CFG.strings with the SAME keys
	 * below, each a real PHP __('...', 'counter') call — this Object.assign
	 * is the merge point: a translated value wins when the bootstrap
	 * supplies one, this English literal is the fallback otherwise (a
	 * fixture/harness that predates this, or a key the bootstrap somehow
	 * omits, still renders correctly, just untranslated). Every %n%-style
	 * placeholder is filled by a plain .replace() at the call site, never a
	 * full templating library — the same "no framework" rule as the rest of
	 * this file.
	 */
	const STRINGS = Object.assign(
		{
			netOnline: 'Online',
			netOffline: 'Offline',
			outboxLocked: 'Offline too long — reconnect to keep selling',
			shiftLabel: 'Shift',
			panelCustomerTitle: 'Customer',
			panelCartTitle: 'Cart',
			panelGridTitle: 'Products',
			priceGroupLabel: 'Prices:',
			priceGroupRegister: 'Register price',
			priceGroupActive: 'Cart re-priced for %name%',
			gridAllCategories: 'All',
			lowStockBadge: 'Low stock',
			walkInPlaceholder: 'Walk-in — F6 to attach a customer',
			customerOwes: 'Owes',
			customerLimit: 'Limit',
			customerAvailable: 'Available',
			customerOldestDue: 'Oldest due %n% days',
			clearCustomerAria: 'Clear customer',
			usualItemsLabel: 'Usual:',
			searchPlaceholder: 'Scan or type SKU / barcode / name',
			cartTotal: 'Total:',
			footerItemsLabel: 'Items',
			footerDiscountLabel: 'Discount',
			footerTaxLabel: 'Order tax',
			footerShippingLabel: 'Shipping',
			footerRoundOffLabel: 'Round off',
			footerTotalLabel: 'Total',
			footerEditAria: 'Edit %field%',
			orderDiscountTitle: 'Order discount',
			orderTaxTitle: 'Order tax',
			orderShippingTitle: 'Shipping',
			amountLabel: 'Amount',
			outOfStockBadge: 'Out of stock',
			stockInUnit: '%qty% %unit% left',
			editSubtotalAria: 'Line subtotal',
			decreaseQtyAria: 'Decrease quantity',
			increaseQtyAria: 'Increase quantity',
			removeLineAria: 'Remove line',
			keyPay: 'Pay',
			keyQty: 'Qty',
			keyDiscount: 'Discount',
			keyNewItem: 'New item',
			keyCustomer: 'Customer',
			keyHold: 'Hold',
			keyResume: 'Resume',
			keyReturn: 'Return',
			keyNoSale: 'No-sale',
			keyVoid: 'Void',

			searchNoMatches: 'No matches for',

			attachCustomerTitle: 'Attach customer',
			customerPhoneLabel: 'Customer phone',
			findBtn: 'Find',
			cancelBtn: 'Cancel',
			multipleMatches: 'More than one match — pick the right one.',
			lastOrderPrefix: 'Last order',
			noCustomerFound: 'No customer found for %phone%.',
			billAsWalkin: 'Bill as walk-in',
			noNamePlaceholder: '(no name)',

			takePaymentTitle: 'Take payment —',
			addAnotherMethod: 'Add another method',
			tenderDue: 'Due:',
			tenderTaken: 'Taken:',
			tenderRemaining: 'Remaining:',
			tenderChange: 'Change:',
			takePaymentPrint: 'Take payment & print',
			creditOnAccount: 'Credit (on account)',
			removeTenderRowAria: 'Remove tender row',
			offlineCreditNote: "Offline — a credit tender's limit is checked once this sale syncs, not now.",
			accountAfterSaleNoLimit: 'On account after this sale: %after% (no credit limit set).',
			accountAfterSaleWithLimit: 'On account after this sale: %after% of %limit% limit.',
			exceedsAvailableCredit: "Exceeds this customer's available credit (%available% left).",
			creditExceedsDue: 'Credit cannot exceed the total due.',
			methodCash: 'Cash',
			methodCard: 'Card',
			methodBkash: 'bKash',
			methodNagad: 'Nagad',
			methodRocket: 'Rocket',
			methodBank: 'Bank',
			methodChange: 'Change',

			changeQtyTitle: 'Change quantity —',
			qtyLabel: 'Quantity',
			applyBtn: 'Apply',
			removeLineConfirm: 'Remove this line?',

			discountTitle: 'Discount —',
			amountOffLabel: 'Amount off (৳)',
			percentOffLabel: 'Percent off (%)',
			applyDiscountBtn: 'Apply discount',
			overridePriceLabel: 'Override price to (৳)',
			applyOverrideBtn: 'Apply override',
			discountAboveCeiling: "That's %pct%% off — above the %ceiling%% ceiling.",

			noSaleTitle: 'No sale — open drawer',
			reasonLabel: 'Reason',
			openDrawerPrint: 'Open drawer & print',
			reasonRequired: 'A reason is required.',
			noSaleFailed: 'Could not record the no-sale — try again once the till is back online.',
			noSaleSlipTitle: 'NO SALE — DRAWER OPENED',

			quickAddTitle: 'Quick-add a product',
			nameLabel: 'Name',
			priceLabel: 'Price (৳)',
			barcodeSkuLabel: 'Barcode / SKU',
			unitLabel: 'Unit',
			openingQtyLabel: 'Opening quantity',
			addProductBtn: 'Add product',
			quickAddValidation: 'A name and a positive price are required.',
			quickAddFailed: 'Could not add this product — try again.',

			heldSalesTitle: 'Held sales',
			justNow: 'just now',
			minsAgo: '%n%m ago',
			hoursMinsAgo: '%h%h %n%m ago',
			replaceCartConfirm: 'This will replace the current cart with the held ticket. Continue?',
			walkIn: 'Walk-in',
			itemSingular: 'item',
			itemPlural: 'items',

			returnExchangeTitle: 'Return / exchange',
			receiptNumberLabel: 'Original receipt number',
			couldNotFindReceipt: 'Could not find that receipt.',
			returnTitlePrefix: 'Return — receipt',
			ofPrefix: 'of',
			remainingSuffix: 'left',
			refundTotalLabel: 'Refund total:',
			methodLabel: 'Method',
			refundPrintBtn: 'Refund & print',
			selectAtLeastOneItem: 'Select at least one item to return.',
			registerOfflineReturn: 'This register is offline — a return needs a live connection. Try again once reconnected.',
			returnRefused: 'The return was refused.',
			returnReceiptTitle: 'RETURN — against receipt',
			refundedLabel: 'Refunded:',
			reasonPrefix: 'Reason:',

			capDenyDiscountLine: "You don't have permission to discount this line.",
			capDenyNoSale: "You don't have permission to record a no-sale.",
			capDenyVoidLine: "You don't have permission to void a line.",
			capDenyManageStock: "You don't have permission to quick-add a product.",

			clearCartConfirm: 'Clear the entire cart?',

			provisionalBillTitle: 'PROVISIONAL BILL — NOT A VAT INVOICE',
			offlineSaleNotice:
				'This sale was rung offline. Its official Mushak 6.3 challan issues once the till reconnects and this receipt syncs.',
			receiptLabel: 'Receipt:',
			offlinePendingSync: '(offline — pending sync)',
			paidNowLabel: 'Paid now:',
			onAccountOfflineNote: 'On account: %amount% — the credit limit will be checked when this sale syncs, not now.',

			noShiftOpenTitle: 'No shift is open on this register',
			openingFloatLabel: 'Opening float (৳)',
			openShiftBtn: 'Open shift',

			xReportAria: 'X-report',
			xReportTitle: 'X-report',
			xReportFailed: 'Could not load the X-report — try again.',
			sellByMethodLabel: 'Sell by method',
			refundByMethodLabel: 'Refund by method',
			expenseByMethodLabel: 'Expense by method',
			totalSalesLabel: 'Total sales',
			totalRefundLabel: 'Total refund',
			totalExpenseLabel: 'Total expense',
			expectedCashLabel: 'Expected cash',
			productsSoldLabel: 'Products sold',
			skuLabel: 'SKU',
			qtySoldLabel: 'Qty',
			formulaSentence: 'Opening %opening% + cash sale %sale% − cash refund %refund% − cash expense %expense% = expected %expected%',
			noneLabel: 'None',

			closeRegisterAria: 'Close register',
			closeRegisterTitle: 'Close register',
			capDenyCloseShift: "You don't have permission to close this register.",
			countedCashLabel: 'Counted cash (৳)',
			varianceLabel: 'Variance',
			varianceShort: 'Short by %amount%',
			varianceOver: 'Over by %amount%',
			varianceExact: 'Exact count',
			confirmCloseBtn: 'Confirm & print',
			closeRegisterFailed: 'Could not close the register — try again.',
			closeSlipTitle: 'REGISTER CLOSED',
			countedCashLabelShort: 'Counted',
		},
		CFG.strings || {}
	);

	/** Fills %key% placeholders — the one substitution shape every translated string above uses. */
	function fmt(str, vars) {
		let out = str;
		Object.keys(vars || {}).forEach((k) => {
			out = out.replace(new RegExp(`%${k}%`, 'g'), vars[k]);
		});
		return out;
	}

	/**
	 * A2 — none of the till's modals are real <form> elements (no native
	 * Enter-to-submit), so every text input that has one obvious next action
	 * needs it bound explicitly. Swallows the keystroke so it never falls
	 * through to the scanner/global keydown listener behind the modal.
	 */
	function submitOnEnter(el, handler) {
		if (!el) return;
		el.addEventListener('keydown', (e) => {
			if ('Enter' === e.key) {
				e.preventDefault();
				handler();
			}
		});
	}

	/**
	 * U2 — "format money as ৳ 1,425.00 consistently — the Reports screen's
	 * raw 4-decimal output is the counter-example to avoid." Every DISPLAY
	 * of an amount goes through this; the underlying decimal strings
	 * (unitPrice, discount, tender amounts, …) stay exactly as they are
	 * everywhere else in this file — this only ever touches what's shown,
	 * never what's stored, submitted, or computed with.
	 *
	 * Bangladeshi digit grouping, not Western: ৳1,00,000.00, not
	 * ৳100,000.00 — the last three digits as one group, then groups of two
	 * working left. A customer's credit limit or a big day's total is
	 * exactly where this actually shows up; getting it wrong reads as ten
	 * times the real amount to anyone used to reading taka.
	 */
	function formatMoney(value) {
		const num = parseFloat(value) || 0;
		const negative = num < 0;
		const fixed = Math.abs(num).toFixed(2);
		const [intPart, decPart] = fixed.split('.');
		let grouped = intPart.slice(-3);
		let rest = intPart.slice(0, -3);
		while (rest.length > 0) {
			grouped = rest.slice(-2) + ',' + grouped;
			rest = rest.slice(0, -2);
		}
		return `৳ ${negative ? '-' : ''}${grouped}.${decPart}`;
	}

	/**
	 * U2 — the three client-BUILT print documents (offline receipt, return
	 * receipt, no-sale slip) are each a standalone `<html>` string written
	 * straight into the print iframe (see printReceipt()) — none of them
	 * inherit pos.css, so none of them ever had a Bengali-capable font
	 * declared at all, unlike templates/receipt-79.php's own real server-
	 * rendered receipt. Same stack that file already uses.
	 */
	const RECEIPT_FONT_STYLE = "font-family:'Noto Sans Bengali','Segoe UI',sans-serif;";

	const DB_NAME = 'cntr_pos';
	const DB_VERSION = 3; // 3: F8 adds the 'held' store, catalog/outbox untouched
	const STORE = 'catalog';
	const OUTBOX_STORE = 'outbox';
	const HELD_STORE = 'held';
	const CURSOR_KEY = 'cntr_catalog_cursor';
	const POLL_MS = 60000;
	const OUTBOX_DRAIN_MS = 15000;
	const OUTBOX_BACKOFF_BASE_MS = 5000;
	const OUTBOX_BACKOFF_MAX_MS = 300000; // capped at 5 minutes
	const OUTBOX_MAX_ATTEMPTS = 8; // the retry budget (P7.3) — past this, even a non-422 failure stops being retried
	const OUTBOX_MAX_AGE_HOURS = 72; // the age lock (P7.6) — Direction's own number

	// -- IndexedDB -----------------------------------------------------------

	function openDb() {
		return new Promise((resolve, reject) => {
			const req = indexedDB.open(DB_NAME, DB_VERSION);
			req.onupgradeneeded = () => {
				const db = req.result;
				if (!db.objectStoreNames.contains(STORE)) {
					const store = db.createObjectStore(STORE, { keyPath: 'id' });
					store.createIndex('barcode', 'barcode', { unique: false });
					store.createIndex('sku', 'sku', { unique: false });
				}
				if (!db.objectStoreNames.contains(OUTBOX_STORE)) {
					// keyPath 'uuid' IS the unique index Direction refers to
					// ("the unique index makes a double drain harmless") —
					// put() on an existing uuid overwrites in place, it can
					// never create a second local record for the same sale.
					db.createObjectStore(OUTBOX_STORE, { keyPath: 'uuid' });
				}
				if (!db.objectStoreNames.contains(HELD_STORE)) {
					// F8 — a parked ticket that only ever lived in cart.heldSales
					// (a plain array) vanished on a refresh or a crash; this
					// store is what survives one, alongside the catalogue and
					// outbox stores it sits next to.
					db.createObjectStore(HELD_STORE, { keyPath: 'id' });
				}
			};
			req.onsuccess = () => resolve(req.result);
			req.onerror = () => reject(req.error);
		});
	}

	function idbPutAll(db, rows) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite');
			const store = tx.objectStore(STORE);
			rows.forEach((row) => store.put(row));
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	function idbDeleteAll(db, ids) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite');
			const store = tx.objectStore(STORE);
			ids.forEach((id) => store.delete(id));
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	function idbGetAll(db) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readonly');
			const req = tx.objectStore(STORE).getAll();
			req.onsuccess = () => resolve(req.result || []);
			req.onerror = () => reject(req.error);
		});
	}

	/** P8.1 — the fresh start a full snapshot pull needs before repopulating. */
	function idbClearCatalog(db) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite');
			tx.objectStore(STORE).clear();
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	// -- Outbox (P7.2) ------------------------------------------------------------
	//
	// "A completed sale goes into a local outbox keyed by its UUID. A sync
	// worker drains it whenever the connection returns, posting each sale to
	// THE SAME ENDPOINT an online sale uses — no second code path." submitSale()
	// below always attempts that same fetch() first; only a genuine network
	// failure (never a real 4xx/5xx business rejection from a server that was
	// actually reached) falls back to queuing here.

	function idbOutboxPut(db, record) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(OUTBOX_STORE, 'readwrite');
			tx.objectStore(OUTBOX_STORE).put(record);
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	function idbOutboxGetAll(db) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(OUTBOX_STORE, 'readonly');
			const req = tx.objectStore(OUTBOX_STORE).getAll();
			req.onsuccess = () => resolve(req.result || []);
			req.onerror = () => reject(req.error);
		});
	}

	function idbOutboxDelete(db, uuid) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(OUTBOX_STORE, 'readwrite');
			tx.objectStore(OUTBOX_STORE).delete(uuid);
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	// -- Held sales (F8) -----------------------------------------------------------

	function idbHeldPut(db, record) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(HELD_STORE, 'readwrite');
			tx.objectStore(HELD_STORE).put(record);
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	function idbHeldGetAll(db) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(HELD_STORE, 'readonly');
			const req = tx.objectStore(HELD_STORE).getAll();
			req.onsuccess = () => resolve(req.result || []);
			req.onerror = () => reject(req.error);
		});
	}

	function idbHeldDelete(db, id) {
		return new Promise((resolve, reject) => {
			const tx = db.transaction(HELD_STORE, 'readwrite');
			tx.objectStore(HELD_STORE).delete(id);
			tx.oncomplete = () => resolve();
			tx.onerror = () => reject(tx.error);
		});
	}

	/** A queued sale is never lost even if this tab closes before the next drain — everything a retry needs lives in IndexedDB, not memory. */
	async function queueSale(body) {
		const db = await openDb();
		await idbOutboxPut(db, { uuid: body.uuid, body, attempts: 0, nextRetryAt: 0, createdAt: Date.now() });
	}

	/**
	 * Posts each queued sale to POST {restUrl}/sale — the exact same call
	 * submitSale() itself makes online, never a second endpoint or a
	 * different payload shape. A record is deleted from the outbox only on
	 * a real 2xx — Invariant IV's own idempotency (the uuid's UNIQUE KEY on
	 * cntr_sale_queue, server-side) is what makes retrying a sale that
	 * actually already landed harmless, so any failure here — network or a
	 * server error — just backs off and tries again rather than needing to
	 * tell the two cases apart.
	 */
	/**
	 * Every entry still awaiting a real outcome — 'failed_permanent' is
	 * excluded, which is the whole of what "does not count toward the
	 * [72-hour] age lock" (P7.6) means: that lock, when it exists, computes
	 * the oldest entry's age from exactly this list, never the raw outbox.
	 */
	function pendingOutboxEntries(entries) {
		return entries.filter((e) => 'failed_permanent' !== e.status);
	}

	/**
	 * "An outbox older than [72] hours puts the register into read-only
	 * until it syncs" (P7.6). Never a stored flag — a live computation over
	 * the SAME pendingOutboxEntries() list drainOutbox() itself iterates,
	 * keyed off the oldest entry still awaiting a real outcome. The instant
	 * that list drains (a real sync), the very next call here reads
	 * unlocked again, with nothing to separately clear.
	 */
	function isOutboxLocked(entries, now) {
		const pending = pendingOutboxEntries(entries);
		if (!pending.length) return false;
		const oldestCreatedAt = Math.min(...pending.map((e) => e.createdAt));
		return (now - oldestCreatedAt) / 3600000 > OUTBOX_MAX_AGE_HOURS;
	}

	async function drainOutbox() {
		const db = await openDb();
		const entries = await idbOutboxGetAll(db);
		const now = Date.now();
		for (const entry of pendingOutboxEntries(entries)) {
			if (entry.nextRetryAt > now) continue;
			let res = null;
			try {
				res = await fetch(`${CFG.restUrl}/sale`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': CFG.nonce,
						'X-CNTR-Register': String(CFG.registerId || ''),
					},
					credentials: 'same-origin',
					body: JSON.stringify(entry.body),
				});
			} catch (e) {
				res = null; // a genuine network failure — no response to read a status from
			}

			if (res && res.ok) {
				await idbOutboxDelete(db, entry.uuid);
				continue;
			}

			entry.attempts += 1;

			// "An outbox entry that can never succeed... must not hold a
			// register hostage" (P7.3, audit finding T6): a 422 IS the
			// server's own definitive "this will never succeed" signal
			// (Rest\Sale::process() already marks cntr_sale_queue
			// failed_permanent for exactly this — a deleted product, a bad
			// line — the moment it happens, never retried server-side
			// either); anything else only gives up after OUTBOX_MAX_ATTEMPTS,
			// the retry budget Direction's own words name explicitly.
			const permanent = (res && 422 === res.status) || entry.attempts >= OUTBOX_MAX_ATTEMPTS;
			if (permanent) {
				entry.status = 'failed_permanent';
				let reason = 'Exceeded the retry budget with no further detail available.';
				if (res) {
					try {
						const body = await res.json();
						reason = body.message || `Server rejected with status ${res.status}`;
					} catch (e) {
						reason = `Server rejected with status ${res.status}`;
					}
				}
				entry.failReason = reason;
				await idbOutboxPut(db, entry);
				continue;
			}

			// Exponential, capped — 5s, 10s, 20s, 40s, ... up to 5 minutes.
			entry.nextRetryAt = now + Math.min(OUTBOX_BACKOFF_MAX_MS, OUTBOX_BACKOFF_BASE_MS * Math.pow(2, entry.attempts - 1));
			await idbOutboxPut(db, entry);
		}
	}

	/**
	 * A receipt built entirely client-side, from the cart data already in
	 * memory — the server that would normally render one is exactly what is
	 * unreachable right now. Not byte-identical to Docs\Receipt::render()'s
	 * own output; good enough for the walk-in customer who needs paper in
	 * hand immediately, the same "the walk-in customer got paper" reasoning
	 * already applied to POS emails (P1.12).
	 *
	 * P7.7 — under the 'provisional' offline.challan_policy, this paper is
	 * explicitly NOT a VAT invoice: no serial exists yet, since Docs\Challan
	 * only ever issues one once this exact sale reaches the server for real
	 * (see Rest\Sale::process()'s own step 8) — printed here, offline, that
	 * has not happened. Direction's own words: "clearly marked as a
	 * provisional bill," not a subtle footnote.
	 */
	function buildOfflineReceiptHtml(body) {
		const rows = body.lines
			.map((l) => `<tr><td>${escapeHtml(String(l.qty))}</td><td>${escapeHtml(l.unit_price)}</td></tr>`)
			.join('');
		const orderTotal = body.lines.reduce(
			(sum, l) => sum + parseFloat(l.unit_price) * parseFloat(l.qty) - (parseFloat(l.discount) || 0),
			0
		);
		const tendered = body.tenders.reduce((sum, t) => (t.is_change ? sum - parseFloat(t.amount) : sum + parseFloat(t.amount)), 0);
		// F4 — the credit row is never a real tender (see submitTenderModal()),
		// so a partly- or wholly-on-account offline sale shows LESS tendered
		// than the total; that gap is what CustomerLedger::check_limit() will
		// actually check once this receipt syncs, not now — the till cannot
		// know offline whether it's still within limit.
		const shortfall = orderTotal - tendered;
		const hasCreditCustomer = body.customer && body.customer.customer_id > 0;
		return `<html><body style="${RECEIPT_FONT_STYLE}">
			<p style="font-weight:bold;font-size:1.2em;border:2px solid #000;padding:4px;text-align:center;">${STRINGS.provisionalBillTitle}</p>
			<p>${STRINGS.offlineSaleNotice}</p>
			<p>${STRINGS.receiptLabel} ${escapeHtml(body.receipt_no)} ${STRINGS.offlinePendingSync}</p>
			<table>${rows}</table>
			<p>${STRINGS.cartTotal} ${formatMoney(orderTotal)}</p>
			${
				shortfall > 0.0001 && hasCreditCustomer
					? `<p>${STRINGS.paidNowLabel} ${formatMoney(tendered)}</p>
						<p style="font-weight:bold;">${fmt(STRINGS.onAccountOfflineNote, { amount: formatMoney(shortfall) })}</p>`
					: ''
			}
		</body></html>`;
	}

	// -- Catalogue sync --------------------------------------------------------

	/**
	 * X-CNTR-Register: which register's price group applies to the prices
	 * this call gets back — see Rest\Catalog::apply_register_prices() and
	 * docs/decisions.md (the P4.3 price-group wiring gap). Every other
	 * register-scoped call already sends this same header; the catalogue
	 * fetch never had until now, which is exactly why a register's
	 * configured price group never reached a real till.
	 */
	async function fetchDelta(since, snapshot) {
		const url = `${CFG.restUrl}/catalog?since=${encodeURIComponent(since)}&limit=500${snapshot ? '&snapshot=1' : ''}`;
		const res = await fetch(url, {
			headers: { 'X-WP-Nonce': CFG.nonce, 'X-CNTR-Register': String(CFG.registerId || '') },
			credentials: 'same-origin',
		});
		if (!res.ok) throw new Error(`catalog fetch failed: ${res.status}`);
		return res.json();
	}

	/**
	 * P8.1 — a resnapshot response means the delta set is too large to
	 * stream incrementally. Retrying delta() itself from cursor 0 can never
	 * succeed once the catalogue's own TOTAL size exceeds catalog.delta_cap
	 * — every retry re-trips the identical total-count check, forever.
	 * Found live, at P8.1's own realistic-scale load test: a terminal
	 * booting cold against a real-shape 14,000-product catalogue could
	 * never finish syncing. The real full pull is a genuinely different,
	 * uncapped endpoint (?snapshot=1 — Stock\Catalog::snapshot()), paged
	 * the same shape, starting from a cleared local cache, until a page
	 * comes back shorter than the limit.
	 */
	async function pullFullSnapshot(db) {
		await idbClearCatalog(db);
		let cursor = 0;
		for (;;) {
			const page = await fetchDelta(cursor, true);
			if (page.changed && page.changed.length) {
				await idbPutAll(db, page.changed);
			}
			cursor = page.cursor;
			if (page.changed.length < 500) break; // caught up
		}
		return cursor;
	}

	async function syncCatalog(db) {
		let cursor = parseInt(localStorage.getItem(CURSOR_KEY) || '0', 10);
		for (let guard = 0; guard < 5; guard++) {
			const delta = await fetchDelta(cursor, false);
			if (delta.resnapshot) {
				cursor = await pullFullSnapshot(db);
				localStorage.setItem(CURSOR_KEY, String(cursor));
				break;
			}
			if (delta.changed && delta.changed.length) {
				// The payload's own "id" is the entity's product/variation id
				// already, matching the object store's keyPath directly.
				await idbPutAll(db, delta.changed);
			}
			if (delta.removed && delta.removed.length) {
				await idbDeleteAll(db, delta.removed.map((r) => r.product_id));
			}
			cursor = delta.cursor;
			localStorage.setItem(CURSOR_KEY, String(cursor));
			if (delta.changed.length < 500) break; // caught up
		}
	}

	// -- Performance instrumentation (P1.18) -------------------------------------
	// The budget is measured, not asserted. Every timing is kept locally (last
	// 500, capped) and only a p50/p95 ROLLUP — never the raw log — is posted
	// once per calendar day, so this never adds network traffic to the hot path
	// it is measuring.

	const PERF_KEY = 'cntr_perf_log';
	const PERF_LAST_POST_KEY = 'cntr_perf_last_post_date';
	const PERF_MAX_RECORDS = 500;
	const PERF_TYPES = ['lookup', 'add_line', 'post', 'total'];

	function perfLoad() {
		try {
			const raw = JSON.parse(localStorage.getItem(PERF_KEY) || '[]');
			return Array.isArray(raw) ? raw : [];
		} catch (e) {
			return [];
		}
	}

	function perfRecord(type, ms) {
		const log = perfLoad();
		log.push({ ts: Date.now(), type, ms });
		while (log.length > PERF_MAX_RECORDS) log.shift();
		try {
			localStorage.setItem(PERF_KEY, JSON.stringify(log));
		} catch (e) {
			// Storage full or unavailable (private browsing) — drop the
			// measurement, never the sale it was measuring.
		}
		maybePostDailySummary(log);
	}

	function percentile(values, p) {
		if (!values.length) return null;
		const sorted = values.slice().sort((a, b) => a - b);
		const idx = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
		return sorted[idx];
	}

	function summarizePerf(log) {
		const summary = { count: log.length };
		PERF_TYPES.forEach((t) => {
			const values = log.filter((r) => r.type === t).map((r) => r.ms);
			summary[t] = { p50: percentile(values, 50), p95: percentile(values, 95), n: values.length };
		});
		return summary;
	}

	function maybePostDailySummary(log) {
		const today = new Date().toISOString().slice(0, 10);
		if (localStorage.getItem(PERF_LAST_POST_KEY) === today) return;
		if (!log.length || !CFG.restUrl) return;
		const summary = summarizePerf(log);
		// F9 — CFG.version was bootstrapped and never read anywhere: which
		// build a day's numbers came from is exactly the kind of thing a
		// stale-cache mismatch (a redeploy that bumped the PHP but not this
		// asset's own cache-buster) makes invisible without it.
		summary.version = CFG.version || '';
		fetch(`${CFG.restUrl}/perf`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
			body: JSON.stringify({ date: today, summary }),
		})
			.then((res) => {
				if (res.ok) localStorage.setItem(PERF_LAST_POST_KEY, today);
			})
			.catch(() => {
				// Offline or the request failed — try again on the next
				// recorded event rather than losing the day's numbers.
			});
	}

	// -- In-memory search index -------------------------------------------------

	let INDEX = { byBarcode: new Map(), bySku: new Map(), all: [] };

	// B1 — null means "All categories".
	let gridCategoryFilter = null;

	function buildIndex(rows) {
		const byBarcode = new Map();
		const bySku = new Map();
		rows.forEach((r) => {
			if (r.barcode) byBarcode.set(r.barcode, r);
			if (r.sku) bySku.set(r.sku, r);
		});
		INDEX = { byBarcode, bySku, all: rows };
	}

	/**
	 * F6 — searchFields (window.CNTR, a settings-configured CSV, default
	 * 'sku,barcode,name') decides which row properties count as a match; a
	 * shop that only ever wants name search (SKUs collide across brands, say)
	 * can narrow it without a code change. Falls back to the same default if
	 * unset, so a fixture/bootstrap that predates this still behaves exactly
	 * as searchByText() always did.
	 */
	function searchByText(q) {
		const needle = q.trim().toLowerCase();
		if (!needle) return [];
		const fields = String(CFG.searchFields || 'sku,barcode,name')
			.split(',')
			.map((f) => f.trim())
			.filter(Boolean);
		return INDEX.all.filter((r) => fields.some((f) => String(r[f] || '').toLowerCase().includes(needle))).slice(0, 20);
	}

	/**
	 * B1 — every unique (id, name) pair across the synced catalogue, in
	 * first-seen order. Unnamed categories (get_terms() failed, or the id
	 * has no name for some other reason) are dropped rather than shown as
	 * a blank chip — an empty chip label filters to nothing useful anyway.
	 */
	function gridCategories() {
		const seen = new Map();
		INDEX.all.forEach((r) => {
			(r.category_ids || []).forEach((id, i) => {
				const name = (r.category_names || [])[i];
				if (name && !seen.has(id)) seen.set(id, name);
			});
		});
		return Array.from(seen, ([id, name]) => ({ id, name }));
	}

	/** Rows for the tile grid, honouring gridCategoryFilter. */
	function gridProducts() {
		if (null === gridCategoryFilter) return INDEX.all;
		return INDEX.all.filter((r) => (r.category_ids || []).includes(gridCategoryFilter));
	}

	/**
	 * Same "empty/null means don't alert" semantics as the server's own
	 * Reports::reorder_list() — a product with no threshold configured
	 * never carries the marker, at any stock level.
	 */
	function isLowStock(row) {
		const threshold = row.low_stock_amount;
		if (null === threshold || undefined === threshold || '' === threshold) return false;
		return parseFloat(row.sellable_qty) <= parseFloat(threshold);
	}

	/** The one lookup path both the scanner and the search box resolve a code through. */
	function lookupProduct(code) {
		const weight = /^\d{13}$/.test(code) ? parseWeightBarcode(code) : null;
		if (weight) {
			const match = INDEX.bySku.get(weight.sku);
			return match ? { ...match, price: weight.price } : null;
		}
		return INDEX.byBarcode.get(code) || INDEX.bySku.get(code) || null;
	}

	function timedLookup(code) {
		const t0 = performance.now();
		const match = lookupProduct(code);
		perfRecord('lookup', performance.now() - t0);
		return match;
	}

	// -- Weight-embedded barcodes ------------------------------------------------
	// EAN-13 with a configurable prefix (commonly '2'). A per-shop rule decides
	// which digits carry the SKU and which carry weight/price. Parsed BEFORE
	// lookup, since the raw scanned code never matches a stored barcode.

	function parseWeightBarcode(code) {
		const prefix = String(CFG.weightBarcodePrefix || '2');
		if (code.length !== 13 || code[0] !== prefix) return null;
		// Common convention: digits 1-6 = SKU (padded), 7-11 = price (poisha),
		// 12 = check digit. This is the default rule; a per-shop override lands
		// with the settings screen this task does not build.
		const sku = code.slice(1, 6).replace(/^0+/, '');
		const priceDigits = code.slice(6, 11);
		const price = (parseInt(priceDigits, 10) / 100).toFixed(2);
		return { sku, price };
	}

	// -- Scanner detection -------------------------------------------------------
	// USB barcode scanners are HID keyboard-wedge devices: they type and press
	// Enter. A burst of >= 6 characters with every gap under 35ms, terminated by
	// Enter, is a scan; a person cannot type that fast. Swallow the event so a
	// scan never lands inside whatever input happens to be focused.

	let buf = '';
	let last = 0;
	let fastRun = true;

	/**
	 * F1 — closes the race found while building F0's DOM harness (see
	 * docs/decisions.md, "A single scan currently auto-checks-out..."): the
	 * search box's own 'input' listener exact-matches and clears the field
	 * mid-keystroke, before this scanner's buf/Enter detector ever runs, so
	 * the scanner's trailing Enter — which every real keyboard-wedge
	 * scanner sends automatically — used to misfire against a field that
	 * was already handled. Set true the instant the input listener clears
	 * the field on its own (matched-and-added, or an unmatched
	 * weight-shaped code); the very next Enter is then a no-op tail of that
	 * SAME keystroke sequence, not a fresh signal.
	 */
	let suppressNextEnter = false;

	// -- F6: product search by name -----------------------------------------------
	// A keyboard-navigable result list beneath the search box, reached only
	// when NOTHING exact-matches (see the 'input' listener in render()) — an
	// exact barcode/SKU hit, or a weight-shaped code, still adds instantly
	// and synchronously, never touching any of this. Debounced ~120ms,
	// against the in-memory INDEX only — never a REST call on the hot path
	// of typing (P1.13).

	const SEARCH_DEBOUNCE_MS = 120;
	let searchDebounceTimer = null;
	let searchResultsState = null; // { results, selectedIdx, query }

	function clearSearchResults() {
		searchResultsState = null;
		if (searchDebounceTimer) {
			clearTimeout(searchDebounceTimer);
			searchDebounceTimer = null;
		}
		renderSearchResults();
	}

	function scheduleTextSearch(q) {
		if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
		searchDebounceTimer = setTimeout(() => {
			searchDebounceTimer = null;
			searchResultsState = { results: searchByText(q), selectedIdx: 0, query: q };
			renderSearchResults();
		}, SEARCH_DEBOUNCE_MS);
	}

	/** ↑/↓ over the result list — clamps at the ends, never wraps, same shape as moveSelection() for cart lines. */
	function moveResultSelection(delta) {
		if (!searchResultsState || !searchResultsState.results.length) return;
		const next = Math.max(0, Math.min(searchResultsState.results.length - 1, searchResultsState.selectedIdx + delta));
		searchResultsState.selectedIdx = next;
		renderSearchResults();
	}

	function addHighlightedResult() {
		if (!searchResultsState || !searchResultsState.results.length) return;
		const product = searchResultsState.results[searchResultsState.selectedIdx];
		if (!product) return;
		const search = document.getElementById('cntr-search');
		if (search) search.value = '';
		clearSearchResults();
		addToCart(product, 1);
	}

	/** Targeted re-render of ONLY #cntr-search-results — never the whole render(), which would steal the search box's own focus/caret mid-type (F1's own reasoning for prevValue/prevCaret). */
	function renderSearchResults() {
		const root = document.getElementById('cntr-search-results');
		if (!root) return;
		if (!searchResultsState) {
			root.innerHTML = '';
			root.hidden = true;
			return;
		}
		const { results, selectedIdx, query } = searchResultsState;
		if (!results.length) {
			root.innerHTML = `<li class="cntr-search-empty">${escapeHtml(STRINGS.searchNoMatches)} "${escapeHtml(query)}"</li>`;
			root.hidden = false;
			return;
		}
		root.innerHTML = results
			.map(
				(r, i) => `<li data-idx="${i}" class="cntr-search-result${i === selectedIdx ? ' cntr-search-result-selected' : ''}">
					<span class="cntr-search-result-name">${escapeHtml(r.name || '')}</span>
					<span class="cntr-search-result-sku">${escapeHtml(r.sku || '')}</span>
					<span class="cntr-search-result-price">${escapeHtml(formatMoney(r.price))}</span>
					<span class="cntr-search-result-stock">${escapeHtml(String(null != r.sellable_qty ? r.sellable_qty : ''))}</span>
				</li>`
			)
			.join('');
		root.hidden = false;
		root.querySelectorAll('.cntr-search-result').forEach((li) => {
			li.addEventListener('click', () => {
				searchResultsState.selectedIdx = parseInt(li.dataset.idx, 10);
				addHighlightedResult();
			});
		});
	}

	function initScanner(onScan, onEmptyEnter) {
		addEventListener(
			'keydown',
			(e) => {
				const now = performance.now();
				const gap = now - last;
				last = now;
				if (gap > 120) {
					buf = '';
					fastRun = true;
				} else if (gap > 35) {
					fastRun = false;
				}
				if (e.key === 'Enter') {
					if (suppressNextEnter) {
						// The search box's own 'input' listener already
						// matched (or rejected) this exact keystroke
						// sequence and cleared the field itself — this
						// Enter is just its trailing keystroke, not a new
						// signal. See the suppressNextEnter declaration.
						suppressNextEnter = false;
						buf = '';
						fastRun = true;
						return;
					}
					// F6 — a visible result list owns Enter while it's showing
					// (matches or not): adds the highlighted result, or does
					// nothing for an empty state, but either way this Enter
					// is spoken for and must never fall through to the
					// scan/exact-cash-checkout logic below it.
					if (searchResultsState) {
						e.preventDefault();
						if (searchResultsState.results.length) addHighlightedResult();
						buf = '';
						fastRun = true;
						return;
					}
					const wasScan = buf.length >= 6 && fastRun;
					if (wasScan) {
						e.preventDefault();
						onScan(buf);
					} else {
						// Enter pressed while the search box is empty and this
						// wasn't a scan burst — the "take exact cash, print,
						// clear" shortcut. This listener is document-wide
						// (every keydown, not just the search box's own), so
						// it must also confirm the search box is what's
						// actually FOCUSED, not merely that it happens to be
						// empty — found live while building F5's own qty
						// modal: typing an amount into the tender modal (or
						// any other modal's input) and pressing Enter to
						// confirm it, with the search box sitting empty
						// behind it, silently rang up the WHOLE cart as an
						// exact-cash sale instead of doing what the modal
						// itself was asking for. See decisions.md.
						const search = document.getElementById('cntr-search');
						if (search && document.activeElement === search && '' === search.value && onEmptyEnter) onEmptyEnter();
					}
					buf = '';
					fastRun = true;
					return;
				}
				if (e.key.length === 1) buf += e.key;
			},
			true
		);
	}

	// -- Cart ---------------------------------------------------------------------

	/**
	 * F3 — a fresh object each call (never a shared literal two resets could
	 * both end up referencing). customer_id 0 means walk-in/no customer
	 * attached; every other field mirrors GET /customers/{id}/profile's own
	 * response shape exactly, so attachCustomer() can hold it verbatim.
	 */
	function emptyCustomer() {
		return {
			customer_id: 0,
			display_name: '',
			phone: '',
			balance: null,
			credit_limit: null,
			available: null,
			oldest_due_days: 0,
			can_credit: false,
			usual_items: [],
			price_group_id: 0, // F4 §2 — 0 means no group of their own; see attachCustomer()
		};
	}

	/**
	 * F4 (COUNTERFRONTEND.md) §2 — "the customer's own price group overrides
	 * the register's when attached." Product IDs come and go from the local
	 * catalogue index each sync; this map is rebuilt fresh every attach
	 * rather than cached across sales, so it can never go stale mid-shift.
	 * Keyed the same way lookupProduct()'s barcode/SKU rows carry a
	 * variation_id: `${product_id}:${variation_id}`.
	 */
	let customerPriceMap = new Map();

	function overrideKey(productId, variationId) {
		return `${productId}:${variationId || 0}`;
	}

	/** The register price for this row — undoes a customer-group override, never a discount or manual price. */
	function registerPriceFor(line) {
		return null != line.basePrice ? line.basePrice : line.unitPrice;
	}

	/**
	 * Applies the currently-attached customer's own price-group override,
	 * if any, else the product's default unit's own resolved price (B3 —
	 * Units::resolve_price() already falls back to base_price × 1 for a
	 * multiplier-1 default unit, so this equals product.price in the
	 * ordinary case), else the bare register price for a product with no
	 * configured multi-unit setup at all.
	 */
	function priceForProduct(product) {
		const key = overrideKey(product.id, product.variation_id || 0);
		const override = customerPriceMap.get(key);
		if (undefined !== override) return override;
		const defaultUnit = product.units && product.units[0];
		return defaultUnit ? defaultUnit.price : String(product.price || '0');
	}

	const cart = {
		lines: [], // { product, qty, unitPrice, discount, note, basePrice, priceOverridden, outOfStock? }
		customer: emptyCustomer(),
		heldSales: [],
		selectedIdx: null, // F1 — the line ↑/↓, per-row qty and Esc act on; defaults to the last line added
		priceGroupId: 0, // B2 — 0 means the register's own price; set by attach (F4 §2) or the manual picker
		orderDiscount: '0', // B4 — a flat amount, same shape as a line's own l.discount
		orderTax: '0',
		shipping: '0',
	};

	/** B4 — a fresh sale starts with none of these; called everywhere the cart itself already resets (sale completed, held, or voided). */
	function resetOrderAdjustments() {
		cart.orderDiscount = '0';
		cart.orderTax = '0';
		cart.shipping = '0';
	}

	/**
	 * B4 — the totals footer's own arithmetic, and the one place "what is
	 * actually due" is computed once everything above the footer (lines,
	 * price group, per-line discounts) is settled. Round off is a PREVIEW
	 * of Orders\Money::apply_rounding()'s own server-side step/round math —
	 * display-only, per the task's own Frontend line; the server is still
	 * what actually applies it (as a real fee line), this only shows the
	 * cashier what to expect before they submit.
	 */
	function footerTotals() {
		const items = cart.lines.reduce((sum, l) => sum + lineTotal(l), 0);
		const discount = parseFloat(cart.orderDiscount) || 0;
		const tax = parseFloat(cart.orderTax) || 0;
		const shipping = parseFloat(cart.shipping) || 0;
		const beforeRound = items - discount + tax + shipping;
		const roundOff = roundToStep(beforeRound) - beforeRound;
		return { items, discount, tax, shipping, roundOff, total: beforeRound + roundOff };
	}

	/** Marks when the CURRENT sale began — its first line on an empty cart —
	 * so submitSale() can record total scan-to-receipt for the whole sale,
	 * not just the final line. */
	let saleStart = null;

	/** P7.6 — a periodically-refreshed cache purely for the header badge;
	 * submitSale() itself never trusts this, it always re-checks live. */
	let outboxLockedState = false;

	function findLineIndex(product) {
		return cart.lines.findIndex(
			(l) => l.product.id === product.id && (l.product.variation_id || 0) === (product.variation_id || 0)
		);
	}

	/** Scanning the same item repeatedly increments one line, not five lines. */
	function addToCart(product, qty = 1) {
		const t0 = performance.now();
		if (cart.lines.length === 0 && saleStart === null) saleStart = t0;
		const idx = findLineIndex(product);
		if (idx >= 0) {
			cart.lines[idx].qty = String((parseFloat(cart.lines[idx].qty) || 0) + qty);
			cart.selectedIdx = idx;
		} else {
			cart.lines.push({
				product,
				qty: String(qty),
				// F4 §2 — basePrice is always the REGISTER's own price (never
				// an override), so clearCustomer() can restore it exactly;
				// unitPrice starts at the attached customer's own price when
				// one applies, same as a scan added mid-attachment would get.
				basePrice: String(product.price || '0'),
				unitPrice: priceForProduct(product),
				discount: '0',
				note: '',
				// F5 — true once a supervisor deliberately sets this line's
				// OWN price via lineDiscountPrompt()'s price-override section;
				// applyCustomerPriceGroup()/clearCustomer() must never clobber
				// a deliberate override back to a group/register price — see
				// their own guards below and docs/decisions.md.
				priceOverridden: false,
				// B3 — the product's own default unit (Stock\Units::for_product()
				// already orders is_default DESC, so [0] is it); null for a
				// product with no configured multi-unit setup at all, same as
				// units: [] itself already means "just the one, unlabelled".
				unit: product.units && product.units.length ? product.units[0] : null,
			});
			cart.selectedIdx = cart.lines.length - 1; // F1 — selection defaults to the last line added
		}
		render();
		perfRecord('add_line', performance.now() - t0);
	}

	/**
	 * U1 — the "usual items" strip, F2's own usual_items list tapped straight
	 * into the cart. Built by hand, same as quick-add's own immediate-add
	 * product object (F7): the real catalogue row for it may not be in
	 * INDEX at all (a product this customer bought before this register's
	 * own catalogue synced), and going through lookupProduct() would only
	 * add uncertainty a direct product_id already resolves.
	 */
	function addUsualItem(idx) {
		const item = (cart.customer.usual_items || [])[idx];
		if (!item) return;
		const product = {
			id: item.product_id,
			variation_id: item.variation_id || 0,
			parent_id: item.variation_id ? item.product_id : 0,
			name: item.name,
			sku: item.sku,
			barcode: '',
			price: item.price,
		};
		addToCart(product, 1);
	}

	/** F1 — unit price × qty − discount, the one place both a single row and the cart total compute it. */
	function lineTotal(line) {
		return parseFloat(line.unitPrice) * parseFloat(line.qty) - parseFloat(line.discount || '0');
	}

	/** B4 — the amount actually due: every line, plus the order-level footer (discount/tax/shipping/round-off preview). */
	function cartTotal() {
		return footerTotals().total;
	}

	/**
	 * F1 — removes cart.lines[idx] and keeps cart.selectedIdx pointing at
	 * the same LOGICAL line (or clears it), rather than left dangling at a
	 * stale array position after the splice shifts everything after idx.
	 */
	function spliceLine(idx) {
		cart.lines.splice(idx, 1);
		if (null === cart.selectedIdx) return;
		if (cart.selectedIdx === idx) {
			cart.selectedIdx = null;
		} else if (cart.selectedIdx > idx) {
			cart.selectedIdx -= 1;
		}
	}

	// -- Receipt number allocation -----------------------------------------------
	// {register_prefix}-{shift_id}-{seq_within_shift}. Terminal-allocated in
	// both online and offline mode — there is exactly one allocation path.

	function nextReceiptNo() {
		const shiftId = CFG.shiftId || 0;
		const key = `cntr_seq_shift_${shiftId}`;
		const seq = (parseInt(localStorage.getItem(key) || '0', 10) || 0) + 1;
		localStorage.setItem(key, String(seq));
		const shiftPadded = String(shiftId).padStart(6, '0');
		const seqPadded = String(seq).padStart(4, '0');
		return `${CFG.registerPrefix || 'R0'}-${shiftPadded}-${seqPadded}`;
	}

	function uuid4() {
		if (crypto.randomUUID) return crypto.randomUUID();
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
			const r = (Math.random() * 16) | 0;
			const v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	// -- Sale submission ----------------------------------------------------------

	async function submitSale(tenders) {
		// P7.6 — checked live, never against a cached flag: the outbox may
		// have drained (a real sync) since the last time anything looked.
		// Refuses outright, before the network is even tried and before
		// anything new is queued — a register this far behind must not grow
		// its own backlog further, online attempt or not.
		const lockDb = await openDb();
		const outboxNow = await idbOutboxGetAll(lockDb);
		if (isOutboxLocked(outboxNow, Date.now())) {
			return { locked: true, message: 'This register has been offline too long. Reconnect and let it sync before ringing another sale.' };
		}

		const uuid = uuid4();
		const receiptNo = nextReceiptNo();
		const body = {
			uuid,
			register_id: CFG.registerId,
			shift_id: CFG.shiftId,
			receipt_no: receiptNo,
			rung_at: new Date().toISOString(),
			customer: cart.customer,
			lines: cart.lines.map((l) => ({
				product_id: l.product.variation_id ? l.product.parent_id : l.product.id,
				variation_id: l.product.variation_id || 0,
				qty: l.qty,
				unit_price: l.unitPrice,
				discount: l.discount,
				note: l.note,
			})),
			tenders,
			// B4 — the footer's own order-level figures; Rest\Sale::process()
			// forwards these into Orders\Builder as a real WooCommerce
			// discount/fee, not baked into any one line.
			cart_discount: cart.orderDiscount || '0.0000',
			order_tax: cart.orderTax || '0.0000',
			shipping: cart.shipping || '0.0000',
			offline: false,
			price_group_id: cart.priceGroupId || 0,
		};

		const postStart = performance.now();
		let res = null;
		let networkFailed = false;
		try {
			res = await fetch(`${CFG.restUrl}/sale`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CFG.nonce,
					'X-CNTR-Register': String(CFG.registerId || ''),
				},
				credentials: 'same-origin',
				body: JSON.stringify(body),
			});
		} catch (e) {
			// The request never reached a server at all (offline, DNS, a
			// dropped connection mid-flight) — genuinely different from a
			// server that responded with a real rejection, which must
			// never be queued for a retry that can only ever fail the
			// same way again.
			networkFailed = true;
		}
		perfRecord('post', performance.now() - postStart);

		if (networkFailed) {
			body.offline = true;
			await queueSale(body);
			printReceipt(buildOfflineReceiptHtml(body));
			if (saleStart !== null) {
				perfRecord('total', performance.now() - saleStart);
				saleStart = null;
			}
			cart.lines = [];
			cart.customer = emptyCustomer();
			applyPriceOverrides([], 0); // B2 — the next sale starts at the register's own price
			resetOrderAdjustments(); // B4 — order-level adjustments reset with the cart
			render();
			return { queued: true, uuid, receipt_no: receiptNo };
		}

		const receipt = await res.json();
		if (res.ok) {
			if (receipt.receipt_html) printReceipt(receipt.receipt_html);
			if (saleStart !== null) {
				perfRecord('total', performance.now() - saleStart);
				saleStart = null;
			}
			cart.lines = [];
			cart.customer = emptyCustomer();
			applyPriceOverrides([], 0); // B2 — the next sale starts at the register's own price
			resetOrderAdjustments(); // B4 — order-level adjustments reset with the cart
			render();
		}
		return receipt;
	}

	/** "Enter" on an empty search box: take exact cash, print, clear. */
	async function checkoutExactCash() {
		if (!cart.lines.length) return;
		const total = cartTotal().toFixed(4);
		await submitSale([{ method: 'cash', amount: total }]);
	}

	// -- Returns (F9) ---------------------------------------------------------------
	// P8.5 — the terminal's own trigger for the return flow: the server side
	// (Rest\Returns -> Orders\Refunds::process()) already existed and worked,
	// this is what was actually missing. Deliberately online-only — unlike a
	// sale, a return that cannot reach the server is not something to queue
	// and silently replay later; the cashier gets an immediate, visible
	// failure instead, the same way a genuinely offline register already
	// blocks a sale once P7.6's own age lock trips.

	let returnState = null; // { orderId, receiptNo, lines, qtyByItemId }

	async function fetchOrderLookup(receiptNo) {
		const res = await fetch(`${CFG.restUrl}/order-lookup?receipt_no=${encodeURIComponent(receiptNo)}`, {
			headers: {
				'X-WP-Nonce': CFG.nonce,
				'X-CNTR-Register': String(CFG.registerId || ''),
			},
			credentials: 'same-origin',
		});
		const data = await res.json();
		if (!res.ok) throw new Error(data.message || `Lookup failed with status ${res.status}`);
		return data;
	}

	/** F9 — was window.prompt('Original receipt number:'); a dedicated modal step now, same as every other key's own surface. */
	function openReturnFlow() {
		returnState = { step: 'receipt' };
		renderReturnFlow();
	}

	async function submitReturnReceiptLookup() {
		if (!returnState || 'receipt' !== returnState.step) return;
		const input = document.getElementById('cntr-return-receipt-no');
		const receiptNo = input ? input.value.trim() : '';
		const warn = document.getElementById('cntr-return-receipt-warning');
		if (!receiptNo) return;
		let lookup;
		try {
			lookup = await fetchOrderLookup(receiptNo);
		} catch (e) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = e.message || STRINGS.couldNotFindReceipt;
			}
			return;
		}
		returnState = { step: 'picker', orderId: lookup.order_id, receiptNo: lookup.receipt_no, lines: lookup.lines, qtyByItemId: {} };
		renderReturnFlow();
	}

	function returnRefundTotal() {
		if (!returnState) return 0;
		return returnState.lines.reduce((sum, l) => {
			const qty = returnState.qtyByItemId[l.item_id] || 0;
			return sum + qty * parseFloat(l.unit_price);
		}, 0);
	}

	function renderReturnFlow() {
		const root = document.getElementById('cntr-return');
		if (!root || !returnState) return;

		if ('receipt' === returnState.step) {
			root.innerHTML = `
				<div class="cntr-modal-box">
					<h2>${STRINGS.returnExchangeTitle}</h2>
					<label>${STRINGS.receiptNumberLabel}
						<input id="cntr-return-receipt-no" type="text">
					</label>
					<span id="cntr-return-receipt-warning" class="cntr-inline-warning" hidden></span>
					<div class="cntr-modal-actions">
						<button type="button" id="cntr-return-receipt-find">${STRINGS.findBtn}</button>
						<button type="button" id="cntr-return-receipt-cancel">${STRINGS.cancelBtn}</button>
					</div>
				</div>
			`;
			root.hidden = false;
			const input = document.getElementById('cntr-return-receipt-no');
			if (input) input.focus();
			const findBtn = document.getElementById('cntr-return-receipt-find');
			if (findBtn) findBtn.addEventListener('click', submitReturnReceiptLookup);
			const cancelBtn = document.getElementById('cntr-return-receipt-cancel');
			if (cancelBtn) cancelBtn.addEventListener('click', closeReturnFlow);
			return;
		}

		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.returnTitlePrefix} ${escapeHtml(returnState.receiptNo)}</h2>
				<ul class="cntr-return-lines">
					${returnState.lines
						.map(
							(l) => `<li data-item-id="${l.item_id}">
								<span class="cntr-return-name">${escapeHtml(l.name)}</span>
								<span class="cntr-return-remaining">${STRINGS.ofPrefix} ${l.remaining_qty} ${STRINGS.remainingSuffix}</span>
								<input class="cntr-return-qty" type="number" min="0" max="${l.remaining_qty}" step="any" value="0" data-item-id="${l.item_id}">
							</li>`
						)
						.join('')}
				</ul>
				<label>${STRINGS.reasonLabel}
					<input id="cntr-return-reason" type="text">
				</label>
				<div class="cntr-return-total">${STRINGS.refundTotalLabel} ${formatMoney(returnRefundTotal())}</div>
				<label>${STRINGS.methodLabel}
					<select id="cntr-return-method">
						${TENDER_METHODS.map((m) => `<option value="${m}">${escapeHtml(methodLabel(m))}</option>`).join('')}
					</select>
				</label>
				<div class="cntr-modal-actions">
					<button id="cntr-return-submit">${STRINGS.refundPrintBtn}</button>
					<button id="cntr-return-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;

		root.querySelectorAll('.cntr-return-qty').forEach((input) => {
			input.addEventListener('input', () => {
				const itemId = input.dataset.itemId;
				const max = parseFloat(input.max) || 0;
				let qty = parseFloat(input.value) || 0;
				if (qty > max) qty = max;
				if (qty < 0) qty = 0;
				returnState.qtyByItemId[itemId] = qty;
				const totalEl = root.querySelector('.cntr-return-total');
				if (totalEl) totalEl.textContent = `${STRINGS.refundTotalLabel} ${formatMoney(returnRefundTotal())}`;
			});
		});
		const cancelBtn = document.getElementById('cntr-return-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeReturnFlow);
		const submitBtn = document.getElementById('cntr-return-submit');
		if (submitBtn) submitBtn.addEventListener('click', submitReturn);
	}

	function closeReturnFlow() {
		const root = document.getElementById('cntr-return');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		returnState = null;
		restoreFocus(); // F1 — hiding the modal doesn't itself call render()
	}

	async function submitReturn() {
		if (!returnState) return;
		const lineItems = {};
		let anySelected = false;
		for (const l of returnState.lines) {
			const qty = returnState.qtyByItemId[l.item_id] || 0;
			if (qty > 0) {
				anySelected = true;
				lineItems[l.item_id] = {
					qty,
					refund_total: (qty * parseFloat(l.unit_price)).toFixed(4),
					refund_tax: [],
				};
			}
		}
		if (!anySelected) {
			alert(STRINGS.selectAtLeastOneItem);
			return;
		}

		const method = document.getElementById('cntr-return-method').value;
		const reason = document.getElementById('cntr-return-reason').value || '';
		const amount = returnRefundTotal().toFixed(4);

		let res;
		try {
			res = await fetch(`${CFG.restUrl}/returns`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CFG.nonce,
					'X-CNTR-Register': String(CFG.registerId || ''),
				},
				credentials: 'same-origin',
				body: JSON.stringify({
					order_id: returnState.orderId,
					shift_id: CFG.shiftId,
					amount,
					line_items: lineItems,
					reason,
					tenders: [{ method, amount }],
				}),
			});
		} catch (e) {
			alert(STRINGS.registerOfflineReturn);
			return;
		}

		const data = await res.json();
		if (!res.ok) {
			alert(data.message || STRINGS.returnRefused);
			return;
		}

		printReceipt(buildReturnReceiptHtml(returnState, amount, method, reason));
		closeReturnFlow();
	}

	function buildReturnReceiptHtml(state, amount, method, reason) {
		const rows = state.lines
			.filter((l) => (state.qtyByItemId[l.item_id] || 0) > 0)
			.map((l) => `<tr><td>${escapeHtml(l.name)}</td><td>${state.qtyByItemId[l.item_id]}</td></tr>`)
			.join('');
		return `<html><body style="${RECEIPT_FONT_STYLE}">
			<p style="font-weight:bold;">${STRINGS.returnReceiptTitle} ${escapeHtml(state.receiptNo)}</p>
			<table>${rows}</table>
			<p>${STRINGS.refundedLabel} ${formatMoney(amount)} (${escapeHtml(methodLabel(method))})</p>
			${reason ? `<p>${STRINGS.reasonPrefix} ${escapeHtml(reason)}</p>` : ''}
		</body></html>`;
	}

	// -- Printing -----------------------------------------------------------------
	// A hidden iframe + print() — the browser rasterises the HTML and hands
	// pixels to the Windows driver, which is why ৳ and Bengali render
	// correctly. The print DIALOG popping on every job is a machine-setup
	// problem (Chrome --kiosk-printing), not something this code can fix.

	function printReceipt(html) {
		let frame = document.getElementById('cntr-print-frame');
		if (!frame) {
			frame = document.createElement('iframe');
			frame.id = 'cntr-print-frame';
			frame.style.position = 'fixed';
			frame.style.right = '0';
			frame.style.bottom = '0';
			frame.style.width = '0';
			frame.style.height = '0';
			frame.style.border = '0';
			document.body.appendChild(frame);
		}
		const doc = frame.contentWindow.document;
		doc.open();
		doc.write(html);
		doc.close();
		frame.contentWindow.focus();
		frame.contentWindow.print();
	}

	// -- Keyboard map ---------------------------------------------------------------
	// scan/type+Enter: add item (default, no confirmation)
	// Enter (empty search): take exact cash, print, clear
	// F2: tender screen  F3: change qty  F4: line discount (capability-gated)
	// F6: attach customer  F7/F8: hold/resume  F9: return/exchange
	// F10: no-sale (capability-gated)  Esc: void line, then cart

	function initKeyboardMap() {
		addEventListener('keydown', (e) => {
			switch (e.key) {
				case 'ArrowUp':
					e.preventDefault();
					if (searchResultsState) moveResultSelection(-1);
					else moveSelection(-1);
					break;
				case 'ArrowDown':
					e.preventDefault();
					if (searchResultsState) moveResultSelection(1);
					else moveSelection(1);
					break;
				case 'F2':
					e.preventDefault();
					openTenderScreen();
					break;
				case 'F3':
					e.preventDefault();
					changeSelectedQty();
					break;
				case 'F4':
					e.preventDefault();
					lineDiscountPrompt();
					break;
				case 'F5':
					e.preventDefault();
					openQuickAdd();
					break;
				case 'F6':
					e.preventDefault();
					attachCustomerPrompt();
					break;
				case 'F7':
					e.preventDefault();
					holdSale();
					break;
				case 'F8':
					e.preventDefault();
					resumeSale();
					break;
				case 'F9':
					e.preventDefault();
					openReturnFlow();
					break;
				case 'F10':
					e.preventDefault();
					noSale();
					break;
				case 'Escape':
					e.preventDefault();
					if (searchResultsState) clearSearchResults();
					else voidLineThenCart();
					break;
				default:
					break;
			}
		});
	}

	// -- Held sales (F8) -----------------------------------------------------------
	// A parked ticket is not a commitment — held sales never reserve stock.
	// Persisted to IndexedDB (HELD_STORE) alongside the catalogue and outbox
	// stores it sits next to, so a refresh or a crash never loses one;
	// cart.heldSales is loaded from there once at boot() and kept in sync
	// with every hold/resume from then on.

	async function holdSale() {
		if (!cart.lines.length) return;
		// B2 — carried along so a resume can restore the picker's own
		// selection; held lines already carry their own frozen unitPrice
		// (same as the customer's own group always has), so nothing here
		// re-prices on resume — matching that existing, unchanged behaviour.
		const record = {
			id: uuid4(),
			lines: cart.lines,
			customer: cart.customer,
			priceGroupId: cart.priceGroupId,
			// B4 — a discount/tax/shipping already set on this sale is part
			// of what "resume" should bring back, same reasoning as the
			// price group above.
			orderDiscount: cart.orderDiscount,
			orderTax: cart.orderTax,
			shipping: cart.shipping,
			heldAt: Date.now(),
		};
		cart.heldSales.push(record);
		const db = await openDb();
		await idbHeldPut(db, record);
		cart.lines = [];
		cart.customer = emptyCustomer();
		cart.selectedIdx = null;
		applyPriceOverrides([], 0); // the next walk-up customer starts at the register's own price
		resetOrderAdjustments(); // B4 — order-level adjustments reset with the cart
		render();
	}

	let heldListState = null; // {}

	/** F8 now opens a LIST — a LIFO pop() only ever reached the most recently parked ticket. */
	function resumeSale() {
		if (!cart.heldSales.length) return;
		heldListState = {};
		renderHeldList();
	}

	function closeHeldList() {
		heldListState = null;
		const root = document.getElementById('cntr-held');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	function formatHeldAge(ms) {
		const mins = Math.max(0, Math.floor(ms / 60000));
		if (mins < 1) return STRINGS.justNow;
		if (mins < 60) return fmt(STRINGS.minsAgo, { n: mins });
		return fmt(STRINGS.hoursMinsAgo, { h: Math.floor(mins / 60), n: mins % 60 });
	}

	/**
	 * P1.13's own unmet line: "Say so in the UI if a held item later goes out
	 * of stock." Checked against the CURRENT in-memory INDEX, never blocking
	 * the resume — flagged (line.outOfStock), same as every other capability
	 * gate in this file surfaces why rather than silently refusing.
	 */
	async function resumeHeldSale(id) {
		const idx = cart.heldSales.findIndex((h) => h.id === id);
		if (idx < 0) return;
		if (cart.lines.length && !confirm(STRINGS.replaceCartConfirm)) {
			return;
		}
		const held = cart.heldSales[idx];
		cart.heldSales.splice(idx, 1);
		const db = await openDb();
		await idbHeldDelete(db, id);

		cart.lines = held.lines.map((l) => {
			const fresh = INDEX.byBarcode.get(l.product.barcode) || INDEX.bySku.get(l.product.sku) || null;
			const sellable = fresh ? parseFloat(fresh.sellable_qty) : null;
			return { ...l, outOfStock: null !== sellable && sellable < parseFloat(l.qty) };
		});
		cart.customer = held.customer;
		cart.priceGroupId = held.priceGroupId || 0; // display only — held lines keep their own frozen prices, same as customer groups already do on resume
		cart.orderDiscount = held.orderDiscount || '0';
		cart.orderTax = held.orderTax || '0';
		cart.shipping = held.shipping || '0';
		cart.selectedIdx = null;
		closeHeldList();
		render();
	}

	function renderHeldList() {
		const root = document.getElementById('cntr-held');
		if (!root || !heldListState) return;
		if (!cart.heldSales.length) {
			closeHeldList();
			return;
		}
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.heldSalesTitle}</h2>
				<ul class="cntr-held-list">
					${cart.heldSales
						.map((h) => {
							const total = h.lines.reduce((s, l) => s + lineTotal(l), 0);
							return `<li data-id="${h.id}" class="cntr-held-row">
								<span class="cntr-held-customer">${escapeHtml(h.customer.display_name || STRINGS.walkIn)}</span>
								<span class="cntr-held-count">${h.lines.length} ${escapeHtml(1 === h.lines.length ? STRINGS.itemSingular : STRINGS.itemPlural)}</span>
								<span class="cntr-held-total">${formatMoney(total)}</span>
								<span class="cntr-held-age">${escapeHtml(formatHeldAge(Date.now() - h.heldAt))}</span>
							</li>`;
						})
						.join('')}
				</ul>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-held-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		root.querySelectorAll('.cntr-held-row').forEach((li) => {
			li.addEventListener('click', () => resumeHeldSale(li.dataset.id));
		});
		const cancelBtn = document.getElementById('cntr-held-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeHeldList);
	}

	// -- Quick-add (F5) ---------------------------------------------------------------

	async function submitQuickAdd(data) {
		const res = await fetch(`${CFG.restUrl}/quick-add`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify({ ...data, location_id: CFG.defaultLocationId }),
		});
		return res.json();
	}

	// -- Minimal UI (no framework) ------------------------------------------------------

	// -- F4: split tenders and the credit row -------------------------------------
	//
	// P1.14's own words: "WooCommerce records one payment method per order; a
	// Bangladeshi counter routinely takes half cash and half bKash." Rebuilds
	// the single cash-only modal into rows of method+amount, live Due/Taken/
	// Remaining/Change, and a credit row that only ever appears when it could
	// actually be used. Mirrors renderCustomerFlow()'s own pattern: a
	// dedicated state object, a full rebuild on structural change, and a
	// lightweight live-update path (updateTenderSummary()) for typing, so an
	// amount field never loses focus the way the main render() search box
	// used to before F1's fix.

	const TENDER_METHODS = ['cash', 'card', 'bkash', 'nagad', 'rocket', 'bank'];

	/** U2 — the same method vocabulary appears in the tender modal and the return flow's own method select; one translated label source for both. */
	function methodLabel(code) {
		const map = {
			cash: STRINGS.methodCash,
			card: STRINGS.methodCard,
			bkash: STRINGS.methodBkash,
			nagad: STRINGS.methodNagad,
			rocket: STRINGS.methodRocket,
			bank: STRINGS.methodBank,
			change: STRINGS.methodChange,
		};
		return map[code] || code;
	}

	let tenderState = null; // { rows: [{ id, method, amount }] }
	let tenderRowSeq = 0;

	/** cntr_credit_sale is a capability of the CASHIER, not a property of the customer — Rest\Customer::profile()'s own can_credit field already folds that in. */
	function creditEligible() {
		return cart.customer.customer_id > 0 && true === cart.customer.can_credit;
	}

	/** Coin rounding — a shop with no small coins rounds cash to its nearest configured step; every other method is exact. */
	function roundToStep(amount) {
		const step = parseFloat(CFG.roundingStep) || 0;
		if (!step) return amount;
		return Math.round(amount / step) * step;
	}

	/** What a freshly appended row should default to: whatever's not yet assigned, rounded for cash's sake. */
	function tenderOutstanding() {
		const due = cartTotal();
		const assigned = tenderState.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
		return Math.max(0, due - assigned);
	}

	/**
	 * Credit is money that never arrives today — it must never appear in the
	 * Due/Taken/Remaining/Change arithmetic the same way a real tender does.
	 * neededFromReal is "Due, minus whatever's going on this customer's
	 * account instead" — realTotal must cover exactly that; short of it is
	 * Remaining, past it is Change.
	 */
	function tenderTotals() {
		const due = cartTotal();
		const creditTotal = tenderState.rows.filter((r) => 'credit' === r.method).reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
		const realTotal = tenderState.rows.filter((r) => 'credit' !== r.method).reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
		const neededFromReal = due - creditTotal;
		const remaining = Math.max(0, +(neededFromReal - realTotal).toFixed(4));
		const change = Math.max(0, +(realTotal - neededFromReal).toFixed(4));
		const available = null === cart.customer.available ? null : parseFloat(cart.customer.available);
		const overLimit = null !== available && creditTotal > available + 0.0001;
		const overCredited = creditTotal > due + 0.0001; // credit alone can never exceed the whole sale
		const ok = due > 0 && remaining < 0.005 && !overLimit && !overCredited;
		return { due, creditTotal, realTotal, remaining, change, available, overLimit, overCredited, ok };
	}

	function openTenderScreen() {
		if (!cart.lines.length) return;
		tenderState = { rows: [{ id: ++tenderRowSeq, method: 'cash', amount: roundToStep(cartTotal()).toFixed(4) }] };
		renderTenderModal();
	}

	function closeTenderModal() {
		tenderState = null;
		const root = document.getElementById('cntr-tender');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	function addTenderRow() {
		const outstanding = tenderOutstanding();
		tenderState.rows.push({ id: ++tenderRowSeq, method: 'cash', amount: outstanding > 0 ? roundToStep(outstanding).toFixed(4) : '0.0000' });
		renderTenderModal();
	}

	function removeTenderRow(id) {
		tenderState.rows = tenderState.rows.filter((r) => r.id !== id);
		renderTenderModal();
	}

	/** Re-reads every row's live amount from the DOM into tenderState, then updates only the summary text/warnings/submit-state — never rebuilds the rows, so a mid-keystroke amount field keeps its focus and caret. */
	function updateTenderSummary() {
		if (!tenderState) return;
		document.querySelectorAll('.cntr-tender-row-amount').forEach((input) => {
			const row = tenderState.rows.find((r) => r.id === parseInt(input.dataset.rowId, 10));
			if (row) row.amount = input.value;
		});

		const t = tenderTotals();
		const due = document.getElementById('cntr-tender-due');
		const taken = document.getElementById('cntr-tender-taken');
		const remaining = document.getElementById('cntr-tender-remaining');
		const change = document.getElementById('cntr-tender-change');
		if (due) due.textContent = formatMoney(t.due);
		if (taken) taken.textContent = formatMoney(t.realTotal + t.creditTotal);
		if (remaining) remaining.textContent = formatMoney(t.remaining);
		if (change) change.textContent = formatMoney(t.change);

		const consequence = document.getElementById('cntr-tender-account-consequence');
		if (consequence) {
			if (t.creditTotal > 0) {
				const after = (parseFloat(cart.customer.balance) || 0) + t.creditTotal;
				consequence.hidden = false;
				consequence.textContent =
					null === t.available
						? fmt(STRINGS.accountAfterSaleNoLimit, { after: formatMoney(after) })
						: fmt(STRINGS.accountAfterSaleWithLimit, { after: formatMoney(after), limit: formatMoney(cart.customer.credit_limit) });
			} else {
				consequence.hidden = true;
				consequence.textContent = '';
			}
		}

		document.querySelectorAll('.cntr-tender-credit-warning').forEach((warn) => {
			if (t.overLimit) {
				warn.hidden = false;
				warn.textContent = fmt(STRINGS.exceedsAvailableCredit, { available: formatMoney(t.available || 0) });
			} else if (t.overCredited) {
				warn.hidden = false;
				warn.textContent = STRINGS.creditExceedsDue;
			} else {
				warn.hidden = true;
				warn.textContent = '';
			}
		});

		const submitBtn = document.getElementById('cntr-tender-submit');
		if (submitBtn) submitBtn.disabled = !t.ok;
	}

	function renderTenderModal() {
		const root = document.getElementById('cntr-tender');
		if (!root || !tenderState) return;

		const eligible = creditEligible();
		const t = tenderTotals();

		root.innerHTML = `
			<div class="cntr-modal-box cntr-tender-box">
				<h2>${STRINGS.takePaymentTitle} ${formatMoney(t.due)}</h2>
				<div class="cntr-tender-rows">
					${tenderState.rows
						.map(
							(r) => `<div class="cntr-tender-row" data-row-id="${r.id}">
								<select class="cntr-tender-row-method" data-row-id="${r.id}">
									${TENDER_METHODS.map((m) => `<option value="${m}"${r.method === m ? ' selected' : ''}>${escapeHtml(methodLabel(m))}</option>`).join('')}
									${eligible ? `<option value="credit"${'credit' === r.method ? ' selected' : ''}>${STRINGS.creditOnAccount}</option>` : ''}
								</select>
								<input type="text" inputmode="decimal" class="cntr-tender-row-amount" data-row-id="${r.id}" value="${r.amount}">
								<button type="button" class="cntr-tender-row-remove" data-row-id="${r.id}" aria-label="${STRINGS.removeTenderRowAria}">&times;</button>
								${'credit' === r.method ? `<span class="cntr-tender-credit-warning" hidden></span>` : ''}
							</div>`
						)
						.join('')}
				</div>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-tender-add-row">${STRINGS.addAnotherMethod}</button>
				</div>
				<div class="cntr-tender-summary">
					<div>${STRINGS.tenderDue} <span id="cntr-tender-due">${formatMoney(t.due)}</span></div>
					<div>${STRINGS.tenderTaken} <span id="cntr-tender-taken">${formatMoney(t.realTotal + t.creditTotal)}</span></div>
					<div>${STRINGS.tenderRemaining} <span id="cntr-tender-remaining">${formatMoney(t.remaining)}</span></div>
					<div>${STRINGS.tenderChange} <span id="cntr-tender-change">${formatMoney(t.change)}</span></div>
					<div id="cntr-tender-account-consequence" class="cntr-tender-account-consequence"${t.creditTotal > 0 ? '' : ' hidden'}></div>
					${!navigator.onLine && eligible ? `<p class="cntr-tender-offline-note">${STRINGS.offlineCreditNote}</p>` : ''}
				</div>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-tender-submit"${t.ok ? '' : ' disabled'}>${STRINGS.takePaymentPrint}</button>
					<button type="button" id="cntr-tender-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;

		root.querySelectorAll('.cntr-tender-row-method').forEach((sel) => {
			sel.addEventListener('change', () => {
				const row = tenderState.rows.find((r) => r.id === parseInt(sel.dataset.rowId, 10));
				if (row) row.method = sel.value;
				renderTenderModal();
			});
		});
		root.querySelectorAll('.cntr-tender-row-amount').forEach((input) => {
			input.addEventListener('input', updateTenderSummary);
			input.addEventListener('blur', () => {
				const row = tenderState.rows.find((r) => r.id === parseInt(input.dataset.rowId, 10));
				if (row && 'cash' === row.method) {
					input.value = roundToStep(parseFloat(input.value) || 0).toFixed(4);
					row.amount = input.value;
					updateTenderSummary();
				}
			});
		});
		root.querySelectorAll('.cntr-tender-row-remove').forEach((btn) => {
			btn.addEventListener('click', () => removeTenderRow(parseInt(btn.dataset.rowId, 10)));
		});
		const addBtn = document.getElementById('cntr-tender-add-row');
		if (addBtn) addBtn.addEventListener('click', addTenderRow);
		const cancelBtn = document.getElementById('cntr-tender-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeTenderModal);
		const submitBtn = document.getElementById('cntr-tender-submit');
		if (submitBtn) submitBtn.addEventListener('click', submitTenderModal);

		// The static markup above always starts a credit row's warning
		// hidden and t.ok baked in at render time — this syncs both to the
		// CURRENT totals for every rebuild reason that isn't a fresh amount
		// keystroke (a method change, an added/removed row), not just the
		// initial open.
		updateTenderSummary();

		const firstAmount = root.querySelector('.cntr-tender-row-amount');
		if (firstAmount) {
			firstAmount.focus();
			firstAmount.select();
		}
	}

	/**
	 * Tenders::record() refuses an EMPTY tenders array outright ("At least
	 * one tender is required") even for a sale going entirely on account —
	 * the shortfall mechanism needs at least one row to compute a diff
	 * against. A wholly-credit sale keeps that contract by sending one
	 * explicit zero-amount cash row rather than nothing; it costs the
	 * shortfall math nothing (0 contributes nothing to $net) and asks
	 * nothing of the backend beyond what it already requires.
	 */
	async function submitTenderModal() {
		if (!tenderState) return;
		updateTenderSummary();
		const t = tenderTotals();
		if (!t.ok) return;

		const tenders = tenderState.rows
			.filter((r) => 'credit' !== r.method && (parseFloat(r.amount) || 0) > 0)
			.map((r) => ({ method: r.method, amount: parseFloat(r.amount).toFixed(4) }));
		if (t.change > 0.0001) {
			tenders.push({ method: 'change', amount: t.change.toFixed(4), is_change: true });
		}
		if (!tenders.length) {
			tenders.push({ method: 'cash', amount: '0.0000' });
		}

		closeTenderModal();
		await submitSale(tenders);
	}

	// -- F5: line quantity, capped line discount/override, audited no-sale -------
	//
	// All three are dedicated modals (never window.prompt()), same
	// targeted-rebuild-into-an-empty-#cntr-* container pattern the tender and
	// customer flows already established — see renderTenderModal()'s own
	// docblock for why.

	let qtyPromptState = null; // { idx }

	function changeSelectedQty() {
		if (null === cart.selectedIdx || !cart.lines[cart.selectedIdx]) return;
		qtyPromptState = { idx: cart.selectedIdx };
		renderQtyPrompt();
	}

	function closeQtyPrompt() {
		qtyPromptState = null;
		const root = document.getElementById('cntr-qty');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	/** Loose goods are weighed — parseFloat, not parseInt, is deliberate. Zero or less asks before removing, never removes silently. */
	function submitQtyPrompt() {
		if (!qtyPromptState) return;
		const line = cart.lines[qtyPromptState.idx];
		if (!line) {
			closeQtyPrompt();
			return;
		}
		const input = document.getElementById('cntr-qty-input');
		const next = input ? parseFloat(input.value) : NaN;
		if (!(next > 0)) {
			if (confirm(STRINGS.removeLineConfirm)) {
				spliceLine(qtyPromptState.idx);
				closeQtyPrompt();
				render();
			}
			return; // declined — leave the modal open, unchanged, for another try
		}
		line.qty = String(next);
		cart.selectedIdx = qtyPromptState.idx;
		closeQtyPrompt();
		render();
	}

	function renderQtyPrompt() {
		const root = document.getElementById('cntr-qty');
		if (!root || !qtyPromptState) return;
		const line = cart.lines[qtyPromptState.idx];
		if (!line) {
			closeQtyPrompt();
			return;
		}
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.changeQtyTitle} ${escapeHtml(line.product.name || '')}</h2>
				<label>${STRINGS.qtyLabel}
					<input id="cntr-qty-input" type="text" inputmode="decimal" value="${line.qty}">
				</label>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-qty-apply">${STRINGS.applyBtn}</button>
					<button type="button" id="cntr-qty-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const input = document.getElementById('cntr-qty-input');
		if (input) {
			input.focus();
			input.select();
		}
		submitOnEnter(input, submitQtyPrompt);
		const applyBtn = document.getElementById('cntr-qty-apply');
		if (applyBtn) applyBtn.addEventListener('click', submitQtyPrompt);
		const cancelBtn = document.getElementById('cntr-qty-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeQtyPrompt);
	}

	let discountPromptState = null; // { idx }

	/**
	 * cntr_discount_line gates the key itself — with neither capability the
	 * modal never opens, and requireCap() surfaces why. cntr_price_override
	 * is narrower and nested: it only decides whether the modal's OWN
	 * override section renders, never whether the key works at all.
	 */
	function lineDiscountPrompt() {
		if (null === cart.selectedIdx || !cart.lines[cart.selectedIdx]) return;
		if (!requireCap('cntr_discount_line', 'capDenyDiscountLine')) return;
		discountPromptState = { idx: cart.selectedIdx };
		renderDiscountPrompt();
	}

	function closeDiscountPrompt() {
		discountPromptState = null;
		const root = document.getElementById('cntr-discount');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	function lineDiscountBase(line) {
		return parseFloat(line.unitPrice) * parseFloat(line.qty);
	}

	/**
	 * Writes line.discount (the field has always existed in the cart model;
	 * nothing has ever set it until now) — never a second, client-side audit
	 * trail; the server already writes one on submission (P0.6). Refuses
	 * above discountCeilingPct rather than clamping to it — a cashier asking
	 * for 30% off a 10%-ceiling item needs a supervisor, not a silently
	 * smaller discount they never asked for.
	 */
	function submitLineDiscount() {
		if (!discountPromptState) return;
		const line = cart.lines[discountPromptState.idx];
		if (!line) {
			closeDiscountPrompt();
			return;
		}
		const amountInput = document.getElementById('cntr-discount-amount');
		const pctInput = document.getElementById('cntr-discount-pct');
		const amountVal = amountInput ? parseFloat(amountInput.value) : NaN;
		const pctVal = pctInput ? parseFloat(pctInput.value) : NaN;
		const base = lineDiscountBase(line);

		let amount;
		let pct;
		if (amountVal > 0) {
			amount = amountVal;
			pct = base > 0 ? (amount / base) * 100 : 0;
		} else if (pctVal > 0) {
			pct = pctVal;
			amount = (pct / 100) * base;
		} else {
			return; // nothing entered
		}

		const ceiling = parseFloat(CFG.discountCeilingPct) || 0;
		const warn = document.getElementById('cntr-discount-warning');
		if (pct > ceiling + 0.0001) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = fmt(STRINGS.discountAboveCeiling, { pct: pct.toFixed(1), ceiling: ceiling.toFixed(1) });
			}
			return;
		}

		line.discount = amount.toFixed(4);
		closeDiscountPrompt();
		render();
	}

	/** Independent of the discount above — sets the line's OWN price outright, never bounded by discountCeilingPct (that cap protects a cashier's discretion; this needs cntr_price_override precisely because it has none). */
	function submitPriceOverride() {
		if (!discountPromptState || !(CFG.caps && CFG.caps.cntr_price_override)) return;
		const line = cart.lines[discountPromptState.idx];
		if (!line) {
			closeDiscountPrompt();
			return;
		}
		const input = document.getElementById('cntr-override-price');
		const next = input ? parseFloat(input.value) : NaN;
		if (!(next >= 0)) return;
		line.unitPrice = next.toFixed(4);
		line.priceOverridden = true; // F4 §2 — a later customer attach/detach must never clobber this back
		closeDiscountPrompt();
		render();
	}

	function renderDiscountPrompt() {
		const root = document.getElementById('cntr-discount');
		if (!root || !discountPromptState) return;
		const line = cart.lines[discountPromptState.idx];
		if (!line) {
			closeDiscountPrompt();
			return;
		}
		const canOverride = !!(CFG.caps && CFG.caps.cntr_price_override);
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.discountTitle} ${escapeHtml(line.product.name || '')}</h2>
				<label>${STRINGS.amountOffLabel}
					<input id="cntr-discount-amount" type="text" inputmode="decimal" value="">
				</label>
				<label>${STRINGS.percentOffLabel}
					<input id="cntr-discount-pct" type="text" inputmode="decimal" value="">
				</label>
				<span id="cntr-discount-warning" class="cntr-inline-warning" hidden></span>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-discount-apply">${STRINGS.applyDiscountBtn}</button>
				</div>
				${
					canOverride
						? `<hr class="cntr-modal-divider">
					<label>${STRINGS.overridePriceLabel}
						<input id="cntr-override-price" type="text" inputmode="decimal" value="${line.unitPrice}">
					</label>
					<div class="cntr-modal-actions">
						<button type="button" id="cntr-override-apply">${STRINGS.applyOverrideBtn}</button>
					</div>`
						: ''
				}
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-discount-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const amountInput = document.getElementById('cntr-discount-amount');
		if (amountInput) amountInput.focus();
		submitOnEnter(amountInput, submitLineDiscount);
		submitOnEnter(document.getElementById('cntr-discount-pct'), submitLineDiscount);
		submitOnEnter(document.getElementById('cntr-override-price'), submitPriceOverride);
		const applyBtn = document.getElementById('cntr-discount-apply');
		if (applyBtn) applyBtn.addEventListener('click', submitLineDiscount);
		const overrideBtn = document.getElementById('cntr-override-apply');
		if (overrideBtn) overrideBtn.addEventListener('click', submitPriceOverride);
		const cancelBtn = document.getElementById('cntr-discount-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeDiscountPrompt);
	}

	// -- B4: order-level discount, tax and shipping — one shared pencil modal ----

	let orderAdjustState = null; // { field: 'discount' | 'tax' | 'shipping' }

	function openOrderAdjust(field) {
		if (!cart.lines.length) return;
		orderAdjustState = { field };
		renderOrderAdjust();
	}

	function closeOrderAdjust() {
		orderAdjustState = null;
		const root = document.getElementById('cntr-order-adjust');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	/**
	 * Discount only: amount or percent OF THE ITEMS SUBTOTAL (mirrors
	 * lineDiscountBase()'s own amount-or-percent shape), bounded by the
	 * same discountCeilingPct a line discount already enforces — an
	 * order-level discount is still a cashier's own discretion, not a
	 * supervisor override, so the same ceiling applies. Tax and shipping
	 * are plain amounts, no ceiling: they add to the total, they are never
	 * a discretion to bound.
	 */
	function submitOrderAdjust() {
		if (!orderAdjustState) return;
		const field = orderAdjustState.field;

		if ('discount' === field) {
			const amountInput = document.getElementById('cntr-order-adjust-amount');
			const pctInput = document.getElementById('cntr-order-adjust-pct');
			const amountVal = amountInput ? parseFloat(amountInput.value) : NaN;
			const pctVal = pctInput ? parseFloat(pctInput.value) : NaN;
			const base = footerTotals().items;

			let amount;
			let pct;
			if (amountVal >= 0 && !isNaN(amountVal)) {
				amount = amountVal;
				pct = base > 0 ? (amount / base) * 100 : 0;
			} else if (pctVal >= 0 && !isNaN(pctVal)) {
				pct = pctVal;
				amount = (pct / 100) * base;
			} else {
				return; // nothing entered
			}

			const ceiling = parseFloat(CFG.discountCeilingPct) || 0;
			const warn = document.getElementById('cntr-order-adjust-warning');
			if (pct > ceiling + 0.0001) {
				if (warn) {
					warn.hidden = false;
					warn.textContent = fmt(STRINGS.discountAboveCeiling, { pct: pct.toFixed(1), ceiling: ceiling.toFixed(1) });
				}
				return;
			}
			cart.orderDiscount = amount.toFixed(4);
		} else {
			const input = document.getElementById('cntr-order-adjust-amount');
			const val = input ? parseFloat(input.value) : NaN;
			if (isNaN(val) || val < 0) return;
			if ('tax' === field) cart.orderTax = val.toFixed(4);
			else if ('shipping' === field) cart.shipping = val.toFixed(4);
		}
		closeOrderAdjust();
		render();
	}

	function renderOrderAdjust() {
		const root = document.getElementById('cntr-order-adjust');
		if (!root || !orderAdjustState) return;
		const field = orderAdjustState.field;
		const titles = { discount: STRINGS.orderDiscountTitle, tax: STRINGS.orderTaxTitle, shipping: STRINGS.orderShippingTitle };
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${titles[field] || ''}</h2>
				${
					'discount' === field
						? `<label>${STRINGS.amountOffLabel}
							<input id="cntr-order-adjust-amount" type="text" inputmode="decimal" value="">
						</label>
						<label>${STRINGS.percentOffLabel}
							<input id="cntr-order-adjust-pct" type="text" inputmode="decimal" value="">
						</label>
						<span id="cntr-order-adjust-warning" class="cntr-inline-warning" hidden></span>`
						: `<label>${STRINGS.amountLabel}
							<input id="cntr-order-adjust-amount" type="text" inputmode="decimal" value="${'tax' === field ? cart.orderTax : cart.shipping}">
						</label>`
				}
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-order-adjust-apply">${STRINGS.applyBtn}</button>
					<button type="button" id="cntr-order-adjust-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const amountInput = document.getElementById('cntr-order-adjust-amount');
		if (amountInput) amountInput.focus();
		submitOnEnter(amountInput, submitOrderAdjust);
		submitOnEnter(document.getElementById('cntr-order-adjust-pct'), submitOrderAdjust);
		const applyBtn = document.getElementById('cntr-order-adjust-apply');
		if (applyBtn) applyBtn.addEventListener('click', submitOrderAdjust);
		const cancelBtn = document.getElementById('cntr-order-adjust-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeOrderAdjust);
	}

	// -- B5: live X-report and register close ------------------------------------

	let xReportState = null; // { report } | { error: true }

	function renderMethodTable(rows) {
		if (!rows || !rows.length) return `<p>${STRINGS.noneLabel}</p>`;
		return `<table class="cntr-report-table">${rows
			.map((r) => `<tr><td>${escapeHtml(methodLabel(r.method))}</td><td>${formatMoney(r.total)}</td></tr>`)
			.join('')}</table>`;
	}

	function renderProductsBySku(rows) {
		if (!rows || !rows.length) return `<p>${STRINGS.noneLabel}</p>`;
		return `<table class="cntr-report-table">
			<thead><tr><th>${STRINGS.skuLabel}</th><th>${STRINGS.qtySoldLabel}</th><th>${STRINGS.footerTotalLabel}</th></tr></thead>
			${rows
				.map(
					(r) =>
						`<tr><td>${escapeHtml(r.sku)} — ${escapeHtml(r.name)}</td><td>${escapeHtml(String(parseFloat(r.qty) || 0))}</td><td>${formatMoney(r.total)}</td></tr>`
				)
				.join('')}
		</table>`;
	}

	function formulaSentence(formula, expected) {
		return fmt(STRINGS.formulaSentence, {
			opening: formatMoney(formula.opening),
			sale: formatMoney(formula.cash_sale),
			refund: formatMoney(formula.cash_refund),
			expense: formatMoney(formula.cash_expense),
			expected: formatMoney(expected),
		});
	}

	async function openXReport() {
		xReportState = {};
		renderXReport();
		try {
			const res = await fetch(`${CFG.restUrl}/shift/x-report?register_id=${CFG.registerId}`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			if (!res.ok) throw new Error('bad status');
			xReportState = { report: await res.json() };
		} catch (e) {
			xReportState = { error: true };
		}
		renderXReport();
	}

	function closeXReport() {
		xReportState = null;
		const root = document.getElementById('cntr-xreport');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	function renderXReport() {
		const root = document.getElementById('cntr-xreport');
		if (!root || !xReportState) return;
		const report = xReportState.report;
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.xReportTitle}</h2>
				${
					xReportState.error
						? `<p class="cntr-inline-warning">${STRINGS.xReportFailed}</p>`
						: !report
							? `<p>…</p>`
							: `<p>${STRINGS.openingFloatLabel.replace(' (৳)', '')}: ${formatMoney(report.opening_float)}</p>
								<h3>${STRINGS.sellByMethodLabel}</h3>
								${renderMethodTable(report.sell_by_method)}
								<p><b>${STRINGS.totalSalesLabel}:</b> ${formatMoney(report.sales_total)}</p>
								<h3>${STRINGS.refundByMethodLabel}</h3>
								${renderMethodTable(report.refund_by_method)}
								<p><b>${STRINGS.totalRefundLabel}:</b> ${formatMoney(report.refunds_total)}</p>
								<h3>${STRINGS.expenseByMethodLabel}</h3>
								${renderMethodTable(report.expense_by_method)}
								<p><b>${STRINGS.totalExpenseLabel}:</b> ${formatMoney(report.expense_total)}</p>
								<p><b>${STRINGS.expectedCashLabel}:</b> ${formatMoney(report.expected_cash)}</p>
								<p class="cntr-report-formula">${formulaSentence(report.formula, report.expected_cash)}</p>
								<h3>${STRINGS.productsSoldLabel}</h3>
								${renderProductsBySku(report.products_by_sku)}`
				}
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-xreport-close">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const closeBtn = document.getElementById('cntr-xreport-close');
		if (closeBtn) closeBtn.addEventListener('click', closeXReport);
	}

	let closeRegisterState = null; // { report } | { report, countedCash } | { error: true }

	function openCloseRegister() {
		if (!requireCap('cntr_close_shift', 'capDenyCloseShift')) return;
		closeRegisterState = {};
		renderCloseRegister();
		fetch(`${CFG.restUrl}/shift/x-report?register_id=${CFG.registerId}`, {
			headers: { 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
		})
			.then((res) => {
				if (!res.ok) throw new Error('bad status');
				return res.json();
			})
			.then((report) => {
				closeRegisterState = { report, countedCash: '' };
				renderCloseRegister();
			})
			.catch(() => {
				closeRegisterState = { error: true };
				renderCloseRegister();
			});
	}

	function closeCloseRegister() {
		closeRegisterState = null;
		const root = document.getElementById('cntr-close-register');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	/**
	 * The variance shown here is a PREVIEW — computed client-side against the
	 * report this modal fetched when it opened, purely so the cashier sees
	 * it before confirming (the goal: "reconciles ... without opening
	 * WordPress"). The AUTHORITATIVE variance is whatever POST /shift/close
	 * computes and stores server-side a moment later in submitCloseRegister()
	 * — this preview is never itself written anywhere.
	 */
	function varianceWords(countedCash, expectedCash) {
		const variance = (parseFloat(countedCash) || 0) - (parseFloat(expectedCash) || 0);
		if (Math.abs(variance) < 0.005) return STRINGS.varianceExact;
		return fmt(variance < 0 ? STRINGS.varianceShort : STRINGS.varianceOver, { amount: formatMoney(Math.abs(variance)) });
	}

	function renderCloseRegister() {
		const root = document.getElementById('cntr-close-register');
		if (!root || !closeRegisterState) return;
		const report = closeRegisterState.report;
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.closeRegisterTitle}</h2>
				${
					closeRegisterState.error
						? `<p class="cntr-inline-warning">${STRINGS.xReportFailed}</p>`
						: !report
							? `<p>…</p>`
							: `<p><b>${STRINGS.expectedCashLabel}:</b> ${formatMoney(report.expected_cash)}</p>
								<label>${STRINGS.countedCashLabel}
									<input id="cntr-close-register-counted" type="text" inputmode="decimal" value="${closeRegisterState.countedCash || ''}">
								</label>
								<p id="cntr-close-register-variance">${STRINGS.varianceLabel}: ${varianceWords(closeRegisterState.countedCash || '0', report.expected_cash)}</p>
								<span id="cntr-close-register-warning" class="cntr-inline-warning" hidden></span>`
				}
				<div class="cntr-modal-actions">
					${report ? `<button type="button" id="cntr-close-register-submit">${STRINGS.confirmCloseBtn}</button>` : ''}
					<button type="button" id="cntr-close-register-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const countedInput = document.getElementById('cntr-close-register-counted');
		if (countedInput) {
			countedInput.focus();
			countedInput.addEventListener('input', () => {
				closeRegisterState.countedCash = countedInput.value;
				const varianceEl = document.getElementById('cntr-close-register-variance');
				if (varianceEl && report) varianceEl.textContent = `${STRINGS.varianceLabel}: ${varianceWords(countedInput.value, report.expected_cash)}`;
			});
			submitOnEnter(countedInput, submitCloseRegister);
		}
		const submitBtn = document.getElementById('cntr-close-register-submit');
		if (submitBtn) submitBtn.addEventListener('click', submitCloseRegister);
		const cancelBtn = document.getElementById('cntr-close-register-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeCloseRegister);
	}

	function buildCloseSlipHtml(report, countedCash, variance) {
		return `<html><body style="${RECEIPT_FONT_STYLE}">
			<p style="font-weight:bold;font-size:1.2em;border:2px solid #000;padding:4px;text-align:center;">${STRINGS.closeSlipTitle}</p>
			<p>${escapeHtml(new Date().toLocaleString())}</p>
			<p>${STRINGS.totalSalesLabel}: ${formatMoney(report.sales_total)}</p>
			<p>${STRINGS.totalRefundLabel}: ${formatMoney(report.refunds_total)}</p>
			<p>${STRINGS.totalExpenseLabel}: ${formatMoney(report.expense_total)}</p>
			<p>${formulaSentence(report.formula, report.expected_cash)}</p>
			<p>${STRINGS.countedCashLabelShort}: ${formatMoney(countedCash)}</p>
			<p>${STRINGS.varianceLabel}: ${varianceWords(countedCash, report.expected_cash)} (${formatMoney(variance)})</p>
			<h3>${STRINGS.productsSoldLabel}</h3>
			${renderProductsBySku(report.products_by_sku)}
		</body></html>`;
	}

	/**
	 * The report this prints from is a FRESH fetch taken right after the
	 * close succeeds, not the pre-close preview — x_report() stays valid to
	 * call on an already-closed shift (see its own docblock: every query is
	 * a plain WHERE shift_id = %d over rows that don't change post-close),
	 * so this is the authoritative, final snapshot, never the client's own
	 * possibly-stale one.
	 */
	async function submitCloseRegister() {
		if (!closeRegisterState || !closeRegisterState.report) return;
		const report = closeRegisterState.report;
		const countedCash = closeRegisterState.countedCash || '0';
		const warn = document.getElementById('cntr-close-register-warning');
		try {
			const res = await fetch(`${CFG.restUrl}/shift/close`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
				body: JSON.stringify({ shift_id: report.shift_id, counted_cash: countedCash }),
			});
			if (!res.ok) throw new Error('bad status');
			const data = await res.json();
			const variance = data.report && data.report.shift ? data.report.shift.variance : (parseFloat(countedCash) || 0) - (parseFloat(report.expected_cash) || 0);

			// Re-fetch by shift_id (not register_id — the register no longer
			// has an OPEN shift to resolve) for the final print: the freshest
			// possible snapshot, taken after close, rather than trusting the
			// pre-close preview to still be accurate.
			const finalRes = await fetch(`${CFG.restUrl}/shift/x-report?shift_id=${report.shift_id}`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			const finalReport = finalRes.ok ? await finalRes.json() : report;

			closeCloseRegister();
			printReceipt(buildCloseSlipHtml(finalReport, countedCash, variance));
			CFG.shiftId = 0;
			await resolveShift();
			render();
		} catch (e) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = STRINGS.closeRegisterFailed;
			}
		}
	}

	let noSalePromptState = null; // {}

	function noSale() {
		if (!requireCap('cntr_no_sale', 'capDenyNoSale')) return;
		noSalePromptState = {};
		renderNoSalePrompt();
	}

	function closeNoSalePrompt() {
		noSalePromptState = null;
		const root = document.getElementById('cntr-no-sale');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	/**
	 * P1.14: the drawer only opens by printing (the printer driver's own
	 * "open before/after printing" setting is the actual kick), so the slip
	 * IS the mechanism, not a receipt of a sale that never happened. The
	 * audit row is written server-side by POST /shift/no-sale BEFORE the
	 * slip ever prints — offline, that write can't happen, so this refuses
	 * rather than printing an unaudited slip; P1.14's no-sale has no offline
	 * path, unlike a real sale's outbox.
	 */
	async function submitNoSale() {
		if (!noSalePromptState) return;
		const input = document.getElementById('cntr-no-sale-reason');
		const reason = input ? input.value.trim() : '';
		const warn = document.getElementById('cntr-no-sale-warning');
		if (!reason) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = STRINGS.reasonRequired;
			}
			return;
		}
		let ok = false;
		try {
			const res = await fetch(`${CFG.restUrl}/shift/no-sale`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
				body: JSON.stringify({ register_id: CFG.registerId, reason }),
			});
			ok = res.ok;
		} catch (e) {
			ok = false;
		}
		if (!ok) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = STRINGS.noSaleFailed;
			}
			return;
		}
		closeNoSalePrompt();
		printReceipt(buildNoSaleSlipHtml(reason));
	}

	function buildNoSaleSlipHtml(reason) {
		return `<html><body style="${RECEIPT_FONT_STYLE}">
			<p style="font-weight:bold;font-size:1.2em;border:2px solid #000;padding:4px;text-align:center;">${STRINGS.noSaleSlipTitle}</p>
			<p>${escapeHtml(new Date().toLocaleString())}</p>
			<p>${STRINGS.reasonPrefix} ${escapeHtml(reason)}</p>
		</body></html>`;
	}

	function renderNoSalePrompt() {
		const root = document.getElementById('cntr-no-sale');
		if (!root || !noSalePromptState) return;
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.noSaleTitle}</h2>
				<label>${STRINGS.reasonLabel}
					<input id="cntr-no-sale-reason" type="text">
				</label>
				<span id="cntr-no-sale-warning" class="cntr-inline-warning" hidden></span>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-no-sale-submit">${STRINGS.openDrawerPrint}</button>
					<button type="button" id="cntr-no-sale-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const input = document.getElementById('cntr-no-sale-reason');
		if (input) input.focus();
		submitOnEnter(input, submitNoSale);
		const submitBtn = document.getElementById('cntr-no-sale-submit');
		if (submitBtn) submitBtn.addEventListener('click', submitNoSale);
		const cancelBtn = document.getElementById('cntr-no-sale-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeNoSalePrompt);
	}
	/**
	 * F1 — P1.13: "Void the line, then the cart." First press voids the
	 * SELECTED line (gated on cntr_void_line) and clears the selection, so
	 * the immediate next press finds an empty selection rather than
	 * auto-picking another line to void; a second press with nothing
	 * selected confirms before clearing everything that's left.
	 */
	function voidLineThenCart() {
		if (!cart.lines.length) return;
		if (null !== cart.selectedIdx && cart.lines[cart.selectedIdx]) {
			if (!requireCap('cntr_void_line', 'capDenyVoidLine')) return;
			spliceLine(cart.selectedIdx); // splices the SELECTED line and clears the selection (idx === selectedIdx)
			render();
			return;
		}
		if (confirm(STRINGS.clearCartConfirm)) {
			cart.lines = [];
			cart.customer = emptyCustomer();
			cart.selectedIdx = null;
			applyPriceOverrides([], 0); // B2 — a voided cart starts over at the register's own price too
			resetOrderAdjustments(); // B4 — order-level adjustments reset with the cart
			render();
		}
	}
	// -- F7: quick-add form --------------------------------------------------------

	let quickAddState = null; // {}

	function openQuickAdd() {
		if (!requireCap('cntr_manage_stock', 'capDenyManageStock')) return;
		quickAddState = {};
		renderQuickAdd();
	}

	function closeQuickAdd() {
		quickAddState = null;
		const root = document.getElementById('cntr-quick-add');
		if (root) {
			root.hidden = true;
			root.innerHTML = '';
		}
		restoreFocus();
	}

	/** On success, adds the new product straight into the cart — P1.13's whole point of this key. */
	async function submitQuickAddForm() {
		if (!quickAddState) return;
		const name = document.getElementById('cntr-quick-add-name').value.trim();
		const price = document.getElementById('cntr-quick-add-price').value;
		const barcode = document.getElementById('cntr-quick-add-barcode').value.trim();
		const unitSelect = document.getElementById('cntr-quick-add-unit');
		const unitId = unitSelect && unitSelect.value ? parseInt(unitSelect.value, 10) : 0;
		const qty = document.getElementById('cntr-quick-add-qty').value;
		const warn = document.getElementById('cntr-quick-add-warning');

		// Client-side, before ever posting — the same rule the endpoint itself
		// enforces (Terminal::quick_add()'s "a name and a positive price are
		// required"), surfaced without a round trip for the common typo.
		if (!name || !(parseFloat(price) > 0)) {
			if (warn) {
				warn.hidden = false;
				warn.textContent = STRINGS.quickAddValidation;
			}
			return;
		}

		const result = await submitQuickAdd({ name, price, barcode, qty: qty || '0', unit_id: unitId });
		if (!result || !result.product_id) {
			// The endpoint's own 422 message (or a network failure), surfaced legibly rather than a silent no-op.
			if (warn) {
				warn.hidden = false;
				warn.textContent = (result && result.message) || STRINGS.quickAddFailed;
			}
			return;
		}

		// Mirrors the catalog row shape lookupProduct() hands addToCart() —
		// sku carries the barcode (Terminal::quick_add() itself calls
		// set_sku($barcode)); the real catalog row won't exist until the
		// next sync, so this is built by hand for the immediate add.
		const product = {
			id: result.product_id,
			name,
			sku: barcode,
			barcode: '',
			price: parseFloat(price).toFixed(4),
			sellable_qty: qty || '0',
		};
		closeQuickAdd();
		addToCart(product, 1);
	}

	function renderQuickAdd() {
		const root = document.getElementById('cntr-quick-add');
		if (!root || !quickAddState) return;
		root.innerHTML = `
			<div class="cntr-modal-box">
				<h2>${STRINGS.quickAddTitle}</h2>
				<label>${STRINGS.nameLabel}
					<input id="cntr-quick-add-name" type="text">
				</label>
				<label>${STRINGS.priceLabel}
					<input id="cntr-quick-add-price" type="text" inputmode="decimal">
				</label>
				<label>${STRINGS.barcodeSkuLabel}
					<input id="cntr-quick-add-barcode" type="text">
				</label>
				<label>${STRINGS.unitLabel}
					<select id="cntr-quick-add-unit">
						<option value="">—</option>
						${(CFG.units || []).map((u) => `<option value="${u.id}">${escapeHtml(u.name)}</option>`).join('')}
					</select>
				</label>
				<label>${STRINGS.openingQtyLabel}
					<input id="cntr-quick-add-qty" type="text" inputmode="decimal" value="0">
				</label>
				<span id="cntr-quick-add-warning" class="cntr-inline-warning" hidden></span>
				<div class="cntr-modal-actions">
					<button type="button" id="cntr-quick-add-submit">${STRINGS.addProductBtn}</button>
					<button type="button" id="cntr-quick-add-cancel">${STRINGS.cancelBtn}</button>
				</div>
			</div>
		`;
		root.hidden = false;
		const nameInput = document.getElementById('cntr-quick-add-name');
		if (nameInput) nameInput.focus();
		['cntr-quick-add-name', 'cntr-quick-add-price', 'cntr-quick-add-barcode', 'cntr-quick-add-qty'].forEach((id) =>
			submitOnEnter(document.getElementById(id), submitQuickAddForm)
		);
		const submitBtn = document.getElementById('cntr-quick-add-submit');
		if (submitBtn) submitBtn.addEventListener('click', submitQuickAddForm);
		const cancelBtn = document.getElementById('cntr-quick-add-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeQuickAdd);
	}

	// -- F1: selection, per-row qty/remove, focus discipline ---------------------

	/**
	 * True if the current user has $capKey; otherwise surfaces WHY the
	 * action didn't happen (never a silent no-op — the same standard F5's
	 * own capability-gated keys are held to) and returns false.
	 */
	/** U2 — messageKey names a full, pre-composed STRINGS sentence (capDeny*), not a fragment to interpolate: Bengali grammar doesn't compose the same way English "permission to X" does. */
	function requireCap(capKey, messageKey) {
		if (CFG.caps && CFG.caps[capKey]) return true;
		alert(STRINGS[messageKey] || `You don't have permission (${capKey}).`);
		return false;
	}

	function selectLine(idx) {
		if (!cart.lines[idx]) return;
		cart.selectedIdx = idx;
		render();
	}

	/** ↑/↓ — clamps at the ends, never wraps. */
	function moveSelection(delta) {
		if (!cart.lines.length) return;
		const current = null === cart.selectedIdx ? cart.lines.length - 1 : cart.selectedIdx;
		const next = Math.max(0, Math.min(cart.lines.length - 1, current + delta));
		cart.selectedIdx = next;
		render();
	}

	/**
	 * B3 — switching a line's unit converts its qty by the multiplier ratio
	 * (the same physical quantity, described in the new unit) and re-prices
	 * to that unit's OWN configured price — Units::resolve_price() already
	 * falls back to base_price × multiplier when no explicit per-unit price
	 * was set, so the common case is exactly "converts by the multiplier";
	 * an explicit bulk price (a 5kg bag priced under 5× the 1kg rate) is
	 * honoured for free, not a special case here. Marked as an override so
	 * a later customer attach/clear never claws it back — same reasoning as
	 * submitPriceOverride()'s own priceOverridden flag.
	 */
	function switchLineUnit(idx, unitId) {
		const line = cart.lines[idx];
		if (!line || !line.product.units) return;
		const nextUnit = line.product.units.find((u) => u.unit_id === parseInt(unitId, 10));
		if (!nextUnit || !line.unit) return;
		const oldMultiplier = parseFloat(line.unit.multiplier || '1');
		const newMultiplier = parseFloat(nextUnit.multiplier || '1');
		const currentQty = parseFloat(line.qty) || 0;
		line.qty = String((currentQty * oldMultiplier) / newMultiplier);
		line.unitPrice = nextUnit.price;
		line.unit = nextUnit;
		line.priceOverridden = true;
		render();
	}

	/**
	 * B3 — "type the total, we compute the price": back-solves unitPrice
	 * from a directly-typed line subtotal, qty held fixed — the loose-goods
	 * case (a customer hands over a fixed amount, the exact weight is
	 * whatever that buys), but useful for any line. Net of the line's own
	 * discount, same relationship lineTotal() itself defines. A blank or
	 * non-positive entry is ignored — the input just reverts to the real
	 * value on the next render(), never a divide-by-zero or a free item.
	 */
	function editLineSubtotal(idx, value) {
		const line = cart.lines[idx];
		if (!line) return;
		const qty = parseFloat(line.qty) || 0;
		const nextSubtotal = parseFloat(value);
		if (!(qty > 0) || !(nextSubtotal >= 0)) {
			render();
			return;
		}
		const discount = parseFloat(line.discount || '0');
		line.unitPrice = ((nextSubtotal + discount) / qty).toFixed(4);
		line.priceOverridden = true;
		render();
	}

	/** Per-row +/-. Reaching 0 or below removes the line, same as the remove control. */
	function adjustLineQty(idx, delta) {
		const line = cart.lines[idx];
		if (!line) return;
		const next = (parseFloat(line.qty) || 0) + delta;
		if (next <= 0) {
			spliceLine(idx);
		} else {
			line.qty = String(next);
			cart.selectedIdx = idx;
		}
		render();
	}

	function removeLine(idx) {
		if (!cart.lines[idx]) return;
		spliceLine(idx);
		render();
	}

	/**
	 * The render/focus bug: render() replaces root.innerHTML wholesale, so
	 * the `autofocus` attribute on the freshly-built #cntr-search does not
	 * reliably re-fire, and printReceipt()'s frame.contentWindow.focus()
	 * actively steals focus into the print iframe. Call this at the end of
	 * every render(), on every modal close, and it's what pulls focus back
	 * out of the print iframe too, since print always precedes a render()
	 * in every call site that prints.
	 */
	function restoreFocus() {
		const search = document.getElementById('cntr-search');
		if (search) search.focus();
	}

	// -- F3: attach a customer (household picker, never a guess) -----------------
	//
	// Rest\Sale::resolve_customer()'s own comment: "A household shares a
	// number; the till shows a picker rather than guessing which one is
	// right." This is that picker. lookupProduct()'s exact-match search and
	// this flow are deliberately separate — a phone number is never typed
	// into the product search box.

	let customerFlowState = null; // { step: 'phone' | 'candidates' | 'none', candidates?, phone? }

	function attachCustomerPrompt() {
		customerFlowState = { step: 'phone' };
		renderCustomerFlow();
	}

	async function submitCustomerLookup() {
		const phoneInput = document.getElementById('cntr-customer-phone');
		const phone = phoneInput ? phoneInput.value.trim() : '';
		if (!phone) return;

		let candidates = [];
		try {
			const res = await fetch(`${CFG.restUrl}/customers/lookup?phone=${encodeURIComponent(phone)}`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			const data = await res.json();
			candidates = Array.isArray(data) ? data : [];
		} catch (e) {
			candidates = [];
		}

		if (0 === candidates.length) {
			customerFlowState = { step: 'none', phone };
			renderCustomerFlow();
		} else if (1 === candidates.length) {
			// Never a second guess for a single match either — this IS the
			// resolved identity, fetched and shown, not assumed silently.
			await attachCustomer(candidates[0].customer_id);
		} else {
			// 2+ candidates: every row rendered, nothing attached until the
			// cashier picks one. See renderCustomerFlow()'s 'candidates' step.
			customerFlowState = { step: 'candidates', candidates };
			renderCustomerFlow();
		}
	}

	/** Fetches the full profile and holds it on cart.customer verbatim — see emptyCustomer()'s own shape. */
	async function attachCustomer(customerId) {
		let profile = null;
		try {
			const res = await fetch(`${CFG.restUrl}/customers/${customerId}/profile`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			if (res.ok) profile = await res.json();
		} catch (e) {
			profile = null;
		}
		if (profile) {
			cart.customer = {
				customer_id: profile.customer_id,
				display_name: profile.display_name,
				phone: profile.phone,
				balance: profile.balance,
				credit_limit: profile.credit_limit,
				available: profile.available,
				oldest_due_days: profile.oldest_due_days,
				can_credit: profile.can_credit,
				usual_items: profile.usual_items || [],
				price_group_id: profile.price_group_id || 0,
			};
			await applyCustomerPriceGroup();
		}
		closeCustomerFlow();
	}

	/**
	 * F4 (COUNTERFRONTEND.md) §2 — fetches the just-attached customer's own
	 * override table ONCE (never on the scan/search hot path) and re-prices
	 * every line already in the cart, so a customer attached mid-sale gets
	 * their own pricing on what they already scanned, not just what they
	 * scan next. 0 price_group_id (no group of their own) clears the map and
	 * leaves every line at the register's own price — the common case, one
	 * cheap no-op fetch skipped entirely.
	 */
	/**
	 * B2 — sets customerPriceMap from a group's own override rows and
	 * re-prices every line already in the cart. Shared by the auto-select
	 * on customer attach (F4 §2, groupId from their own price_group_id)
	 * and the cashier's own manual pick from the price-group selector —
	 * one re-pricing path, whichever chose the group. F5 — a supervisor's
	 * own deliberate price override always wins over an automatic
	 * group/register price, whether it was set before or after this call;
	 * see priceOverridden's own declaration.
	 */
	function applyPriceOverrides(rows, groupId) {
		customerPriceMap = new Map();
		cart.priceGroupId = groupId || 0;
		(Array.isArray(rows) ? rows : []).forEach((r) => {
			customerPriceMap.set(overrideKey(r.product_id, r.variation_id), r.price);
		});
		cart.lines.forEach((l) => {
			if (l.priceOverridden) return;
			const override = customerPriceMap.get(overrideKey(l.product.id, l.product.variation_id || 0));
			l.unitPrice = undefined !== override ? override : registerPriceFor(l);
		});
	}

	async function applyCustomerPriceGroup() {
		if (!cart.customer.price_group_id) {
			applyPriceOverrides([], 0);
			return;
		}
		let rows = [];
		try {
			const res = await fetch(`${CFG.restUrl}/customers/${cart.customer.customer_id}/price-overrides`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			rows = res.ok ? await res.json() : [];
		} catch (e) {
			// Network hiccup mid-attach — the register's own prices are still
			// correct and safe to fall through to; nothing here is a hard
			// failure of the attach itself.
		}
		applyPriceOverrides(rows, cart.customer.price_group_id);
	}

	/** B2 — the picker's own onChange: 0 (the "Register price" option) clears back to no override at all. */
	async function selectPriceGroup(groupId) {
		groupId = parseInt(groupId, 10) || 0;
		if (!groupId) {
			applyPriceOverrides([], 0);
			render();
			return;
		}
		let rows = [];
		try {
			const res = await fetch(`${CFG.restUrl}/price-groups/${groupId}/overrides`, {
				headers: { 'X-WP-Nonce': CFG.nonce },
				credentials: 'same-origin',
			});
			rows = res.ok ? await res.json() : [];
		} catch (e) {
			// Network hiccup — same fallback as applyCustomerPriceGroup(): the
			// register's own prices are still correct.
		}
		applyPriceOverrides(rows, groupId);
		render();
	}

	function clearCustomer() {
		cart.customer = emptyCustomer(); // the cart itself (cart.lines) is untouched
		// F4 §2 — a walk-in never keeps a departed customer's pricing; F5 — except a line a supervisor deliberately overrode, which stays exactly as set.
		applyPriceOverrides([], 0);
		render();
	}

	/** Ends the picker without attaching anyone — "bill as walk-in" and Cancel both land here. */
	function closeCustomerFlow() {
		customerFlowState = null;
		render(); // rebuilds #cntr-customer back to its static, hidden state too
	}

	/**
	 * Targeted re-render of ONLY #cntr-customer, mirroring
	 * renderReturnFlow()'s own pattern — the main cart/header render()
	 * only runs once this flow actually ends (closeCustomerFlow() /
	 * attachCustomer()), so an in-progress phone entry is never wiped by an
	 * unrelated render() elsewhere.
	 */
	function renderCustomerFlow() {
		const root = document.getElementById('cntr-customer');
		if (!root || !customerFlowState) return;

		let body = '';
		if ('phone' === customerFlowState.step) {
			body = `
				<label>${STRINGS.customerPhoneLabel}
					<input id="cntr-customer-phone" type="text" inputmode="tel" placeholder="01XXXXXXXXX">
				</label>
				<div class="cntr-modal-actions">
					<button id="cntr-customer-lookup">${STRINGS.findBtn}</button>
					<button id="cntr-customer-cancel">${STRINGS.cancelBtn}</button>
				</div>
			`;
		} else if ('candidates' === customerFlowState.step) {
			body = `
				<p>${STRINGS.multipleMatches}</p>
				<ul class="cntr-customer-candidates">
					${customerFlowState.candidates
						.map(
							(c) => `<li data-customer-id="${c.customer_id}">
								<span class="cntr-customer-candidate-name">${escapeHtml(c.display_name || STRINGS.noNamePlaceholder)}</span>
								<span class="cntr-customer-candidate-last">${c.last_order_at ? escapeHtml(STRINGS.lastOrderPrefix) + ' ' + escapeHtml(c.last_order_at) : ''}</span>
							</li>`
						)
						.join('')}
				</ul>
				<div class="cntr-modal-actions">
					<button id="cntr-customer-cancel">${STRINGS.cancelBtn}</button>
				</div>
			`;
		} else if ('none' === customerFlowState.step) {
			body = `
				<p>${fmt(STRINGS.noCustomerFound, { phone: escapeHtml(customerFlowState.phone) })}</p>
				<div class="cntr-modal-actions">
					<button id="cntr-customer-walkin">${STRINGS.billAsWalkin}</button>
					<button id="cntr-customer-cancel">${STRINGS.cancelBtn}</button>
				</div>
			`;
		}

		root.innerHTML = `<div class="cntr-modal-box"><h2>${STRINGS.attachCustomerTitle}</h2>${body}</div>`;
		root.hidden = false;

		const phoneInput = document.getElementById('cntr-customer-phone');
		if (phoneInput) phoneInput.focus();
		submitOnEnter(phoneInput, submitCustomerLookup);

		const lookupBtn = document.getElementById('cntr-customer-lookup');
		if (lookupBtn) lookupBtn.addEventListener('click', submitCustomerLookup);

		root.querySelectorAll('.cntr-customer-candidates li').forEach((li) => {
			li.addEventListener('click', () => attachCustomer(parseInt(li.dataset.customerId, 10)));
		});

		const walkinBtn = document.getElementById('cntr-customer-walkin');
		if (walkinBtn) walkinBtn.addEventListener('click', closeCustomerFlow);

		const cancelBtn = document.getElementById('cntr-customer-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', closeCustomerFlow);
	}

	/**
	 * U1 — "the plan's answer to discoverability was 'print it on a card and
	 * tape it to the counter'; a row of labels costs nothing and trains a
	 * new cashier without the card." Every real key, not just the mockup's
	 * illustrative subset. `cap` is optional — most keys (qty, hold, resume,
	 * customer, pay, return) carry no capability at all; where one exists,
	 * the bar dims the label (never blocks the key itself, which still
	 * surfaces its own reason via requireCap() if actually pressed).
	 */
	const KEY_BAR = [
		{ key: 'F2', label: STRINGS.keyPay },
		{ key: 'F3', label: STRINGS.keyQty },
		{ key: 'F4', label: STRINGS.keyDiscount, cap: 'cntr_discount_line' },
		{ key: 'F5', label: STRINGS.keyNewItem, cap: 'cntr_manage_stock' },
		{ key: 'F6', label: STRINGS.keyCustomer },
		{ key: 'F7', label: STRINGS.keyHold },
		{ key: 'F8', label: STRINGS.keyResume },
		{ key: 'F9', label: STRINGS.keyReturn },
		{ key: 'F10', label: STRINGS.keyNoSale, cap: 'cntr_no_sale' },
		{ key: 'Esc', label: STRINGS.keyVoid, cap: 'cntr_void_line' },
	];

	function render() {
		const root = document.getElementById('cntr-pos-root');
		if (!root) return;

		// F1 — preserve the search box's own in-progress value and caret
		// position across the innerHTML rebuild below (e.g. a row click
		// while the cashier has half-typed an unrelated search). A
		// just-completed add already cleared search.value itself (see the
		// 'input' listener below) BEFORE calling addToCart() — by the time
		// render() runs, that clearing has already happened, so this
		// correctly preserves "empty" for that case rather than reviving
		// the just-consumed code.
		const prevSearch = document.getElementById('cntr-search');
		const prevValue = prevSearch ? prevSearch.value : '';
		const prevCaret = prevSearch ? prevSearch.selectionStart : null;

		const footer = footerTotals();
		// U1 — "Shift R1-000042", the same {prefix}-{shift, zero-padded 6}
		// convention nextReceiptNo() already uses for the receipt number
		// itself, so the header names the same shift a printed receipt does.
		const shiftLabel = `${CFG.registerPrefix || 'R0'}-${String(CFG.shiftId || 0).padStart(6, '0')}`;
		root.innerHTML = `
			<div class="cntr-pos">
				<header class="cntr-pos-header">
					<span class="cntr-pos-title">Counter</span>
					<span id="cntr-net-status" class="cntr-net-status ${navigator.onLine ? 'cntr-net-online' : 'cntr-net-offline'}">${navigator.onLine ? STRINGS.netOnline : STRINGS.netOffline}</span>
					${outboxLockedState ? `<span id="cntr-outbox-locked" class="cntr-outbox-locked">${STRINGS.outboxLocked}</span>` : ''}
					<span class="cntr-pos-shift">${STRINGS.shiftLabel} ${escapeHtml(shiftLabel)}</span>
					<button type="button" id="cntr-xreport-btn" class="cntr-toolbar-icon" aria-label="${STRINGS.xReportAria}" title="${STRINGS.xReportAria}">&#128188;</button>
					${
						CFG.caps && CFG.caps.cntr_close_shift
							? `<button type="button" id="cntr-close-register-btn" class="cntr-toolbar-icon" aria-label="${STRINGS.closeRegisterAria}" title="${STRINGS.closeRegisterAria}">&#10062;</button>`
							: ''
					}
				</header>
				<div class="cntr-pos-main">
					<section class="cntr-panel cntr-panel-customer">
						<h2 class="cntr-panel-title">${STRINGS.panelCustomerTitle}</h2>
						${
							CFG.priceGroups && CFG.priceGroups.length
								? `<div class="cntr-price-group-picker">
									<label for="cntr-price-group">${STRINGS.priceGroupLabel}
										<select id="cntr-price-group">
											<option value="0">${STRINGS.priceGroupRegister}</option>
											${CFG.priceGroups
												.map((g) => `<option value="${g.id}"${g.id === cart.priceGroupId ? ' selected' : ''}>${escapeHtml(g.name)}</option>`)
												.join('')}
										</select>
									</label>
									${cart.priceGroupId ? `<span class="cntr-price-group-active">${fmt(STRINGS.priceGroupActive, { name: escapeHtml((CFG.priceGroups.find((g) => g.id === cart.priceGroupId) || {}).name || '') })}</span>` : ''}
								</div>`
								: ''
						}
						<div class="cntr-customer-strip">
							${
								cart.customer.customer_id
									? `<span class="cntr-customer-strip-name">${escapeHtml(cart.customer.display_name || '')}</span>
										<span class="cntr-customer-strip-phone">${escapeHtml(cart.customer.phone || '')}</span>
										<span class="cntr-customer-strip-balance">${STRINGS.customerOwes} ${formatMoney(cart.customer.balance ?? '0')}</span>
										${null !== cart.customer.available ? `<span class="cntr-customer-strip-limit">${STRINGS.customerLimit} ${formatMoney(cart.customer.credit_limit ?? '0')}</span>` : ''}
										${null !== cart.customer.available ? `<span class="cntr-customer-strip-available">${STRINGS.customerAvailable} ${formatMoney(cart.customer.available)}</span>` : ''}
										${cart.customer.oldest_due_days ? `<span class="cntr-customer-strip-oldest">${fmt(STRINGS.customerOldestDue, { n: cart.customer.oldest_due_days })}</span>` : ''}
										<button type="button" id="cntr-customer-clear" aria-label="${STRINGS.clearCustomerAria}">&times;</button>`
									: `<p class="cntr-panel-empty">${STRINGS.walkInPlaceholder}</p>`
							}
						</div>
					</section>
					<section class="cntr-panel cntr-panel-cart">
						<h2 class="cntr-panel-title">${STRINGS.panelCartTitle}</h2>
						<ul class="cntr-pos-cart">
							${cart.lines
								.map((l, i) => {
									const discount = parseFloat(l.discount || '0');
									const units = l.product.units || [];
									// B3 — stock is always held in base units server-side;
									// dividing by the SELECTED unit's own multiplier is
									// what makes "12 available" legible as "1 dozen".
									const stockInUnit =
										units.length && l.unit && null != l.product.sellable_qty
											? (parseFloat(l.product.sellable_qty) / parseFloat(l.unit.multiplier || '1')).toFixed(l.unit.allow_decimal ? 2 : 0)
											: null;
									return `<li data-idx="${i}" class="cntr-cart-line${i === cart.selectedIdx ? ' cntr-cart-line-selected' : ''}">
										<span class="cntr-cart-name">${escapeHtml(l.product.name || '')}</span>
										<span class="cntr-cart-qty">${l.qty}</span>
										${
											units.length
												? `<select class="cntr-cart-unit" data-idx="${i}">
													${units.map((u) => `<option value="${u.unit_id}"${l.unit && u.unit_id === l.unit.unit_id ? ' selected' : ''}>${escapeHtml(u.code || u.name)}</option>`).join('')}
												</select>
												<span class="cntr-cart-stock-in-unit">${null !== stockInUnit ? fmt(STRINGS.stockInUnit, { qty: stockInUnit, unit: escapeHtml(l.unit.code || '') }) : ''}</span>`
												: ''
										}
										<span class="cntr-cart-price">${formatMoney(l.unitPrice)}</span>
										${discount ? `<span class="cntr-cart-discount-badge">&minus;${formatMoney(discount)}</span>` : ''}
										${l.outOfStock ? `<span class="cntr-cart-outofstock-badge">${STRINGS.outOfStockBadge}</span>` : ''}
										<input type="text" inputmode="decimal" class="cntr-cart-subtotal" data-idx="${i}" value="${lineTotal(l).toFixed(2)}" aria-label="${STRINGS.editSubtotalAria}">
										<button type="button" class="cntr-cart-qty-dec" data-idx="${i}" aria-label="${STRINGS.decreaseQtyAria}">&minus;</button>
										<button type="button" class="cntr-cart-qty-inc" data-idx="${i}" aria-label="${STRINGS.increaseQtyAria}">+</button>
										<button type="button" class="cntr-cart-remove" data-idx="${i}" aria-label="${STRINGS.removeLineAria}">&times;</button>
									</li>`;
								})
								.join('')}
						</ul>
					</section>
					<section class="cntr-panel cntr-panel-grid">
						<h2 class="cntr-panel-title">${STRINGS.panelGridTitle}</h2>
						<div class="cntr-grid-chips">
							<button type="button" class="cntr-grid-chip${null === gridCategoryFilter ? ' cntr-grid-chip-active' : ''}" data-cat="">${STRINGS.gridAllCategories}</button>
							${gridCategories()
								.map(
									(c) =>
										`<button type="button" class="cntr-grid-chip${c.id === gridCategoryFilter ? ' cntr-grid-chip-active' : ''}" data-cat="${c.id}">${escapeHtml(c.name)}</button>`
								)
								.join('')}
						</div>
						<div class="cntr-grid-tiles">
							${gridProducts()
								.map((r) => {
									const unit = r.units && r.units[0] ? ' ' + escapeHtml(r.units[0].code || '') : '';
									return `<button type="button" class="cntr-grid-tile" data-id="${r.id}">
										<span class="cntr-grid-tile-name">${escapeHtml(r.name || '')}</span>
										<span class="cntr-grid-tile-sku">${escapeHtml(r.sku || '')}</span>
										<span class="cntr-grid-tile-price">${formatMoney(r.price)}</span>
										<span class="cntr-grid-tile-stock">${escapeHtml(String(null != r.sellable_qty ? r.sellable_qty : ''))}${unit}</span>
										${isLowStock(r) ? `<span class="cntr-grid-tile-lowstock">${STRINGS.lowStockBadge}</span>` : ''}
									</button>`;
								})
								.join('')}
						</div>
					</section>
				</div>
				${
					cart.customer.usual_items && cart.customer.usual_items.length
						? `<div class="cntr-usual-items">
							<span class="cntr-usual-items-label">${STRINGS.usualItemsLabel}</span>
							${cart.customer.usual_items
								.map((it, i) => `<button type="button" class="cntr-usual-item" data-idx="${i}">${escapeHtml(it.name)}</button>`)
								.join('')}
						</div>`
						: ''
				}
				<div class="cntr-pos-search">
					<div class="cntr-pos-search-row">
						<input id="cntr-search" type="text" placeholder="${escapeHtml(STRINGS.searchPlaceholder)}" autofocus>
						<span class="cntr-pos-total">${STRINGS.cartTotal} ${formatMoney(footer.total)}</span>
					</div>
					<ul id="cntr-search-results" class="cntr-search-results" hidden></ul>
				</div>
				<div class="cntr-pos-footer">
					<div class="cntr-footer-row"><span>${STRINGS.footerItemsLabel}</span><span>${formatMoney(footer.items)}</span></div>
					<div class="cntr-footer-row cntr-footer-total"><span>${STRINGS.footerTotalLabel}</span><span>${formatMoney(footer.total)}</span></div>
					<div class="cntr-footer-row cntr-footer-adjust">
						<span>${STRINGS.footerDiscountLabel}</span>
						<span>${footer.discount ? '−' + formatMoney(footer.discount) : '—'}</span>
						<button type="button" class="cntr-footer-edit" data-field="discount" aria-label="${fmt(STRINGS.footerEditAria, { field: STRINGS.footerDiscountLabel })}">&#9998;</button>
					</div>
					<div class="cntr-footer-row cntr-footer-adjust">
						<span>${STRINGS.footerTaxLabel}</span>
						<span>${footer.tax ? '+' + formatMoney(footer.tax) : '—'}</span>
						<button type="button" class="cntr-footer-edit" data-field="tax" aria-label="${fmt(STRINGS.footerEditAria, { field: STRINGS.footerTaxLabel })}">&#9998;</button>
					</div>
					<div class="cntr-footer-row cntr-footer-adjust">
						<span>${STRINGS.footerShippingLabel}</span>
						<span>${footer.shipping ? '+' + formatMoney(footer.shipping) : '—'}</span>
						<button type="button" class="cntr-footer-edit" data-field="shipping" aria-label="${fmt(STRINGS.footerEditAria, { field: STRINGS.footerShippingLabel })}">&#9998;</button>
					</div>
					${
						Math.abs(footer.roundOff) >= 0.005
							? `<div class="cntr-footer-row"><span>${STRINGS.footerRoundOffLabel}</span><span>${formatMoney(footer.roundOff)}</span></div>`
							: ''
					}
				</div>
				<footer class="cntr-pos-keybar">
					${KEY_BAR.map(
						(k) => `<span class="cntr-key${k.cap && CFG.caps && !CFG.caps[k.cap] ? ' cntr-key-disabled' : ''}"><b>${k.key}</b> ${escapeHtml(k.label)}</span>`
					).join('')}
				</footer>
			</div>
			<div id="cntr-tender" class="cntr-modal" hidden></div>
			<div id="cntr-quick-add" class="cntr-modal" hidden></div>
			<div id="cntr-return" class="cntr-modal" hidden></div>
			<div id="cntr-customer" class="cntr-modal" hidden></div>
			<div id="cntr-qty" class="cntr-modal" hidden></div>
			<div id="cntr-discount" class="cntr-modal" hidden></div>
			<div id="cntr-no-sale" class="cntr-modal" hidden></div>
			<div id="cntr-held" class="cntr-modal" hidden></div>
			<div id="cntr-order-adjust" class="cntr-modal" hidden></div>
			<div id="cntr-xreport" class="cntr-modal" hidden></div>
			<div id="cntr-close-register" class="cntr-modal" hidden></div>
		`;

		const search = document.getElementById('cntr-search');
		if (search) {
			search.value = prevValue;
			if (null !== prevCaret) search.setSelectionRange(prevCaret, prevCaret);

			search.addEventListener('input', () => {
				const q = search.value;
				if (!q) {
					clearSearchResults();
					return;
				}
				// A 13-digit weight barcode is never a useful leftover text
				// search, matched or not — cleared either way, same as before
				// this lookup path was unified with the scanner's.
				const isWeightShaped = /^\d{13}$/.test(q);
				const match = timedLookup(q);
				if (match) {
					// Cleared BEFORE addToCart() (which calls render()) so the
					// render() this triggers captures/restores "already
					// empty," not the just-consumed code — see the preserved
					// prevValue/prevCaret above. suppressNextEnter tells the
					// scanner's own Enter handler this exact keystroke
					// sequence is already fully handled (F1 — see decisions.md).
					search.value = '';
					suppressNextEnter = true;
					clearSearchResults();
					addToCart(match, 1);
				} else if (isWeightShaped) {
					search.value = '';
					suppressNextEnter = true;
					clearSearchResults();
				} else {
					// F6 — no exact hit. Debounced text search, never on the
					// hot path of a real scan/exact-typed code (P1.13's 5ms
					// budget is about THAT path, which the branches above
					// never touch); this one runs entirely against the
					// in-memory INDEX, no REST call, ~120ms after the
					// cashier stops typing.
					scheduleTextSearch(q);
				}
			});
		}

		root.querySelectorAll('.cntr-cart-line').forEach((li) => {
			li.addEventListener('click', (e) => {
				// The row's own qty/remove buttons handle themselves; the unit
				// select and subtotal input (B3) must never trigger a
				// mid-interaction render() — a native <select> losing its own
				// click, or an <input> losing focus/caret the instant it's
				// tapped, same class of bug F1's own prevValue/prevCaret exists
				// to prevent for the main search box.
				if (e.target.closest('button, select, input')) return;
				selectLine(parseInt(li.dataset.idx, 10));
			});
		});
		root.querySelectorAll('.cntr-cart-qty-inc').forEach((btn) => {
			btn.addEventListener('click', () => adjustLineQty(parseInt(btn.dataset.idx, 10), 1));
		});
		root.querySelectorAll('.cntr-cart-qty-dec').forEach((btn) => {
			btn.addEventListener('click', () => adjustLineQty(parseInt(btn.dataset.idx, 10), -1));
		});
		root.querySelectorAll('.cntr-cart-remove').forEach((btn) => {
			btn.addEventListener('click', () => removeLine(parseInt(btn.dataset.idx, 10)));
		});

		// B3 — per-line unit switch and the editable subtotal.
		root.querySelectorAll('.cntr-cart-unit').forEach((select) => {
			select.addEventListener('change', () => switchLineUnit(parseInt(select.dataset.idx, 10), select.value));
		});
		root.querySelectorAll('.cntr-cart-subtotal').forEach((input) => {
			input.addEventListener('change', () => editLineSubtotal(parseInt(input.dataset.idx, 10), input.value));
		});

		// B4 — the footer's own pencil rows.
		root.querySelectorAll('.cntr-footer-edit').forEach((btn) => {
			btn.addEventListener('click', () => openOrderAdjust(btn.dataset.field));
		});

		// B5 — the 💼 X-report and ❎ close-register toolbar icons.
		const xReportBtn = document.getElementById('cntr-xreport-btn');
		if (xReportBtn) xReportBtn.addEventListener('click', openXReport);
		const closeRegisterBtn = document.getElementById('cntr-close-register-btn');
		if (closeRegisterBtn) closeRegisterBtn.addEventListener('click', openCloseRegister);

		// B1 — the tile grid: a category chip narrows gridProducts(), a tile
		// tap adds 1 unit the same way a search-result click does.
		root.querySelectorAll('.cntr-grid-chip').forEach((btn) => {
			btn.addEventListener('click', () => {
				const cat = btn.dataset.cat;
				gridCategoryFilter = '' === cat ? null : parseInt(cat, 10);
				render();
			});
		});
		root.querySelectorAll('.cntr-grid-tile').forEach((btn) => {
			btn.addEventListener('click', () => {
				// The raw INDEX row, unmodified — same object shape
				// addHighlightedResult() already hands addToCart() from a
				// search-result click.
				const product = INDEX.all.find((r) => r.id === parseInt(btn.dataset.id, 10));
				if (product) addToCart(product, 1);
			});
		});

		const customerClear = document.getElementById('cntr-customer-clear');
		if (customerClear) {
			customerClear.addEventListener('click', clearCustomer);
		}

		const priceGroupSelect = document.getElementById('cntr-price-group');
		if (priceGroupSelect) {
			priceGroupSelect.addEventListener('change', () => selectPriceGroup(priceGroupSelect.value));
		}

		root.querySelectorAll('.cntr-usual-item').forEach((btn) => {
			btn.addEventListener('click', () => addUsualItem(parseInt(btn.dataset.idx, 10)));
		});

		renderSearchResults(); // F6 — the template above always rebuilds #cntr-search-results empty; re-syncs it to searchResultsState so an in-progress search list survives an unrelated render() elsewhere (same class of gap F1's own prevValue/prevCaret preservation exists for)
		restoreFocus(); // F1 — end of every render(); also what pulls focus back out of the print iframe after every print, since print always precedes a render() in every call site that prints
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	}

	// -- Shift resolution ---------------------------------------------------------
	// A shift must be opened while online — the shift id is what makes offline
	// receipt numbers safe (see nextReceiptNo()), and closing needs server-side
	// totals anyway. Deliberately no register-picker or shift UI beyond the
	// minimum needed to boot: check for an open shift, offer to open one.

	/**
	 * F9 — was window.prompt(...). This runs before boot()'s own first
	 * render() ever builds #cntr-pos-root, so none of the usual
	 * innerHTML-into-a-static-container modals exist yet to reuse; appended
	 * directly to document.body (which DOES already exist this early) and
	 * removed once answered, rather than restructuring boot()'s own order
	 * just to reach a container that would otherwise sit empty and hidden
	 * through this entire step anyway.
	 */
	function promptOpeningFloat() {
		return new Promise((resolve) => {
			const overlay = document.createElement('div');
			overlay.className = 'cntr-modal';
			overlay.innerHTML = `
				<div class="cntr-modal-box">
					<h2>${STRINGS.noShiftOpenTitle}</h2>
					<label>${STRINGS.openingFloatLabel}
						<input id="cntr-boot-opening-float" type="text" inputmode="decimal" value="0.00">
					</label>
					<div class="cntr-modal-actions">
						<button type="button" id="cntr-boot-opening-float-submit">${STRINGS.openShiftBtn}</button>
						<button type="button" id="cntr-boot-opening-float-cancel">${STRINGS.cancelBtn}</button>
					</div>
				</div>
			`;
			document.body.appendChild(overlay);
			const input = overlay.querySelector('#cntr-boot-opening-float');
			if (input) {
				input.focus();
				input.select();
			}
			overlay.querySelector('#cntr-boot-opening-float-submit').addEventListener('click', () => {
				const value = input ? input.value : '0.00';
				overlay.remove();
				resolve(value || '0.00');
			});
			overlay.querySelector('#cntr-boot-opening-float-cancel').addEventListener('click', () => {
				overlay.remove();
				resolve(null);
			});
		});
	}

	async function resolveShift() {
		if (!CFG.registerId) return; // no active register configured — nothing to resolve
		const res = await fetch(`${CFG.restUrl}/shift/current?register_id=${CFG.registerId}`, {
			headers: { 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
		});
		const data = await res.json();
		if (data.shift && data.shift.id) {
			CFG.shiftId = parseInt(data.shift.id, 10);
			return;
		}
		const openingFloat = await promptOpeningFloat();
		if (null === openingFloat) return;
		const openRes = await fetch(`${CFG.restUrl}/shift/open`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
			body: JSON.stringify({ register_id: CFG.registerId, opening_float: openingFloat || '0.00' }),
		});
		const openData = await openRes.json();
		if (openData.shift_id) CFG.shiftId = parseInt(openData.shift_id, 10);
	}

	// -- Offline indicator and shell cache (P7.1) --------------------------------------
	//
	// "An explicit online/offline indicator the cashier can see at a glance, not a
	// subtle colour change" — render() already reads navigator.onLine directly into
	// the header's own text ("Online"/"Offline"), so any render() call is correct by
	// construction; these two listeners force an immediate render the exact instant
	// the browser itself detects the transition, rather than waiting for the next
	// unrelated state change to happen to repaint it.

	window.addEventListener('online', render);
	window.addEventListener('offline', render);

	if ('serviceWorker' in navigator) {
		// Served from site root (not this file's own /wp-content/.../assets/ path) so
		// its default scope already covers /pos/ — see includes/Pos/ServiceWorker.php.
		navigator.serviceWorker.register('/counter-sw.js').catch(() => {
			// A failed registration must not block the till from working online —
			// it only means the shell will not survive a dropped connection this
			// session, not that this session itself is broken.
		});
	}

	// -- Outbox drain triggers (P7.2) ---------------------------------------------------
	//
	// Drained the instant the browser itself reports connectivity back, AND on a
	// plain interval — the 'online' event alone would miss an outbox entry whose
	// own backoff window happens to still be running at the moment connectivity
	// returns; the poll catches it once that window elapses without needing a
	// second 'online' event that will never come.

	window.addEventListener('online', () => {
		drainOutbox().catch(() => {});
	});
	setInterval(() => {
		drainOutbox().catch(() => {});
	}, OUTBOX_DRAIN_MS);

	// -- Age lock badge (P7.6) -----------------------------------------------------
	//
	// The badge is a periodic refresh of outboxLockedState, piggybacked on the
	// same triggers a drain runs on — a real sync (draining) is the only thing
	// that can clear the lock, so checking right after a drain attempt is
	// exactly when the badge is most likely to have something new to show.
	// submitSale() never reads this cache; see its own comment.

	async function refreshOutboxLockBadge() {
		const db = await openDb();
		const entries = await idbOutboxGetAll(db);
		const locked = isOutboxLocked(entries, Date.now());
		if (locked !== outboxLockedState) {
			outboxLockedState = locked;
			render();
		}
	}

	window.addEventListener('online', () => {
		refreshOutboxLockBadge().catch(() => {});
	});
	setInterval(() => {
		refreshOutboxLockBadge().catch(() => {});
	}, OUTBOX_DRAIN_MS);

	// -- Boot -------------------------------------------------------------------------

	async function boot() {
		await resolveShift();
		const db = await openDb();
		drainOutbox().catch(() => {}); // whatever survived from a previous session, attempted once immediately rather than waiting for the first poll tick
		refreshOutboxLockBadge().catch(() => {});
		await syncCatalog(db);
		const rows = await idbGetAll(db);
		buildIndex(rows.filter((r) => !r.deleted));
		cart.heldSales = await idbHeldGetAll(db); // F8 — survives a refresh or a crash, not just an in-memory array

		initScanner(
			(code) => {
				const match = timedLookup(code);
				if (match) addToCart(match, 1);
			},
			() => checkoutExactCash()
		);
		initKeyboardMap();
		render();

		setInterval(() => {
			syncCatalog(db)
				.then(() => idbGetAll(db))
				.then((freshRows) => buildIndex(freshRows.filter((r) => !r.deleted)));
		}, POLL_MS);

		document.addEventListener('visibilitychange', () => {
			if (document.visibilityState === 'visible') {
				syncCatalog(db)
					.then(() => idbGetAll(db))
					.then((freshRows) => buildIndex(freshRows.filter((r) => !r.deleted)));
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	window.CNTR = window.CNTR || {};
	window.CNTR._pos = { submitSale, submitQuickAdd, cart, searchByText, parseWeightBarcode, drainOutbox, queueSale, buildOfflineReceiptHtml, pendingOutboxEntries, isOutboxLocked, openReturnFlow, fetchOrderLookup, submitReturn, buildReturnReceiptHtml, footerTotals };
})();
