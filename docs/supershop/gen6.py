# -*- coding: utf-8 -*-
from _nav import nav, TOP, page
CK='<input type="checkbox" checked style="width:15px;height:15px;accent-color:#2f6ce5">'
UN='<input type="checkbox" style="width:15px;height:15px">'
def prow(cap, c, s, m, note=""):
    n=f'<div style="font-size:11.5px;color:#79839a;margin-top:2px">{note}</div>' if note else ''
    return (f'<tr><td>{cap}{n}</td><td class="c">{CK if c else UN}</td>'
            f'<td class="c">{CK if s else UN}</td><td class="c">{CK if m else UN}</td></tr>')

def grp(title, rows):
    return (f'<div class="card"><div class="ch"><h2>{title}</h2><span class="act">Select all</span></div>'
            f'<table><thead><tr><th>Capability</th><th class="c">Cashier</th><th class="c">Supervisor</th>'
            f'<th class="c">Manager</th></tr></thead><tbody>{rows}</tbody></table></div>')

ROLES = f'''{nav(rol=1)}<div class="main">{TOP}<div class="body">
<h1>Roles &amp; permissions</h1><div class="crumb">5 roles · 41 capabilities in 9 groups · Administrator is locked from edit</div>
<div class="card"><div class="cb" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
<span class="chip inf">Counter Cashier</span><span class="chip mut">Counter Supervisor</span>
<span class="chip mut">Counter Stockkeeper</span><span class="chip mut">Counter Manager</span>
<span class="chip mut">Counter Terminal (device only)</span>
<div style="flex:1"></div><button class="btn2">+ Add role</button><button class="btn">Save changes</button>
</div></div>
<div style="padding:11px 14px;background:#fbf2e0;border:1px solid #e8d3a0;border-radius:8px;font-size:13px;color:#9a6208;margin-bottom:16px">
<b>Two defaults are worth a decision before you save.</b> Selling on account (বাকি) is the feature the till was built for — a cashier who cannot use it cannot bill a regular customer. And refunds ship enabled for cashiers, which most shops restrict to a supervisor.
</div>
<div class="two">
{grp("Till — selling", 
  prow("Use POS terminal",1,1,1)+
  prow("Open shift / register",1,1,1)+
  prow("Close shift / register",0,1,1,"Closing the till is its own privilege, independent of selling")+
  prow("Hold / suspend a sale",1,1,1)+
  prow("Sell on account (বাকি)",1,1,1,"Decide this deliberately — it extends the shop's credit")+
  prow("Authorise refund",0,1,1,"Restricted to supervisor by default")+
  prow("No-sale / open drawer",0,1,1)+
  prow("Void line",0,1,1))}
{grp("Till — pricing",
  prow("Line discount",0,1,1)+
  prow("Order-level discount",0,1,1)+
  prow("Price override",0,0,1)+
  prow("Quick-add product at till",0,1,1,"Requires a selling price — never inherits a default margin")+
  prow("Change price group",0,1,1)+
  prow("Redeem reward points",1,1,1)+
  prow("Edit order tax",0,1,1)+
  prow("Add expense at till",0,1,1))}
</div>
<div class="two">
{grp("Visibility",
  prow("View cost price",0,0,1)+
  prow("View margin",0,0,1)+
  prow("View own sales",1,1,1)+
  prow("View all sales",0,1,1)+
  prow("View all reports",0,0,1)+
  prow("Export tables (CSV / Excel / PDF)",0,1,1,"Enabled by default for supervisor and above")+
  prow("View audit log",0,0,1))}
{grp("Stock &amp; purchasing",
  prow("Adjust stock",0,1,1)+
  prow("Transfer stock",0,1,1)+
  prow("Run stocktake",0,1,1)+
  prow("Manage purchasing",0,0,1)+
  prow("Pay supplier",0,0,1)+
  prow("Print labels",0,1,1)+
  prow("Manage settings",0,0,0,"Administrator only"))}
</div>
</div></div>'''
open('p10-roles.html','w').write(page("Roles & permissions", ROLES))

# ---------- 11 Print labels ----------
def fld(name, on, pt):
    ck=CK if on else UN
    return (f'<tr><td>{ck} &nbsp; {name}</td><td class="r">'
            f'<select style="padding:4px 7px;border:1px solid #e2e6ec;border-radius:5px"><option>{pt} pt</option></select></td></tr>')

def lbl(n,sku,price,bc):
    return f'''<div style="border:1px dashed #c9ced8;border-radius:5px;padding:8px 9px;background:#fff;display:flex;flex-direction:column;gap:3px">
<div style="font-size:10.5px;font-weight:700;line-height:1.2">{n}</div>
<div style="font-size:8.5px;color:#79839a">Peapip General Store · 26-08-2026</div>
<div style="font-family:ui-monospace,monospace;font-size:7px;letter-spacing:-.5px;line-height:1;color:#151922">{bc}</div>
<div style="font-size:8px;color:#79839a">{sku}</div>
<div style="font-size:12px;font-weight:800">৳ {price}</div></div>'''

