# -*- coding: utf-8 -*-
from _nav import nav, TOP, page

TOOLS = '''<div class="tools">
  <span style="font-size:12.5px;color:#79839a">Show</span>
  <select class="btn3"><option>25</option></select>
  <button class="exp">CSV</button><button class="exp">Excel</button><button class="exp">Print</button>
  <button class="exp">Columns</button><button class="exp">PDF</button>
  <div class="sp"></div><input placeholder="Search…">
</div>'''

# ---------- 04 Dashboard ----------
def kpi(l,v,d,cls=""):
    return f'<div class="kpi"><div class="l">{l}</div><div class="v num">{v}</div><div class="d {cls}">{d}</div></div>'

BARS=[("08",18),("09",31),("10",44),("11",52),("12",61),("13",47),("14",38),("15",43),("16",56),("17",74),("18",100),("19",94),("20",71),("21",36)]
def hours():
    out=[]
    for h,v in BARS:
        pk=" style=\"background:#2f6ce5\"" if v>=90 else ""
        out.append(f'<div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:6px;height:100%"><i style="display:block;width:100%;height:{v}%;background:#93b4ec;border-radius:3px 3px 0 0"{pk}></i><span style="font-size:10.5px;color:#79839a">{h}</span></div>')
    return "".join(out)

DASH = f'''{nav(home=1)}<div class="main">{TOP}<div class="body">
<h1>Dashboard</h1><div class="crumb">Main branch · Tuesday 26 August 2026 · live to 14:20</div>
<div class="kpis">
{kpi("Total sales","৳ 84,320","▲ 12.4% vs last Tuesday","up")}
{kpi("Net / gross margin","৳ 19,180","22.7% · COGS ৳ 65,140")}
{kpi("Invoice due (বাকি)","৳ 62,400","৳ 8,900 over 60 days","dn")}
{kpi("Sell return","৳ 1,850","4 returns today")}
</div>
<div class="kpis">
{kpi("Total purchase","৳ 152,916","PO-00187 received today")}
{kpi("Purchase due","৳ 88,400","3 suppliers")}
{kpi("Purchase return","৳ 0","—")}
{kpi("Expense","৳ 1,200","Cash · 2 entries")}
</div>
<div class="two">
  <div class="card"><div class="ch"><h2>Trending products — last 7 days</h2><span class="act">Full report</span></div>
    <table>
      <thead><tr><th>Product</th><th class="r">Units</th><th class="r">Revenue</th><th class="r">Margin</th></tr></thead>
      <tbody>
        <tr><td>Chinigura Rice 5kg</td><td class="r num">312</td><td class="r num">৳ 193,440</td><td class="r num">18.2%</td></tr>
        <tr><td>Soyabin Oil 1L</td><td class="r num">288</td><td class="r num">৳ 53,280</td><td class="r num">11.4%</td></tr>
        <tr><td>Miniket Rice 25kg</td><td class="r num">41</td><td class="r num">৳ 100,450</td><td class="r num">15.8%</td></tr>
        <tr><td>Sugar 1kg</td><td class="r num">204</td><td class="r num">৳ 27,540</td><td class="r num">9.1%</td></tr>
        <tr><td>Tea 400g</td><td class="r num">98</td><td class="r num">৳ 27,930</td><td class="r num">26.4%</td></tr>
      </tbody>
    </table>
  </div>
  <div class="card"><div class="ch"><h2>Product stock alert</h2><span class="chip bad">12 at or below threshold</span></div>
    <table>
      <thead><tr><th>Product</th><th class="r">Stock</th><th class="r">Alert at</th><th></th></tr></thead>
      <tbody>
        <tr><td>Turmeric 200g</td><td class="r num">4</td><td class="r num">10</td><td class="r"><span class="chip bad">Critical</span></td></tr>
        <tr><td>Red Lentil 1kg</td><td class="r num">6</td><td class="r num">15</td><td class="r"><span class="chip bad">Critical</span></td></tr>
        <tr><td>Powder Milk 500g</td><td class="r num">17</td><td class="r num">20</td><td class="r"><span class="chip wa">Low</span></td></tr>
        <tr><td>Mustard Oil 1L</td><td class="r num">14</td><td class="r num">15</td><td class="r"><span class="chip wa">Low</span></td></tr>
        <tr><td>Maida 1kg</td><td class="r num">31</td><td class="r num">30</td><td class="r"><span class="chip ok">OK</span></td></tr>
      </tbody>
    </table>
    <div class="note" style="display:flex;justify-content:space-between;align-items:center">Auto-generate a purchase order from every item below threshold <button class="btn">Generate PO</button></div>
  </div>
</div>
<div class="card"><div class="ch"><h2>Peak operating hours — last 7 days</h2><span class="act">Register report</span></div>
  <div style="display:flex;gap:6px;height:140px;padding:14px 15px 8px;align-items:stretch">{hours()}</div>
  <div class="note">Busiest 18:00–19:00 — 31% of daily takings. Two cashiers rostered at peak; the queue model suggests three.</div>
</div>
<div class="two">
  <div class="card"><div class="ch"><h2>Sales payment due (বাকি)</h2></div>
    <table><thead><tr><th>Customer</th><th class="r">Due</th><th class="r">Oldest</th></tr></thead><tbody>
      <tr><td>Karim Uddin</td><td class="r num">৳ 4,200</td><td class="r">34 d</td></tr>
      <tr><td>Nasir Store</td><td class="r num">৳ 18,900</td><td class="r">62 d</td></tr>
      <tr><td>Salma Begum</td><td class="r num">৳ 2,400</td><td class="r">12 d</td></tr>
    </tbody><tfoot><tr><td>Total</td><td class="r num">৳ 62,400</td><td></td></tr></tfoot></table>
  </div>
  <div class="card"><div class="ch"><h2>Pending shipments</h2></div>
    <table><thead><tr><th>Invoice</th><th>Customer</th><th>Status</th></tr></thead><tbody>
      <tr><td>R1-000042-0031</td><td>Nasir Store</td><td><span class="chip inf">Packed</span></td></tr>
      <tr><td>R1-000042-0028</td><td>Rina Akter</td><td><span class="chip wa">Ordered</span></td></tr>
      <tr><td>R1-000041-0119</td><td>Jahid Hasan</td><td><span class="chip ok">Shipped</span></td></tr>
    </tbody></table>
  </div>
</div>
</div></div>'''
open('p04-dashboard.html','w').write(page("Dashboard", DASH))

