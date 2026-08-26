# -*- coding: utf-8 -*-
from _nav import nav, TOP, page
TOOLS = '''<div class="tools"><span style="font-size:12.5px;color:#79839a">Show</span>
<select class="btn3"><option>25</option></select>
<button class="exp">CSV</button><button class="exp">Excel</button><button class="exp">Print</button>
<button class="exp">Columns</button><button class="exp">PDF</button>
<div class="sp"></div><input placeholder="Search…"></div>'''

# ---------- 06 Add Purchase — the margin-linkage grid ----------
LINES=[
 ("Chinigura Rice 5kg","RICE-CHI-5","100","520.00","2.5","507.00","50,700.00","22.3","620.00"),
 ("Soyabin Oil 1L","OIL-SOY-1","240","155.00","2.0","151.90","36,456.00","21.8","185.00"),
 ("Sugar 1kg","SUG-WHT-1","150","115.00","2.5","112.13","16,819.50","20.4","135.00"),
 ("Red Lentil 1kg","PUL-RED-1","80","137.00","2.0","134.26","10,740.80","22.9","165.00"),
 ("Powder Milk 500g","DRY-MLK-500","60","400.00","2.0","392.00","23,520.00","22.4","480.00"),
]
def pl(l):
    n,s,q,c0,d,c1,tot,m,sp=l
    return f'''<tr>
<td><b>{n}</b><div style="font-size:11.5px;color:#79839a">{s}</div></td>
<td class="r"><input class="num" value="{q}" style="width:62px;text-align:right;padding:5px 7px;border:1px solid #e2e6ec;border-radius:5px"></td>
<td class="r num">৳ {c0}</td><td class="r num">{d}%</td><td class="r num">৳ {c1}</td>
<td class="r num"><b>৳ {tot}</b></td>
<td class="r"><input class="num" value="{m}" style="width:56px;text-align:right;padding:5px 7px;border:1px solid #2f6ce5;border-radius:5px;color:#2f6ce5;font-weight:600"></td>
<td class="r"><input class="num" value="{sp}" style="width:74px;text-align:right;padding:5px 7px;border:1px solid #2f6ce5;border-radius:5px;font-weight:600"></td>
</tr>'''

PURCHASE = f'''{nav(pur=1)}<div class="main">{TOP}<div class="body">
<h1>Add purchase — PO-00187</h1><div class="crumb">Rahim Traders · receiving into Main branch · reference auto-generated</div>
<div class="card"><div class="cb" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
  <div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Supplier</label><select style="width:100%;padding:7px 9px;border:1px solid #e2e6ec;border-radius:5px"><option>Rahim Traders</option></select></div>
  <div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Purchase status</label><select style="width:100%;padding:7px 9px;border:1px solid #e2e6ec;border-radius:5px"><option>Received</option><option>Ordered</option><option>Pending</option></select></div>
  <div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Location</label><select style="width:100%;padding:7px 9px;border:1px solid #e2e6ec;border-radius:5px"><option>Main branch</option></select></div>
  <div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Purchase date</label><input value="26-08-2026" style="width:100%;padding:7px 9px;border:1px solid #e2e6ec;border-radius:5px"></div>
  <div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Pay term</label><input value="30 days" style="width:100%;padding:7px 9px;border:1px solid #e2e6ec;border-radius:5px"></div>
</div></div>
<div class="card">
<div class="ch"><h2>Line items — cost, margin and selling price in one row</h2>
  <div><button class="btn3">Import from file</button> <button class="btn2">Scan carton barcode</button></div></div>
<table>
<thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Unit cost<br><span style="font-weight:400;text-transform:none">before discount</span></th><th class="r">Disc %</th><th class="r">Unit cost<br><span style="font-weight:400;text-transform:none">before tax</span></th><th class="r">Line total</th><th class="r">Margin %</th><th class="r">Selling price</th></tr></thead>
<tbody>{''.join(pl(l) for l in LINES)}</tbody>
<tfoot><tr><td colspan="5">Subtotal</td><td class="r num">৳ 138,236.30</td><td colspan="2"></td></tr></tfoot>
</table>
<div class="note">Editing margin recalculates selling price; editing selling price recalculates margin. Both write through to the price group on save.</div>
</div>
<div class="two">
<div class="card"><div class="ch"><h2>Landed cost</h2></div><div class="cb">
  <table><tbody>
    <tr><td>Goods subtotal</td><td class="r num">৳ 138,236.30</td></tr>
    <tr><td>Freight</td><td class="r num">৳ 3,200.00</td></tr>
    <tr><td>Unloading</td><td class="r num">৳ 800.00</td></tr>
    <tr><td>Purchase tax</td><td class="r num">৳ 10,679.70</td></tr>
  </tbody><tfoot><tr><td>Landed total</td><td class="r num">৳ 152,916.00</td></tr></tfoot></table>
  <div class="note" style="margin:11px -15px -13px">Freight and unloading distributed across lines by received value — the unit cost above is what FIFO will consume.</div>
</div></div>
<div class="card"><div class="ch"><h2>Payment</h2></div><div class="cb">
  <table><tbody>
    <tr><td>Amount paying now</td><td class="r num">৳ 100,000.00</td></tr>
    <tr><td>Method / account</td><td class="r">Bank transfer · Brac Bank</td></tr>
    <tr><td>Payment due</td><td class="r num" style="color:#c3373c;font-weight:700">৳ 52,916.00</td></tr>
    <tr><td>Due date</td><td class="r">25-09-2026</td></tr>
  </tbody></table>
  <div style="display:flex;gap:8px;margin-top:13px"><button class="btn">Save &amp; post to stock</button><button class="btn2">Save &amp; print labels</button></div>
</div></div>
</div>
</div></div>'''
open('p06-purchase.html','w').write(page("Add purchase", PURCHASE))

