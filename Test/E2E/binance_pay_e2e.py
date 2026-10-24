#!/usr/bin/env python3
# Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
"""End-to-end checkout tests for JoePham_BinancePay against the Binance Pay mock (Test/Mock).

Scenarios:
  pay      the shopper pays on the Binance checkout and comes back: order invoiced, success page
  cancel   the shopper cancels on the Binance checkout: Binance order closed, Magento order cancelled, cart restored
  webhook  the shopper pays but never comes back: the webhook alone invoices the order
  refund   an admin refunds the paid order online from its invoice: Binance refund and refund webhook

The store must have the module enabled and pointed at the mock (API URL, key and secret), and Playwright for
Python installed. Example:

  python3 Test/E2E/binance_pay_e2e.py --base https://shop.test --magento-root /var/www/magento \\
      --product joust-duffle-bag.html --admin-user demo --admin-password secret

--video DIR records each scenario; --demo slows down and adds captions, for the README animations.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))


class Run:
    def __init__(self, args, page):
        self.args = args
        self.page = page

    # ---------------------------------------------------------------- helpers

    def caption(self, text, pause=1.6):
        """Show a caption over the page in demo mode; its progress bar keeps the frames moving while it shows."""
        if not self.args.demo:
            return
        self.page.evaluate(
            """([text, ms]) => {
                let el = document.getElementById('e2e-caption');
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'e2e-caption';
                    el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;'
                        + 'background:rgba(17,24,39,.92);color:#fff;font:600 20px/1.4 system-ui,sans-serif;overflow:hidden;'
                        + 'padding:12px 22px 16px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:90%;text-align:center';
                    document.body.appendChild(el);
                }
                el.innerHTML = '<span></span><i style="position:absolute;left:0;bottom:0;height:4px;width:0;background:#f0b90b"></i>';
                el.firstChild.textContent = text;
                el.lastChild.animate([{width: '0%'}, {width: '100%'}], {duration: ms, fill: 'forwards'});
            }""",
            [text, int(pause * 1000)],
        )
        self.page.wait_for_timeout(int(pause * 1000))

    def pause(self, seconds=1.0):
        if self.args.demo:
            self.page.wait_for_timeout(int(seconds * 1000))

    def order_state(self, increment_id):
        out = subprocess.run(
            ['php', os.path.join(HERE, 'order-state.php'), self.args.magento_root, increment_id],
            capture_output=True, text=True,
        )
        return json.loads(out.stdout or '{}')

    def wait_for_state(self, increment_id, check, timeout=40):
        end = time.time() + timeout
        while True:
            state = self.order_state(increment_id)
            if check(state):
                return state
            if time.time() > end:
                raise AssertionError(f'order {increment_id} did not reach the expected state: {json.dumps(state)[:600]}')
            time.sleep(1)

    def mock(self, path, form=None):
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        request = urllib.request.Request(self.args.mock + path, data=data, method='POST' if form is not None else 'GET')
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read())

    # ---------------------------------------------------------------- shopper

    def checkout_to_binance(self):
        """Add a product, check out as a guest with Binance Pay; returns the merchantTradeNo on the Binance page."""
        page, base = self.page, self.args.base
        page.goto(f'{base}/{self.args.product}')
        self.caption('A shopper adds a product to the cart')
        page.click('#product-addtocart-button')
        page.wait_for_selector('.message-success', timeout=90000)
        page.goto(f'{base}/checkout/')
        page.wait_for_selector('#customer-email', state='visible', timeout=180000)
        form = '#shipping-new-address-form'
        page.fill('#customer-email', f'e2e+{int(time.time() * 1000)}@example.com')
        page.fill(f'{form} input[name="firstname"]', 'Alex')
        page.fill(f'{form} input[name="lastname"]', 'Nguyen')
        page.fill(f'{form} input[name="street[0]"]', '100 Market Street')
        page.select_option(f'{form} select[name="country_id"]', 'US')
        page.select_option(f'{form} select[name="region_id"]', label='California')
        page.fill(f'{form} input[name="city"]', 'Los Angeles')
        page.fill(f'{form} input[name="postcode"]', '90001')
        page.fill(f'{form} input[name="telephone"]', '5555550100')
        page.wait_for_selector('input[value="flatrate_flatrate"]', timeout=90000)
        page.check('input[value="flatrate_flatrate"]')
        self.pause(0.6)
        page.click('#shipping-method-buttons-container button.continue')
        page.wait_for_selector('#joepham_binancepay', state='visible', timeout=90000)
        page.check('#joepham_binancepay')
        page.wait_for_selector('.payment-method._active .joepham-binancepay-note', state='visible')
        self.caption('At checkout the shopper picks Binance Pay')
        page.click('.payment-method._active button.checkout')
        page.wait_for_url(re.compile('^' + re.escape(self.args.mock) + '/checkout/'), timeout=90000)
        page.wait_for_selector('.amount')
        meta = page.inner_text('.meta')
        trade_no = re.search(r'Order (\w+)', meta).group(1)
        self.caption('Binance Pay shows the amount in USDT (test checkout)', 2.2)
        return trade_no

    def increment_id(self, trade_no):
        state = self.mock('/mock/state')
        assert trade_no in state['orders'], f'{trade_no} unknown to the mock'
        return trade_no[len(self.args.prefix):]

    # ---------------------------------------------------------------- scenarios

    def scenario_pay(self):
        page = self.page
        trade_no = self.checkout_to_binance()
        increment_id = self.increment_id(trade_no)
        self.caption('The shopper pays and goes back to the store')
        page.click('button.pay')
        page.wait_for_url(re.compile(r'/checkout/onepage/success'), timeout=90000)
        page.wait_for_selector('.checkout-success')
        assert increment_id in page.inner_text('.checkout-success'), 'order number not on the success page'
        self.caption('Paid: the order is confirmed', 2.0)

        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'processing')
        assert len(state['invoices']) == 1 and state['invoices'][0]['state'] == 2, state['invoices']
        assert state['payment']['info'].get('binance_transaction_id', '').startswith('M_P_'), state['payment']
        assert state['email_sent'], 'order email not sent'
        self.wait_for_webhook(trade_no, 'PAY_SUCCESS')
        return increment_id

    def scenario_cancel(self):
        page = self.page
        trade_no = self.checkout_to_binance()
        increment_id = self.increment_id(trade_no)
        self.caption('The shopper changes their mind and cancels')
        page.click('button.cancel')
        page.wait_for_url(re.compile(r'/checkout/cart'), timeout=90000)
        page.wait_for_selector('.message-error')
        assert 'not completed' in page.inner_text('.message-error')
        assert page.locator('#shopping-cart-table .cart.item').count() >= 1, 'cart not restored'
        self.caption('The order is cancelled and the cart is back', 2.0)

        self.wait_for_state(increment_id, lambda s: s.get('state') == 'canceled')
        assert self.mock('/mock/state')['orders'][trade_no]['status'] == 'CANCELED'
        self.wait_for_webhook(trade_no, 'PAY_CLOSED')
        return increment_id

    def scenario_webhook(self):
        trade_no = self.checkout_to_binance()
        increment_id = self.increment_id(trade_no)
        self.caption('The shopper pays in the Binance app and closes the browser')
        self.mock(f'/mock/pay/{trade_no}', {})
        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'processing')
        assert len(state['invoices']) == 1, state['invoices']
        self.wait_for_webhook(trade_no, 'PAY_SUCCESS')
        return increment_id

    def scenario_mismatch(self):
        trade_no = self.checkout_to_binance()
        increment_id = self.increment_id(trade_no)
        self.mock(f'/mock/pay/{trade_no}', {'amount': '0.01'})
        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'holded')
        assert not state['invoices'], 'an order paid with the wrong amount must not be invoiced'
        return increment_id

    def scenario_refund(self, increment_id=None):
        page, base = self.page, self.args.base
        increment_id = increment_id or self.args.order or self.scenario_pay()
        order = self.order_state(increment_id)

        page.goto(f'{base}/{self.args.admin_path}')
        if page.locator('#username').count():
            page.fill('#username', self.args.admin_user)
            page.fill('#login', self.args.admin_password)
            page.click('.action-login')
        page.wait_for_selector('#menu-magento-sales-sales', state='attached', timeout=90000)
        orders_url = page.get_attribute('#menu-magento-sales-sales a[href*="/sales/order/"]', 'href')
        page.goto(orders_url)
        page.wait_for_selector('#fulltext', timeout=90000)
        page.fill('#fulltext', increment_id)
        page.press('#fulltext', 'Enter')
        row = page.locator('tr.data-row', has_text=increment_id).first
        row.wait_for(timeout=60000)
        row.locator('a.action-menu-item', has_text='View').click()
        page.wait_for_selector('.order-payment-method', timeout=90000)
        page.locator('.order-payment-method').scroll_into_view_if_needed()
        assert 'Binance Transaction ID' in page.inner_text('.order-payment-method'), 'Binance IDs not shown to the admin'
        self.caption('The merchant sees the Binance Pay payment on the order', 2.4)

        page.click('#sales_order_view_tabs_order_invoices')
        invoice_row = page.locator('#sales_order_view_tabs_order_invoices_content tr.data-row').first
        invoice_row.wait_for(timeout=60000)
        invoice_row.click()
        page.wait_for_selector('button.credit-memo', timeout=90000)
        self.caption('Refund from the invoice: Credit Memo')
        page.click('button.credit-memo')
        page.wait_for_selector('button.submit-button.refund', timeout=90000)
        page.locator('button.submit-button.refund').scroll_into_view_if_needed()
        self.caption('"Refund" sends the money back through Binance Pay')
        page.click('button.submit-button.refund')
        page.wait_for_selector('.message-success', timeout=90000)

        trade_no = order['payment']['info']['binance_merchant_trade_no']
        state = self.wait_for_state(
            increment_id,
            lambda s: s.get('creditmemos') == 1
            and any('completed refund' in c for c in s.get('comments', [])),
        )
        assert state['state'] == 'closed', state['state']
        if self.args.demo:
            page.reload()
            page.locator('#order_history_block').scroll_into_view_if_needed()
            self.caption('Refunded online; Binance confirms it with a webhook', 2.8)
        assert self.mock('/mock/state')['orders'][trade_no]['status'] == 'FULL_REFUNDED'
        return increment_id

    def wait_for_webhook(self, trade_no, biz_status, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            for hook in self.mock('/mock/state')['webhooks'].values():
                if hook['merchantTradeNo'] == trade_no and hook['bizStatus'] == biz_status and hook['status'] != 'queued':
                    assert hook['status'] == 'delivered', f'webhook answered {hook.get("httpCode")}: {hook.get("response")}'
                    return hook
            time.sleep(1)
        raise AssertionError(f'no {biz_status} webhook for {trade_no}')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--base', required=True, help='store URL, e.g. https://shop.test')
    parser.add_argument('--magento-root', required=True)
    parser.add_argument('--product', required=True, help='URL key of a simple product, e.g. joust-duffle-bag.html')
    parser.add_argument('--mock', default='http://127.0.0.1:8701')
    parser.add_argument('--prefix', default='', help='the module\'s merchant trade number prefix')
    parser.add_argument('--scenario', default='pay,cancel,webhook,mismatch,refund')
    parser.add_argument('--admin-path', default='admin')
    parser.add_argument('--admin-user', default=os.environ.get('E2E_ADMIN_USER', ''))
    parser.add_argument('--admin-password', default=os.environ.get('E2E_ADMIN_PASSWORD', ''))
    parser.add_argument('--order', help='refund this paid order instead of placing a new one')
    parser.add_argument('--video', help='record each scenario into this directory')
    parser.add_argument('--demo', action='store_true', help='slow down and caption the steps')
    parser.add_argument('--headed', action='store_true')
    args = parser.parse_args()
    args.base = args.base.rstrip('/')
    args.mock = args.mock.rstrip('/')

    failures = 0
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=not args.headed)
        for name in [s.strip() for s in args.scenario.split(',') if s.strip()]:
            options = {'ignore_https_errors': True, 'viewport': {'width': 1280, 'height': 800}}
            if args.video:
                options['record_video_dir'] = os.path.join(args.video, name)
                options['record_video_size'] = {'width': 1280, 'height': 800}
            context = browser.new_context(**options)
            context.set_default_timeout(60000)
            if args.demo:
                # Keep store-specific admin notices and third-party menus out of the recordings.
                context.add_init_script(
                    "document.addEventListener('DOMContentLoaded', () => {"
                    " const style = document.createElement('style');"
                    " style.textContent = '#system_messages, .message-system,"
                    " #nav > li[id^=\"menu-\"]:not([id^=\"menu-magento-\"]) { display: none !important; }';"
                    " document.head.appendChild(style); });"
                )
            page = context.new_page()
            started = time.time()
            try:
                increment_id = getattr(Run(args, page), f'scenario_{name}')()
                print(f'PASS {name} order {increment_id} ({time.time() - started:.0f}s)', flush=True)
            except Exception as error:  # report every scenario, then fail
                failures += 1
                shot = os.path.join(args.video or '.', f'failure-{name}.png')
                try:
                    page.screenshot(path=shot, full_page=True)
                except Exception:
                    shot = '(no screenshot)'
                print(f'FAIL {name}: {error} [{page.url}] {shot}', flush=True)
            finally:
                context.close()
        browser.close()
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
