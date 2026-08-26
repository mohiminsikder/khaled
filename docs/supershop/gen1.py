# -*- coding: utf-8 -*-
STRIP = '''<div class="strip">
  <select class="sel"><option>Main branch — Mirpur 10</option><option>Branch 2 — Kazipara</option></select>
  <span class="date">Business date 26-08-2026 · Register R1 · Shift open 09:04</span>
  <div class="icons">
    <div class="ib" title="Go back">⏪</div>
    <div class="ib k" title="Close register (Z-out)">❎</div>
    <div class="ib" title="Register details (X-report)">💼</div>
    <div class="ib" title="Calculator">🖩</div>
    <div class="ib" title="Sell return">↶</div>
    <div class="ib" title="Full screen">⛶</div>
    <div class="ib" title="Suspended sales">⏸</div>
    <div class="ib" title="Recent transactions">🧾</div>
  </div>
  <button class="exp">⊖ Add expense</button>
</div>'''

TILES = [
 ("Chinigura Rice 5kg","RICE-CHI-5","620","40 Piece",""),
 ("Miniket Rice 25kg","RICE-MIN-25","2,450","12 Bag",""),
 ("Basmati Rice 1kg","RICE-BAS-1","320","18 Piece",""),
 ("Soyabin Oil 1L","OIL-SOY-1","185","60 Bottle",""),
 ("Soyabin Oil 5L","OIL-SOY-5","890","22 Bottle",""),
 ("Mustard Oil 1L","OIL-MUS-1","320","14 Bottle",""),
 ("Atta 2kg","FLR-ATT-2","145","25 Packet",""),
 ("Maida 1kg","FLR-MAI-1","85","31 Packet",""),
 ("Sugar 1kg","SUG-WHT-1","135","50 Packet",""),
 ("Red Lentil 1kg","PUL-RED-1","165","6 Packet","lo"),
 ("Chickpea 1kg","PUL-CHK-1","155","30 Packet",""),
 ("Tea 400g","BEV-TEA-400","285","44 Packet",""),
 ("Powder Milk 500g","DRY-MLK-500","480","17 Tin",""),
 ("Turmeric 200g","SPC-TUR-200","95","4 Packet","lo"),
]
def tiles():
    out=[]
    for n,s,p,q,lo in TILES:
        out.append(f'<div class="tile"><div class="n">{n}</div><div class="s">{s}</div>'
                   f'<div class="p num">৳ {p}</div><div class="q {lo}">{q}</div></div>')
    return "\n".join(out)

CART_ROWS = [
 ("Chinigura Rice 5kg","RICE-CHI-5","2","Piece","1,240.00","64 Piece in stock","",""),
 ("Soyabin Oil 1L","OIL-SOY-1","1","Bottle","185.00","60 Bottle in stock","",""),
 ("Sugar 1kg","SUG-WHT-1","2","Packet","250.00","50 Packet in stock","","Discount −৳ 20.00 (fixed)"),
 ("Tea 400g","BEV-TEA-400","1","Packet","285.00","44 Packet in stock","",""),
 ("Red Lentil 1kg","PUL-RED-1","1","Packet","165.00","6 Packet in stock","lo",""),
]
def rows():
    out=[]
    for n,s,q,u,sub,stk,lo,dsc in CART_ROWS:
        d=f'<div class="dsc">{dsc}</div>' if dsc else ''
        out.append(f'''<div class="row">
  <div><div class="nm">{n}</div><div class="sku">{s} ⓘ</div><div class="stk {lo}">{stk}</div>{d}</div>
  <div class="stp"><b>−</b><input value="{q}"><b>+</b></div>
  <div><select class="unit"><option>{u}</option></select></div>
  <div><input class="sub num" value="{sub}"></div>
  <div class="x">×</div>
</div>''')
    return "\n".join(out)

TOTALS = '''<div class="tot">
  <div class="tl"><span>Items <b>7</b></span><span>Total <b class="num">৳ 2,125.00</b></span>
    <span>Price group <b>Retail</b></span></div>
  <div class="tl2">
    <span>Discount / Reward (−) <b>৳ 126.25</b> <i class="pen">✎</i></span>
    <span>Order tax (+) <b>VAT 0.00</b> <i class="pen">✎</i></span>
    <span>Shipping (+) <b>৳ 0.00</b> <i class="pen">✎</i></span>
    <span>Round off <b>৳ 0.25</b></span>
  </div>
</div>'''

BAR = '''<div class="bar">
  <div class="sec"><i>📄</i><span>Draft</span></div>
  <div class="sec"><i>📋</i><span>Quotation</span></div>
  <div class="sec"><i>⏸</i><span>Suspend</span></div>
  <div class="sec"><i>🧾</i><span>Recent</span></div>
  <div class="sec"><i>✖</i><span>Cancel</span></div>
  <button class="big card">Card</button>
  <button class="big">Credit sale (বাকি)</button>
  <button class="big multi">Multiple pay · Enter</button>
  <button class="big cash">Cash</button>
  <div class="payable"><div class="l">Total payable</div><div class="v num">৳ 1,999.00</div></div>
</div>'''

CUSTOMER = '''<div class="pk">
  <div class="fld"><label>Customer</label>
    <div class="box cust"><span>Karim Uddin · 01711-223344</span>
      <span class="cr">Available ৳ 5,800 of ৳ 10,000</span></div></div>
  <div class="fld"><label>Price group</label>
    <div class="box"><span>Retail</span><span style="color:#8a90a2">▾</span></div></div>
</div>'''

SEARCH = '''<div class="srch"><input placeholder="Scan barcode, or type product name / SKU" value=""><div class="mag">🔍</div></div>'''

def pos_body(modal=""):
    return f'''{STRIP}
<div class="wrap">
  <div class="left">
    {CUSTOMER}
    {SEARCH}
    <div class="cart">
      <div class="hd"><span>Product</span><span>Quantity</span><span>Unit</span><span>Subtotal</span><span></span></div>
      <div class="rows">{rows()}</div>
      {TOTALS}
    </div>
  </div>
  <div class="gridp">
    <div class="cats"><span class="cat on">All</span><span class="cat">Rice</span><span class="cat">Oil</span><span class="cat">Flour</span><span class="cat">Pulses</span><span class="cat">Spices</span><span class="cat">Dairy</span></div>
    <div class="tiles">{tiles()}</div>
  </div>
</div>
{BAR}
{modal}'''

def page(title, body, css):
    return (f'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{title}</title>'
            f'<link rel="stylesheet" href="{css}"></head><body>{body}</body></html>')

open('p01-pos.html','w').write(page("POS terminal", pos_body(), "pos.css"))
print("p01 written")