# ---------- 05 Products ----------
PRODS=[
 ("Chinigura Rice 5kg","RICE-CHI-5","Rice & Grain","Pran","508.00","620.00","140","Single","VAT 0%"),
 ("Miniket Rice 25kg","RICE-MIN-25","Rice & Grain","ACI","2,105.00","2,450.00","12","Single","VAT 0%"),
 ("Basmati Rice 1kg","RICE-BAS-1","Rice & Grain","India Gate","268.00","320.00","18","Single","VAT 0%"),
 ("Soyabin Oil 1L","OIL-SOY-1","Oil","Rupchanda","151.50","185.00","300","Single","VAT 0%"),
 ("Soyabin Oil 5L","OIL-SOY-5","Oil","Rupchanda","742.00","890.00","22","Single","VAT 0%"),
 ("T-Shirt Cotton","APP-TSH","Apparel","Local","240.00","420.00","86","Variable · 12","VAT 7.5%"),
 ("Sugar 1kg","SUG-WHT-1","Sugar & Tea","Fresh","112.00","135.00","170","Single","VAT 0%"),
 ("Red Lentil 1kg","PUL-RED-1","Pulses","Teer","134.00","165.00","6","Single","VAT 0%"),
 ("Tea 400g","BEV-TEA-400","Sugar & Tea","Ispahani","198.00","285.00","44","Single","VAT 7.5%"),
]
def prow(p):
    n,s,c,b,cost,sell,stk,t,tax=p
    low=' class="chip bad"' if int(stk.replace(',',''))<10 else ''
    stkcell=f'<span{low}>{stk}</span>' if low else stk
    var = f'<span class="chip inf">{t}</span>' if 'Variable' in t else t
    return (f'<tr><td><b>{n}</b><div style="font-size:11.5px;color:#79839a">{s}</div></td>'
            f'<td>{c}</td><td>{b}</td><td class="r num">৳ {cost}</td><td class="r num">৳ {sell}</td>'
            f'<td class="r num">{stkcell}</td><td>{var}</td><td>{tax}</td>'
            f'<td class="r"><span class="act">Actions ▾</span></td></tr>')

PRODUCTS = f'''{nav(prod=1)}<div class="main">{TOP}<div class="body">
<h1>Products</h1><div class="crumb">2,140 products · 2 locations · WooCommerce is the product master</div>
<div class="card">
<div class="tabs"><span class="on">All products</span><span>Stock report</span><span>Variations</span><span>Low stock (12)</span></div>
<div class="filt"><div class="fh">▾ Filters</div><div class="fg">
  <div><label>Location</label><select><option>All locations</option><option>Main branch</option></select></div>
  <div><label>Category</label><select><option>All categories</option></select></div>
  <div><label>Brand</label><select><option>All brands</option></select></div>
  <div><label>Stock status</label><select><option>Any</option><option>At or below alert</option></select></div>
</div></div>
{TOOLS}
<table>
<thead><tr><th>Product / SKU</th><th>Category</th><th>Brand</th><th class="r">Unit cost</th><th class="r">Selling</th><th class="r">Stock</th><th>Type</th><th>Tax</th><th class="r">Actions</th></tr></thead>
<tbody>{''.join(prow(p) for p in PRODS)}</tbody>
<tfoot><tr><td colspan="5">Stock value at cost</td><td class="r num">৳ 1,284,610</td><td colspan="3"></td></tr></tfoot>
</table>
<div class="note">Showing 1 to 9 of 2,140 · exports enabled for this role</div>
</div>
</div></div>'''
open('p05-products.html','w').write(page("Products", PRODUCTS))
print("p04, p05 written")
