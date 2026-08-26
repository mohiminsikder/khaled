# -*- coding: utf-8 -*-
import gen1
from gen1 import pos_body, page

ACCOUNTS = '<option>Cash Drawer — ৳ 134,080</option><option>bKash — ৳ 41,220</option><option>Brac Bank — ৳ 52,470</option><option>City Bank — ৳ 19,650</option>'

def prow(method, amount, acct, note, cr=False):
    return f'''<div class="prow{' cr' if cr else ''}">
  <div><label>Amount</label><input class="num" value="{amount}"></div>
  <div><label>Payment method</label><select>{method}</select></div>
  <div><label>Payment account</label><select>{acct}</select></div>
</div>'''

M_CASH='<option>Cash</option><option>Card</option><option>bKash</option><option>Nagad</option><option>Rocket</option><option>Bank transfer</option><option>Cheque</option><option>Credit (বাকি)</option>'
M_BK='<option>bKash</option><option>Cash</option><option>Card</option>'
M_CR='<option>Credit (বাকি)</option>'

PAYMENT = f'''<div class="ov"><div class="md">
  <div class="mh"><h2>Take payment</h2>
    <span class="adv">Karim Uddin · outstanding ৳ 4,200.00 · available credit ৳ 5,800.00</span></div>
  <div class="mb">
    <div class="ml">
      {prow(M_CASH,"1,000.00",ACCOUNTS,"")}
      {prow(M_BK,"600.00",ACCOUNTS,"")}
      {prow(M_CR,"399.00","<option>Customer ledger — বাকি</option>","",cr=True)}
      <button class="btn2" style="align-self:flex-start">+ Add payment row</button>
      <div class="prow" style="grid-template-columns:1fr 1fr">
        <div><label>Sell note</label><input value=""></div>
        <div><label>Staff note</label><input value=""></div>
      </div>
    </div>
    <div class="mr">
      <div class="rr"><span>Total items</span><b class="num">7</b></div>
      <div class="rr"><span>Total payable</span><b class="num">৳ 1,999.00</b></div>
      <div class="rr"><span>Total paying</span><b class="num">৳ 1,999.00</b></div>
      <div class="rr"><span>Change return</span><b class="num">৳ 0.00</b></div>
      <div class="rr"><span>Balance</span><b class="num">৳ 0.00</b></div>
      <div class="rr big2"><span>On account after</span><span class="num">৳ 4,599</span></div>
      <div style="font-size:12px;color:#c9b79c">of ৳ 10,000 limit · within available credit</div>
    </div>
  </div>
  <div class="mf"><button class="big multi" style="flex:1">Finalise payment &amp; print · Enter</button>
    <button class="big" style="flex:0 0 140px">Cancel</button></div>
</div></div>'''

open('p02-payment.html','w').write(page("Payment", pos_body(PAYMENT), "pos.css"))

# ---- Register details (X-report) ----
def r(m, sell, exp):
    return f'<tr><td>{m}</td><td class="r num">{sell}</td><td class="r num">{exp}</td></tr>'

