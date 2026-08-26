NAV = '''<nav class="sb">
<div class="logo">Counter <small>Peapip General Store · BDT</small></div>
<div class="g">Main</div>
<a href="#" class="{home}">Dashboard</a>
<a href="#" class="{pos}">POS Terminal <span class="c">↗</span></a>
<div class="g">Contacts</div>
<a href="#">Customers <span class="c">1,284</span></a>
<a href="#">Suppliers <span class="c">37</span></a>
<a href="#">Customer Groups</a>
<div class="g">Products</div>
<a href="#" class="{prod}">All Products <span class="c">2,140</span></a>
<a href="#">Add Product</a>
<a href="#">Update Price</a>
<a href="#" class="{lab}">Print Labels</a>
<a href="#">Price Groups <span class="c">3</span></a>
<a href="#">Units · Categories · Brands</a>
<div class="g">Purchases</div>
<a href="#" class="{pur}">Purchase Orders <span class="c">12</span></a>
<a href="#">Add Purchase</a>
<a href="#">Receiving</a>
<a href="#">Purchase Return</a>
<div class="g">Sell</div>
<a href="#" class="{sell}">All Sales</a>
<a href="#">POS Sales</a>
<a href="#">Drafts · Quotations</a>
<a href="#">Sell Return</a>
<a href="#">Shipments <span class="c">8</span></a>
<a href="#">Discounts</a>
<div class="g">Stock</div>
<a href="#">Locations <span class="c">2</span></a>
<a href="#">Stock Transfers</a>
<a href="#">Stock Adjustments</a>
<a href="#">Batches &amp; Expiry</a>
<a href="#">Stocktake</a>
<div class="g">Money</div>
<a href="#">Expenses</a>
<a href="#">Payment Accounts</a>
<a href="#">Receivables (বাকি)</a>
<a href="#">Payables</a>
<a href="#">Cash Flow</a>
<div class="g">Reports</div>
<a href="#" class="{pl}">Profit / Loss</a>
<a href="#" class="{reg}">Register Report</a>
<a href="#">Stock Report</a>
<a href="#">Trending Products</a>
<a href="#">Tax &amp; VAT Exports</a>
<a href="#">Activity Log</a>
<div class="g">People &amp; Admin</div>
<a href="#">Employees · Attendance</a>
<a href="#" class="{rol}">Roles &amp; Permissions</a>
<a href="#">Settings</a>
<a href="#">Health</a>
</nav>'''

def nav(**kw):
    keys=dict(home='',pos='',prod='',lab='',pur='',sell='',pl='',reg='',rol='')
    keys.update({k:'on' for k in kw})
    return NAV.format(**keys)

TOP = '''<div class="tb">
  <div class="ic">☰</div>
  <div class="ic">＋</div>
  <div class="ic">🖩</div>
  <span class="tb-pos pos">POS Terminal</span>
  <div class="ic">৳</div>
  <div class="sp"></div>
  <span class="pill">Main branch ▾</span>
  <span class="pill">26-08-2026</span>
  <div class="ic">🔔</div>
  <span class="usr">Rehana ▾</span>
</div>'''

def page(title, body, css='app.css'):
    return f'''<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{title}</title>
<link rel="stylesheet" href="{css}"></head><body>{body}</body></html>'''