BARS='▌│▌▌│▌│││▌│▌▌│││▌│▌│▌▌│▌│││▌▌│▌│▌│▌▌│││▌│▌▌│▌'

LABELS = f'''{nav(lab=1)}<div class="main">{TOP}<div class="body">
<h1>Print labels</h1><div class="crumb">Sheet or roll · barcode generated from SKU · EAN-13, Code 128, Code 39, UPC-A</div>
<div class="two" style="grid-template-columns:1fr 1.1fr">
<div>
<div class="card"><div class="ch"><h2>Products in this batch</h2><button class="btn3">+ Add from PO-00187</button></div>
<table><thead><tr><th>Product</th><th class="r">Labels</th><th class="r">Price</th></tr></thead><tbody>
<tr><td>Chinigura Rice 5kg<div style="font-size:11.5px;color:#79839a">RICE-CHI-5</div></td><td class="r"><input value="100" style="width:56px;text-align:right;padding:5px;border:1px solid #e2e6ec;border-radius:5px"></td><td class="r num">৳ 620.00</td></tr>
<tr><td>Soyabin Oil 1L<div style="font-size:11.5px;color:#79839a">OIL-SOY-1</div></td><td class="r"><input value="240" style="width:56px;text-align:right;padding:5px;border:1px solid #e2e6ec;border-radius:5px"></td><td class="r num">৳ 185.00</td></tr>
<tr><td>Sugar 1kg<div style="font-size:11.5px;color:#79839a">SUG-WHT-1</div></td><td class="r"><input value="120" style="width:56px;text-align:right;padding:5px;border:1px solid #e2e6ec;border-radius:5px"></td><td class="r num">৳ 135.00</td></tr>
<tr><td>Powder Milk 500g<div style="font-size:11.5px;color:#79839a">DRY-MLK-500</div></td><td class="r"><input value="60" style="width:56px;text-align:right;padding:5px;border:1px solid #e2e6ec;border-radius:5px"></td><td class="r num">৳ 480.00</td></tr>
</tbody><tfoot><tr><td>Total</td><td class="r num">520</td><td></td></tr></tfoot></table></div>
<div class="card"><div class="ch"><h2>Fields on the label</h2></div>
<table><tbody>
{fld("Product name",1,10)}{fld("Variation",0,8)}{fld("Selling price",1,12)}
{fld("Business name",1,8)}{fld("Packing date",1,8)}{fld("Batch / lot number",1,7)}{fld("Expiry date",1,8)}
</tbody></table>
<div class="cb" style="display:grid;grid-template-columns:1fr 1fr;gap:11px;border-top:1px solid #eef1f5">
<div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Price group</label><select style="width:100%;padding:7px;border:1px solid #e2e6ec;border-radius:5px"><option>Retail</option><option>Wholesale</option></select></div>
<div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Tax</label><select style="width:100%;padding:7px;border:1px solid #e2e6ec;border-radius:5px"><option>Inclusive of VAT</option><option>Exclusive</option></select></div>
<div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Barcode type</label><select style="width:100%;padding:7px;border:1px solid #e2e6ec;border-radius:5px"><option>Code 128</option><option>EAN-13</option></select></div>
<div><label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#79839a;font-weight:600;display:block;margin-bottom:4px">Sheet</label><select style="width:100%;padding:7px;border:1px solid #e2e6ec;border-radius:5px"><option>38 × 25 mm · 40 per A4</option><option>50 × 30 mm · 24 per A4</option></select></div>
</div></div>
</div>
<div class="card"><div class="ch"><h2>Live preview — 38 × 25 mm, 40 per A4</h2><div><button class="btn2">Preview PDF</button> <button class="btn">Print 520 labels</button></div></div>
<div class="cb" style="background:#eef1f5">
<div style="background:#fff;border:1px solid #d5dae2;border-radius:5px;padding:11px;display:grid;grid-template-columns:repeat(4,1fr);gap:6px">
{"".join(lbl("Chinigura Rice 5kg","RICE-CHI-5","620.00",BARS) for _ in range(8))}
{"".join(lbl("Soyabin Oil 1L","OIL-SOY-1","185.00",BARS) for _ in range(8))}
{"".join(lbl("Sugar 1kg","SUG-WHT-1","135.00",BARS) for _ in range(4))}
</div>
<div class="note" style="margin:11px -15px -13px;background:#fff">The preview redraws as fields and point sizes change — what prints is what is shown here.</div>
</div></div>
</div>
</div></div>'''
open('p11-labels.html','w').write(page("Print labels", LABELS))
print("p10, p11 written")