XREP = '''<div class="ov"><div class="md" style="width:840px">
  <div class="mh"><h2>Register details — live X-report</h2>
    <span class="adv">R1 · Rehana · opened 26-08-2026 09:04 · still open</span></div>
  <div style="padding:16px 18px">
    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
      <thead><tr>
        <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #2c313d;color:#8a90a2;font-size:11px;letter-spacing:.06em;text-transform:uppercase">Payment method</th>
        <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #2c313d;color:#8a90a2;font-size:11px;letter-spacing:.06em;text-transform:uppercase">Sell</th>
        <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #2c313d;color:#8a90a2;font-size:11px;letter-spacing:.06em;text-transform:uppercase">Expense</th>
      </tr></thead>
      <tbody style="color:#e8eaf0">
''' + "\n".join([
  r("Cash","৳ 48,320.00","৳ 1,200.00"),
  r("Card","৳ 12,450.00","৳ 0.00"),
  r("bKash","৳ 18,900.00","৳ 0.00"),
  r("Nagad","৳ 3,200.00","৳ 0.00"),
  r("Rocket","৳ 950.00","৳ 0.00"),
  r("Bank transfer","৳ 0.00","৳ 0.00"),
  r("Cheque","৳ 0.00","৳ 0.00"),
  r("Credit (বাকি)","৳ 6,400.00","—"),
]) + '''
      </tbody>
    </table>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:16px">
      <div style="background:#232733;border:1px solid #2c313d;border-radius:8px;padding:11px 13px"><div style="font-size:11px;color:#8a90a2;text-transform:uppercase;letter-spacing:.06em">Opening float</div><div style="font-size:19px;font-weight:700" class="num">৳ 2,000.00</div></div>
      <div style="background:#232733;border:1px solid #2c313d;border-radius:8px;padding:11px 13px"><div style="font-size:11px;color:#8a90a2;text-transform:uppercase;letter-spacing:.06em">Total sales</div><div style="font-size:19px;font-weight:700" class="num">৳ 90,220.00</div></div>
      <div style="background:#232733;border:1px solid #2c313d;border-radius:8px;padding:11px 13px"><div style="font-size:11px;color:#8a90a2;text-transform:uppercase;letter-spacing:.06em">Total refund</div><div style="font-size:19px;font-weight:700;color:#e5484d" class="num">৳ 1,850.00</div></div>
      <div style="background:#232733;border:1px solid #2c313d;border-radius:8px;padding:11px 13px"><div style="font-size:11px;color:#8a90a2;text-transform:uppercase;letter-spacing:.06em">Total expense</div><div style="font-size:19px;font-weight:700;color:#e5484d" class="num">৳ 1,200.00</div></div>
    </div>
    <div style="background:#232733;border:1px solid #2c313d;border-radius:8px;padding:13px;margin-top:12px">
      <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700"><span>Cash expected in drawer</span><span class="num">৳ 47,270.00</span></div>
      <div style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#8a90a2;margin-top:6px">
        opening 2,000.00 + cash sale 48,320.00 − cash refund 1,850.00 − cash expense 1,200.00 = 47,270.00
      </div>
    </div>
    <div style="margin-top:16px;font-size:11px;color:#8a90a2;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Details of products sold — 147 transactions</div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:7px;color:#e8eaf0">
      <thead><tr><th style="text-align:left;padding:7px 10px;color:#8a90a2;font-size:11px;border-bottom:1px solid #2c313d">SKU</th><th style="text-align:left;padding:7px 10px;color:#8a90a2;font-size:11px;border-bottom:1px solid #2c313d">Product</th><th style="text-align:right;padding:7px 10px;color:#8a90a2;font-size:11px;border-bottom:1px solid #2c313d">Qty</th><th style="text-align:right;padding:7px 10px;color:#8a90a2;font-size:11px;border-bottom:1px solid #2c313d">Value</th></tr></thead>
      <tbody>
        <tr><td style="padding:7px 10px">RICE-CHI-5</td><td style="padding:7px 10px">Chinigura Rice 5kg</td><td style="padding:7px 10px;text-align:right">48</td><td style="padding:7px 10px;text-align:right" class="num">৳ 29,760.00</td></tr>
        <tr><td style="padding:7px 10px">OIL-SOY-1</td><td style="padding:7px 10px">Soyabin Oil 1L</td><td style="padding:7px 10px;text-align:right">61</td><td style="padding:7px 10px;text-align:right" class="num">৳ 11,285.00</td></tr>
        <tr><td style="padding:7px 10px">SUG-WHT-1</td><td style="padding:7px 10px">Sugar 1kg</td><td style="padding:7px 10px;text-align:right">44</td><td style="padding:7px 10px;text-align:right" class="num">৳ 5,940.00</td></tr>
        <tr><td style="padding:7px 10px">BEV-TEA-400</td><td style="padding:7px 10px">Tea 400g</td><td style="padding:7px 10px;text-align:right">19</td><td style="padding:7px 10px;text-align:right" class="num">৳ 5,415.00</td></tr>
      </tbody>
    </table>
  </div>
  <div class="mf"><button class="big multi" style="flex:1">Print X-report</button><button class="big" style="flex:0 0 200px">Close register (Z-out)</button><button class="big" style="flex:0 0 120px">Close</button></div>
</div></div>'''

open('p03-register.html','w').write(page("Register details", pos_body(XREP), "pos.css"))
print("p02, p03 written")