# ---------- 07 All sales ----------
SALES=[
 ("26-08-2026 14:12","R1-000042-0147","Karim Uddin","৳ 1,999.00","Partial","৳ 399.00","Cash+bKash+বাকি","Completed","—"),
 ("26-08-2026 13:58","R1-000042-0146","Walk-in","৳ 640.00","Paid","৳ 0.00","Cash","Completed","—"),
 ("26-08-2026 13:41","R1-000042-0145","Nasir Store","৳ 18,900.00","Due","৳ 18,900.00","বাকি","Completed","Packed"),
 ("26-08-2026 13:22","R1-000042-0144","Rina Akter","৳ 3,250.00","Paid","৳ 0.00","Card","Completed","Ordered"),
 ("26-08-2026 12:55","R1-000042-0143","Walk-in","৳ 1,850.00","Paid","৳ 0.00","Cash","Returned","—"),
 ("26-08-2026 12:30","R1-000042-0142","Salma Begum","৳ 2,400.00","Due","৳ 2,400.00","বাকি","Completed","—"),
]
def sr(s):
    d,inv,cust,tot,ps,due,m,st,ship=s
    pc={"Paid":"ok","Due":"bad","Partial":"wa"}[ps]
    sc={"Completed":"ok","Returned":"bad"}[st]
    shipc = "mut" if ship=="—" else ("inf" if ship=="Packed" else "wa")
    return (f'<tr><td>{d}</td><td><b>{inv}</b></td><td>{cust}</td><td class="r num">{tot}</td>'
            f'<td><span class="chip {pc}">{ps}</span></td><td class="r num">{due}</td><td>{m}</td>'
            f'<td><span class="chip {sc}">{st}</span></td><td><span class="chip {shipc}">{ship}</span></td>'
            f'<td class="r"><span class="act">Actions ▾</span></td></tr>')

SELLS = f'''{nav(sell=1)}<div class="main">{TOP}<div class="body">
<h1>All sales</h1><div class="crumb">Till and desk sales share one document · 147 transactions today</div>
<div class="card">
<div class="tabs"><span class="on">All sales</span><span>POS only</span><span>Drafts (3)</span><span>Quotations (1)</span><span>Sell returns (4)</span></div>
<div class="filt"><div class="fh">▾ Filters</div><div class="fg">
  <div><label>Location</label><select><option>Main branch</option></select></div>
  <div><label>Customer</label><select><option>All customers</option></select></div>
  <div><label>Payment status</label><select><option>Any</option><option>Due</option></select></div>
  <div><label>Date range</label><input value="26-08-2026 — 26-08-2026"></div>
  <div><label>Cashier</label><select><option>All users</option><option>Rehana</option></select></div>
  <div><label>Shipping status</label><select><option>Any</option></select></div>
  <div><label>Register / shift</label><select><option>R1 · shift 42</option></select></div>
  <div><label>Payment method</label><select><option>Any</option><option>বাকি (credit)</option></select></div>
</div></div>
{TOOLS}
<table>
<thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th class="r">Total</th><th>Payment</th><th class="r">Due</th><th>Method</th><th>Status</th><th>Shipping</th><th class="r">Actions</th></tr></thead>
<tbody>{''.join(sr(s) for s in SALES)}</tbody>
<tfoot><tr><td colspan="3">Totals — 147 transactions</td><td class="r num">৳ 84,320.00</td><td></td><td class="r num">৳ 21,699.00</td><td colspan="4"></td></tr></tfoot>
</table>
<div class="note">Row actions: View · Edit · Print invoice · Packing slip · Delivery note · Challan · View payments · Sell return · Invoice URL</div>
</div>
</div></div>'''
open('p07-sells.html','w').write(page("All sales", SELLS))
print("p06, p07 written")
